# Upstream 1.0 Pre-Release Findings (`symfony/ai` & `mcp/sdk`)

> Hackathon call to action: *"Break it before 1.0.0 ships... what gets reported now is what 1.0.0 ships without."*

While building **Faultline MCP** against `mcp/sdk` `v0.10.0`, `symfony/ai-agent` `v1.0.0-BETA1`, and `symfony/ai-mcp-tool` (`b14b03b`), deterministic fault injection uncovered **four reproducible reliability hazards** in the 1.0 pre-release stack. Every finding below is reproduced by `vendor/bin/phpunit` and `php bin/console faultline:duel`.

---

## Finding 1 — `mcp/sdk` `HttpTransport::handleErrorStatus()` drops `Retry-After` and maps HTTP `429`/`502`/`503` to JSON-RPC `-32600` (`Invalid Request`)

- **Package & Version:** `mcp/sdk` `v0.10.0` (`5701e69`)
- **Location:** `src/Client/Transport/HttpTransport.php:465-486`
- **Mechanism:**
  When an upstream MCP HTTP gateway answers with a non-JSON `HTTP 429 Too Many Requests` (carrying `Retry-After: 1`) or `HTTP 502 Bad Gateway`, `HttpTransport::send()` reads the body and calls `handleErrorStatus($data, $status, $reason, $body, $truncated)` without passing `$response->getHeaders()`:
  ```php
  $error = \is_array($answer['error'] ?? null) && \is_int($answer['error']['code'] ?? null)
      ? new Error($requestId, $answer['error']['code'], ...)
      : Error::forInvalidRequest(\sprintf('Server answered with HTTP %d%s.', $status, ...), $requestId);
  ```
- **Impact:**
  1. `Retry-After` backoff metadata is discarded before reaching `Client` or Symfony AI callers, causing immediate `0ms` retry storms into rate-limited gateways.
  2. Mapping server-side `429`/`502`/`503` into `-32600` (`Error::INVALID_REQUEST`) misclassifies transient upstream/gateway faults as malformed client requests.
- **Suggested Upstream Fix:** Preserve `httpStatus` and `retryAfter` inside `Error::$data` (`['httpStatus' => $status, 'retryAfter' => $retryAfter]`) and map `5xx`/`429` to `Error::INTERNAL_ERROR` (`-32603`) or a dedicated transport error code rather than `INVALID_REQUEST`.
- **Faultline Reproduction:** `php bin/console faultline:duel --scenario=http_429_storm --seed=20261123`

---

## Finding 2 — `symfony/ai-mcp-tool` `ClientToolset::getTools()` spins in an unbounded loop and duplicates tools on cyclic/empty `nextCursor`

- **Package & Version:** `symfony/ai-mcp-tool` `dev-main` (`b14b03b`)
- **Location:** `ClientToolset.php:46-64`
- **Mechanism:**
  ```php
  $tools = [];
  $cursor = null;

  do {
      $page = $this->client->listTools($cursor);
      foreach ($page->tools as $tool) {
          $tools[] = $tool;
      }
      $cursor = $page->nextCursor;
  } while (null !== $cursor);
  ```
- **Impact:**
  1. An upstream MCP server returning a repeated cursor (`"c1" -> "c1"`), a 2-cycle (`"c1" -> "c2" -> "c1"`), or an empty string `""` (`"" !== null`) traps `ClientToolset::getTools()` in an unbounded RPC storm until memory or `max_execution_time` exhaustion.
  2. Appending `$tools[] = $tool` without deduplicating `$tool->name` inflates the LLM's tool definitions with duplicate entries when pages overlap.
- **Suggested Upstream Fix:** Track `$seenCursors = []`, treat `'' === trim($cursor)` or `isset($seenCursors[$cursor])` as terminal (or throw `ToolCallException`), enforce a configurable `$maxPages` ceiling, and key `$tools[$tool->name] = $tool`.
- **Faultline Reproduction:** `php bin/console faultline:duel --scenario=cursor_tamper --seed=20261123` (`FaultlineGuardedToolset` demonstrates the exact fix).

---

## Finding 3 — `symfony/ai-mcp-tool` `McpToolbox` memoizes `$toolsMetadata` without schema-drift detection or `list_changed` invalidation

- **Package & Version:** `symfony/ai-mcp-tool` `dev-main` (`b14b03b`)
- **Location:** `McpToolbox.php:48-95`
- **Mechanism:** `getTools()` caches `$this->toolsMetadata` permanently until `reset()` is manually called, without subscribing to `ToolListChangedNotification` or pinning canonical `inputSchema` hashes before mutating `callTool()` executions.
- **Impact:** In long-lived workers (FrankenPHP, RoadRunner, Messenger consumers, CLI agents), mid-session upstream schema changes (such as unit-scale shifts from `cents` to `major units` or tightened argument contracts) cause silent semantic mis-execution.
- **Faultline Reproduction:** `php bin/console faultline:duel --scenario=schema_drift --seed=20261123`

---

## Finding 4 — `symfony/ai-agent` `ToolExecutionException::getToolCallResult()` masks ambiguous post-commit failures behind a generic message

- **Package & Version:** `symfony/ai-agent` `v1.0.0-BETA1` (`eb39f8c`)
- **Location:** `src/Toolbox/Exception/ToolExecutionException.php:27-30` & `src/Toolbox/FaultTolerantToolbox.php:35-38`
- **Mechanism:**
  When `McpToolbox` throws `ToolCallException` after a truncated JSON-RPC response or post-commit `HTTP 502`, `AbstractToolbox` wraps it in `ToolExecutionException`, and `FaultTolerantToolbox` feeds the model:
  ```php
  return \sprintf('An error occurred while executing tool "%s".', $this->toolCall->getName());
  ```
- **Impact:** Because all diagnostic context (timeout/truncation vs pre-flight validation error, plus `ToolAnnotations::destructiveHint` / `idempotentHint`) is stripped, the LLM assumes nothing executed and immediately re-invokes non-idempotent tools (`issue_refund`, `capture_payment`), triggering real double-spends.
- **Suggested Upstream Fix:** Surface transport ambiguity metadata for tools marked `destructiveHint: true, idempotentHint: false` so agents verify state with read-only tools (`get_order`) or attach deterministic idempotency keys before retrying.
- **Faultline Reproduction:** `php bin/console faultline:duel --scenario=post_commit_truncation --seed=20261123`
