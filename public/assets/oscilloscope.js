(() => {
  'use strict';

  const state = {
    activeScenario: 'post_commit_truncation',
    seed: 20261123,
    duel: null,
    selectedTrack: 'unguarded',
    selectedSeq: null,
    mcpAppInitialized: false,
  };

  const SEEDS_CYCLE = [20261123, 31415926, 27182818, 99402026, 42424242];

  // Official MCP Apps (io.modelcontextprotocol/ui) postMessage handshake when hosted inside an MCP iframe
  function initMcpAppsBridge() {
    if (window.parent === window) {
      return;
    }

    window.addEventListener('message', (event) => {
      const msg = event.data;
      if (!msg || typeof msg !== 'object' || msg.jsonrpc !== '2.0') {
        return;
      }
      if (msg.id === 'flt_ui_init_1' && msg.result && !state.mcpAppInitialized) {
        state.mcpAppInitialized = true;
        window.parent.postMessage({
          jsonrpc: '2.0',
          method: 'ui/notifications/initialized',
          params: {},
        }, '*');
        notifyHostSize();
      }
    });

    window.parent.postMessage({
      jsonrpc: '2.0',
      id: 'flt_ui_init_1',
      method: 'ui/initialize',
      params: {
        protocolVersion: '2026-07-28',
        appInfo: { name: 'Faultline MCP Oscilloscope', version: '1.0.0' },
        appCapabilities: {},
      },
    }, '*');

    if (typeof ResizeObserver !== 'undefined') {
      const ro = new ResizeObserver(() => notifyHostSize());
      ro.observe(document.body);
    }
  }

  function notifyHostSize() {
    if (window.parent === window || !state.mcpAppInitialized) {
      return;
    }
    const rect = document.documentElement.getBoundingClientRect();
    window.parent.postMessage({
      jsonrpc: '2.0',
      method: 'ui/notifications/size-changed',
      params: {
        width: Math.ceil(rect.width),
        height: Math.ceil(document.documentElement.scrollHeight),
      },
    }, '*');
  }

  function formatEur(cents, signed = true) {
    const val = (Number(cents || 0) / 100).toFixed(2);
    if (!signed) {
      return `${val} EUR`;
    }
    return Number(cents) > 0 ? `+${val} EUR` : `${val} EUR`;
  }

  function formatJsonPretty(raw) {
    if (!raw || typeof raw !== 'string') {
      return '';
    }
    try {
      return JSON.stringify(JSON.parse(raw), null, 2);
    } catch {
      return `${raw}\n\n[WIRE FRAME TRUNCATED — INVALID / UNCLOSED JSON-RPC PAYLOAD]`;
    }
  }

  function escapeSvgText(text) {
    return String(text)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function classifyFrame(frame, trackEval, mutatingIndex) {
    if (frame.guard_action) {
      return { kind: 'guard', color: '#10B981', code: frame.guard_action };
    }
    if (frame.fault_injected) {
      return { kind: 'fault', color: '#F59E0B', code: frame.fault_injected };
    }
    if (
      frame.rpc_method === 'tools/call' &&
      frame.tool_name &&
      frame.tool_name !== 'get_order' &&
      mutatingIndex > 1 &&
      !trackEval.integrity_passed
    ) {
      return { kind: 'leak', color: '#EF4444', code: 'DUPLICATE_SIDE_EFFECT_WRITE' };
    }
    return { kind: 'ok', color: '#94A3B8', code: 'NOMINAL' };
  }

  function renderOscilloscopeSvg(duel) {
    const svg = document.getElementById('oscilloscope-svg');
    if (!svg || !duel) {
      return;
    }

    const width = 1160;
    const height = 286;
    const leftPad = 152;
    const rightPad = 36;
    const usableW = width - leftPad - rightPad;

    const uFrames = duel.unguarded.frames || [];
    const gFrames = duel.guarded.frames || [];
    const maxCols = Math.max(uFrames.length, gFrames.length, 4);

    let markup = '';

    // Oscilloscope graticule grid
    for (let x = leftPad; x <= width - rightPad; x += Math.max(64, Math.floor(usableW / maxCols))) {
      markup += `<line x1="${x}" y1="14" x2="${x}" y2="${height - 16}" stroke="#1E293B" stroke-width="1"/>`;
    }
    markup += `<line x1="16" y1="142" x2="${width - 16}" y2="142" stroke="#334155" stroke-width="1" stroke-dasharray="4 4"/>`;

    const tracks = [
      { key: 'unguarded', label: 'CH1 · UNGUARDED', sub: `Score ${duel.unguarded.resilience_score}/100`, baseY: 96, frames: uFrames, eval: duel.unguarded.evaluation },
      { key: 'guarded', label: 'CH2 · GUARDED', sub: `Score ${duel.guarded.resilience_score}/100`, baseY: 226, frames: gFrames, eval: duel.guarded.evaluation },
    ];

    for (const tr of tracks) {
      // Track label header on left margin
      markup += `<text x="18" y="${tr.baseY - 34}" fill="#E2E8F0" font-family="IBM Plex Mono, monospace" font-size="11.5" font-weight="600">${escapeSvgText(tr.label)}</text>`;
      markup += `<text x="18" y="${tr.baseY - 17}" fill="#94A3B8" font-family="IBM Plex Mono, monospace" font-size="10.5">${escapeSvgText(tr.sub)}</text>`;

      // Baseline rail
      markup += `<line x1="${leftPad}" y1="${tr.baseY}" x2="${width - rightPad}" y2="${tr.baseY}" stroke="#334155" stroke-width="1.4"/>`;

      let pathD = `M ${leftPad} ${tr.baseY}`;
      let mutCount = 0;
      const stepX = tr.frames.length > 1 ? usableW / (tr.frames.length - 1) : usableW / 2;

      const nodesMarkup = [];
      tr.frames.forEach((frame, idx) => {
        if (frame.rpc_method === 'tools/call' && frame.tool_name && frame.tool_name !== 'get_order') {
          if (!frame.fault_injected || frame.fault_injected.startsWith('POST_COMMIT') || frame.fault_injected.startsWith('SCHEMA_DRIFT')) {
            mutCount += 1;
          }
        }

        const cls = classifyFrame(frame, tr.eval, mutCount);
        const cx = Math.round(leftPad + (tr.frames.length === 1 ? usableW * 0.35 : idx * stepX));
        const pulseAmp = Math.min(54, Math.max(14, Math.round(12 + Number(frame.latency_ms || 8) * 0.26)));
        const peakY = tr.baseY - pulseAmp;

        // Square-wave digital logic analyzer pulse
        const halfW = Math.min(18, Math.max(9, Math.floor(stepX * 0.22)));
        pathD += ` L ${cx - halfW} ${tr.baseY} L ${cx - halfW} ${peakY} L ${cx + halfW} ${peakY} L ${cx + halfW} ${tr.baseY}`;

        const isSelected = state.selectedTrack === tr.key && state.selectedSeq === frame.seq;
        const shortLabel = frame.tool_name
          ? frame.tool_name.replace('issue_refund', 'refund').replace('capture_payment', 'capture').replace('open_faultline_oscilloscope', 'ui_app')
          : frame.rpc_method.replace('notifications/', '').replace('guard/', '');

        const tagBadge = cls.kind !== 'ok'
          ? cls.code
              .replace('POST_COMMIT_TRUNCATED_JSON', 'TRUNC_42B')
              .replace('HTTP_429_RETRY_AFTER', 'HTTP_429')
              .replace('POST_COMMIT_HTTP_502_DROP', '502_DROP')
              .replace('SAFE_IDEMPOTENT_REPLAY', 'SAFE_REPLAY')
              .replace('SEALED_COMMITTED_INTENT', 'SEALED')
              .replace('SCHEMA_DRIFT_CAUGHT_AND_PINNED', 'DRIFT_GUARD')
              .replace('SCHEMA_DRIFT_100X_APPLIED', '100X_DRIFT')
              .replace('SCHEMA_DRIFT_PUBLISHED', 'DRIFT_PUB')
              .replace('CURSOR_CYCLE_POISON', 'CYCLE_LOOP')
              .replace('CURSOR_CYCLE_BROKEN', 'CYCLE_BREAK')
              .replace('DUPLICATE_SIDE_EFFECT_WRITE', '2X_LEAK')
              .replace('RETRY_AFTER_RECOVERED', 'BACKOFF_OK')
          : '';

        nodesMarkup.push(`
          <g class="scope-node" data-track="${tr.key}" data-seq="${frame.seq}">
            ${isSelected ? `<rect x="${cx - halfW - 5}" y="${peakY - 22}" width="${(halfW + 5) * 2}" height="${pulseAmp + 42}" rx="3" fill="rgba(245, 158, 11, 0.14)" stroke="#F59E0B" stroke-width="1.2"/>` : ''}
            <rect x="${cx - halfW}" y="${peakY}" width="${halfW * 2}" height="${pulseAmp}" fill="${cls.color}" fill-opacity="0.22"/>
            <circle cx="${cx}" cy="${peakY}" r="${isSelected ? 5.5 : 4}" fill="${cls.color}" stroke="#0F172A" stroke-width="1.5"/>
            ${tagBadge ? `<text x="${cx}" y="${peakY - 8}" text-anchor="middle" fill="${cls.color}" font-family="IBM Plex Mono, monospace" font-size="9.5" font-weight="600">${escapeSvgText(tagBadge)}</text>` : ''}
            <text x="${cx}" y="${tr.baseY + 15}" text-anchor="middle" fill="#CBD5E1" font-family="IBM Plex Mono, monospace" font-size="9.5">${escapeSvgText(shortLabel)}</text>
          </g>
        `);
      });

      pathD += ` L ${width - rightPad} ${tr.baseY}`;
      const strokeColor = tr.key === 'guarded' ? '#34D399' : '#F59E0B';
      markup += `<path d="${pathD}" fill="none" stroke="${strokeColor}" stroke-width="1.75"/>`;
      markup += nodesMarkup.join('');
    }

    svg.innerHTML = markup;

    svg.querySelectorAll('.scope-node').forEach((node) => {
      node.addEventListener('click', () => {
        const track = node.getAttribute('data-track') || 'unguarded';
        const seq = Number(node.getAttribute('data-seq'));
        selectFrame(track, seq);
      });
    });
  }

  function renderInspectorAndLedger(duel) {
    if (!duel) {
      return;
    }

    const scen = duel.scenario;
    const uEval = duel.unguarded.evaluation;
    const gEval = duel.guarded.evaluation;

    document.getElementById('active-category-tag').textContent = scen.category;
    document.getElementById('scope-heading').textContent = scen.title;
    document.getElementById('scope-summary').textContent = scen.summary;

    document.getElementById('metric-unguarded-verdict').textContent = uEval.verdict;
    document.getElementById('metric-unguarded-sub').textContent =
      `Score ${duel.unguarded.resilience_score}/100 · ${formatEur(uEval.actual_delta_cents)} (${uEval.write_count} writes)`;

    document.getElementById('metric-guarded-verdict').textContent = gEval.verdict;
    document.getElementById('metric-guarded-sub').textContent =
      `Score ${duel.guarded.resilience_score}/100 · ${formatEur(gEval.actual_delta_cents)} (${gEval.write_count} write)`;

    document.getElementById('metric-capital-saved').textContent =
      Number(duel.comparison.capital_protected_cents) > 0
        ? `EUR ${duel.comparison.capital_protected_eur}`
        : `${duel.unguarded.exposed_tool_count - duel.guarded.exposed_tool_count} Poisoned Tools Blocked`;

    const totalWall = (Number(duel.unguarded.wall_ms || 0) + Number(duel.guarded.wall_ms || 0)).toFixed(1);
    document.getElementById('metric-timing-sub').textContent = `Seed #${duel.seed} · Replay ${totalWall} ms`;

    document.getElementById('target-order-badge').textContent = scen.target_order_id;

    // Sync track filter buttons
    document.querySelectorAll('[data-track-filter]').forEach((btn) => {
      btn.classList.toggle('is-active', btn.getAttribute('data-track-filter') === state.selectedTrack);
    });

    const activeTrackObj = state.selectedTrack === 'guarded' ? duel.guarded : duel.unguarded;
    const frames = activeTrackObj.frames || [];
    const tbody = document.getElementById('frame-table-body');
    let mutIdx = 0;

    tbody.innerHTML = frames.map((f) => {
      if (f.rpc_method === 'tools/call' && f.tool_name && f.tool_name !== 'get_order') {
        if (!f.fault_injected || f.fault_injected.startsWith('POST_COMMIT') || f.fault_injected.startsWith('SCHEMA_DRIFT')) {
          mutIdx += 1;
        }
      }
      const cls = classifyFrame(f, activeTrackObj.evaluation, mutIdx);
      const badgeClass =
        cls.kind === 'guard' ? 'badge-code--guard'
          : cls.kind === 'fault' ? 'badge-code--fault'
            : cls.kind === 'leak' ? 'badge-code--danger'
              : 'badge-code--nominal';

      const methodCol = f.tool_name ? `${f.rpc_method} (${f.tool_name})` : f.rpc_method;
      const selectedCls = f.seq === state.selectedSeq ? 'is-selected' : '';

      return `
        <tr class="${selectedCls}" data-row-seq="${f.seq}">
          <td>#${f.seq}</td>
          <td>${escapeSvgText(methodCol)}</td>
          <td>${f.http_status}</td>
          <td>${Number(f.latency_ms).toFixed(1)} ms</td>
          <td><span class="badge-code ${badgeClass}">${escapeSvgText(cls.code)}</span></td>
        </tr>
      `;
    }).join('');

    tbody.querySelectorAll('tr[data-row-seq]').forEach((row) => {
      row.addEventListener('click', () => {
        selectFrame(state.selectedTrack, Number(row.getAttribute('data-row-seq')));
      });
    });

    const selectedFrame = frames.find((f) => f.seq === state.selectedSeq) || frames[0];
    if (selectedFrame) {
      document.getElementById('selected-frame-indicator').textContent =
        `INSPECTING ${state.selectedTrack.toUpperCase()} FRAME #${selectedFrame.seq} · ${selectedFrame.rpc_method}`;
      document.getElementById('req-byte-meta').textContent = `${(selectedFrame.request_payload || '').length} B`;
      document.getElementById('resp-byte-meta').textContent = `${(selectedFrame.response_payload || '').length} B`;
      document.getElementById('raw-request-dump').textContent = formatJsonPretty(selectedFrame.request_payload);
      document.getElementById('raw-response-dump').textContent = formatJsonPretty(selectedFrame.response_payload);
    }

    // Ledger Diff Columns
    document.getElementById('diff-u-status').textContent = uEval.integrity_passed ? 'OK' : uEval.verdict;
    document.getElementById('diff-u-delta').textContent = formatEur(uEval.actual_delta_cents);
    document.getElementById('diff-u-expected').textContent = formatEur(uEval.expected_delta_cents);
    document.getElementById('diff-u-writes').textContent =
      `${uEval.write_count} row(s)${uEval.duplicate_writes > 0 ? ` (${uEval.duplicate_writes} duplicate)` : ''}`;
    document.getElementById('diff-u-tools').textContent =
      `${duel.unguarded.exposed_tool_count} exposed${duel.unguarded.exposed_tool_count > 5 ? ' (poisoned)' : ''}`;

    document.getElementById('diff-g-status').textContent = gEval.verdict;
    document.getElementById('diff-g-delta').textContent = formatEur(gEval.actual_delta_cents);
    document.getElementById('diff-g-expected').textContent = formatEur(gEval.expected_delta_cents);
    document.getElementById('diff-g-writes').textContent = `${gEval.write_count} row (sealed)`;
    document.getElementById('diff-g-tools').textContent = `${duel.guarded.exposed_tool_count} verified`;

    renderLedgerRows('diff-u-rows', duel.unguarded.ledger_after.entries || [], false);
    renderLedgerRows('diff-g-rows', duel.guarded.ledger_after.entries || [], true);

    document.getElementById('diff-u-quote').textContent = `Agent: "${duel.unguarded.agent_output}"`;
    document.getElementById('diff-g-quote').textContent = `Agent: "${duel.guarded.agent_output}"`;
  }

  function renderLedgerRows(containerId, entries, isGuarded) {
    const el = document.getElementById(containerId);
    if (!entries.length) {
      el.innerHTML = `<div class="ledger-empty">0 SQLite rows committed</div>`;
      return;
    }

    el.innerHTML = entries.map((entry, idx) => {
      const leakCls = !isGuarded && idx > 0 ? 'ledger-entry--leak' : '';
      const keyTag = entry.idempotency_key ? ` · ${entry.idempotency_key.slice(0, 12)}` : ' · NO_KEY';
      return `
        <div class="ledger-entry ${leakCls}">
          <span>${escapeSvgText(entry.tx_id)}${escapeSvgText(keyTag)}</span>
          <strong>${escapeSvgText(formatEur(entry.delta_cents))}</strong>
        </div>
      `;
    }).join('');
  }

  function selectFrame(track, seq) {
    state.selectedTrack = track;
    state.selectedSeq = seq;
    renderOscilloscopeSvg(state.duel);
    renderInspectorAndLedger(state.duel);
  }

  function pickDefaultInterestingFrame(duel, track) {
    const frames = (track === 'guarded' ? duel.guarded.frames : duel.unguarded.frames) || [];
    const interesting = frames.find((f) => f.fault_injected || f.guard_action);
    return interesting ? interesting.seq : (frames[0] ? frames[0].seq : 1);
  }

  function applyDuelPayload(duel) {
    state.duel = duel;
    state.activeScenario = duel.scenario.id;
    state.seed = duel.seed;

    document.querySelectorAll('.scenario-btn').forEach((btn) => {
      const active = btn.getAttribute('data-scenario') === state.activeScenario;
      btn.classList.toggle('is-active', active);
      btn.setAttribute('aria-selected', active ? 'true' : 'false');
    });

    document.getElementById('seed-input').value = String(state.seed);
    state.selectedSeq = pickDefaultInterestingFrame(duel, state.selectedTrack);

    renderOscilloscopeSvg(duel);
    renderInspectorAndLedger(duel);
    notifyHostSize();
  }

  async function runDuel(scenarioId, seed) {
    const btn = document.getElementById('run-duel-btn');
    const label = document.getElementById('run-duel-label');
    btn.disabled = true;
    label.textContent = 'RUNNING TRACE...';

    try {
      const resp = await fetch('/api/duel', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ scenario_id: scenarioId, seed: Number(seed) }),
      });
      if (!resp.ok) {
        throw new Error(`HTTP ${resp.status}`);
      }
      const data = await resp.json();
      applyDuelPayload(data);
    } catch (err) {
      label.textContent = 'RETRY DUEL';
      console.error('Faultline duel request failed:', err);
    } finally {
      btn.disabled = false;
      label.textContent = 'INJECT & REPLAY';
    }
  }

  async function bootstrap() {
    initMcpAppsBridge();

    document.querySelectorAll('.scenario-btn').forEach((btn) => {
      btn.addEventListener('click', () => {
        const scenarioId = btn.getAttribute('data-scenario') || 'post_commit_truncation';
        const seedVal = Number(document.getElementById('seed-input').value || 20261123);
        runDuel(scenarioId, seedVal);
      });
    });

    document.querySelectorAll('[data-track-filter]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const track = btn.getAttribute('data-track-filter') || 'unguarded';
        if (!state.duel) {
          return;
        }
        state.selectedTrack = track;
        state.selectedSeq = pickDefaultInterestingFrame(state.duel, track);
        renderOscilloscopeSvg(state.duel);
        renderInspectorAndLedger(state.duel);
      });
    });

    document.getElementById('reseed-btn').addEventListener('click', () => {
      const current = Number(document.getElementById('seed-input').value || 20261123);
      const idx = SEEDS_CYCLE.indexOf(current);
      const nextSeed = SEEDS_CYCLE[(idx + 1) % SEEDS_CYCLE.length];
      document.getElementById('seed-input').value = String(nextSeed);
      runDuel(state.activeScenario, nextSeed);
    });

    document.getElementById('duel-form').addEventListener('submit', (e) => {
      e.preventDefault();
      const seedVal = Number(document.getElementById('seed-input').value || 20261123);
      runDuel(state.activeScenario, seedVal);
    });

    if (window.__FAULTLINE_BOOTSTRAP__ && window.__FAULTLINE_BOOTSTRAP__.initial_duel) {
      applyDuelPayload(window.__FAULTLINE_BOOTSTRAP__.initial_duel);
      return;
    }

    try {
      const resp = await fetch('/api/scenarios');
      if (resp.ok) {
        const boot = await resp.json();
        if (boot.runtime && boot.runtime.hf_token_configured) {
          document.getElementById('engine-mode-chip').textContent = 'HF INFERENCE READY';
        }
        if (boot.initial_duel) {
          applyDuelPayload(boot.initial_duel);
        }
      }
    } catch (err) {
      console.error('Bootstrap failed:', err);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootstrap);
  } else {
    bootstrap();
  }
})();
