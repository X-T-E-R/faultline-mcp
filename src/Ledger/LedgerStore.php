<?php

declare(strict_types=1);

namespace Faultline\Ledger;

/**
 * SQLite-backed e-commerce order and settlement ledger used by the reference MCP target server.
 *
 * Scopes isolate parallel deterministic runs (e.g. "unguarded" vs "guarded") inside one SQLite database
 * so side-effect anomalies such as double charges and over-refunds can be diffed row-for-row.
 */
final class LedgerStore
{
    private \PDO $pdo;

    public function __construct(string $dsnOrPath = ':memory:')
    {
        $dsn = str_starts_with($dsnOrPath, 'sqlite:') || ':memory:' === $dsnOrPath
            ? (':memory:' === $dsnOrPath ? 'sqlite::memory:' : $dsnOrPath)
            : 'sqlite:'.$dsnOrPath;

        if (!str_starts_with($dsn, 'sqlite::memory:')) {
            $filePath = substr($dsn, \strlen('sqlite:'));
            $dir = \dirname($filePath);
            if ('' !== $dir && !is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
        }

        $this->pdo = new \PDO($dsn, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA journal_mode = WAL;');
        $this->pdo->exec('PRAGMA busy_timeout = 5000;');
        $this->initializeSchema();
    }

    public function getPdo(): \PDO
    {
        return $this->pdo;
    }

    public function resetScope(string $scope = 'default'): void
    {
        $this->pdo->beginTransaction();
        try {
            $delEntries = $this->pdo->prepare('DELETE FROM ledger_entries WHERE scope = :scope');
            $delEntries->execute(['scope' => $scope]);

            $delOrders = $this->pdo->prepare('DELETE FROM commerce_orders WHERE scope = :scope');
            $delOrders->execute(['scope' => $scope]);

            $delAccount = $this->pdo->prepare('DELETE FROM merchant_accounts WHERE scope = :scope');
            $delAccount->execute(['scope' => $scope]);

            $insAccount = $this->pdo->prepare(
                'INSERT INTO merchant_accounts (scope, merchant_id, currency, balance_cents) VALUES (:scope, :merchant_id, :currency, :balance_cents)'
            );
            $insAccount->execute([
                'scope' => $scope,
                'merchant_id' => 'MERCH-ATELIER-01',
                'currency' => 'EUR',
                'balance_cents' => 45400,
            ]);

            $fixtureOrders = [
                [
                    'order_id' => 'ORD-9042',
                    'customer_name' => 'Clara Oswald',
                    'customer_email' => 'clara.oswald@example.org',
                    'sku' => 'CHRONO-DIAL-MK2',
                    'authorized_cents' => 14900,
                    'captured_cents' => 14900,
                    'refunded_cents' => 0,
                    'currency' => 'EUR',
                    'status' => 'captured',
                ],
                [
                    'order_id' => 'ORD-9043',
                    'customer_name' => 'Marc Weber',
                    'customer_email' => 'marc.weber@example.org',
                    'sku' => 'BRASS-CALIPER-01',
                    'authorized_cents' => 8500,
                    'captured_cents' => 0,
                    'refunded_cents' => 0,
                    'currency' => 'EUR',
                    'status' => 'authorized',
                ],
                [
                    'order_id' => 'ORD-9044',
                    'customer_name' => 'Amina Diallo',
                    'customer_email' => 'amina.diallo@example.org',
                    'sku' => 'SIGNAL-PROBE-X4',
                    'authorized_cents' => 22000,
                    'captured_cents' => 22000,
                    'refunded_cents' => 0,
                    'currency' => 'EUR',
                    'status' => 'captured',
                ],
            ];

            $insOrder = $this->pdo->prepare(
                'INSERT INTO commerce_orders (
                    scope, order_id, customer_name, customer_email, sku,
                    authorized_cents, captured_cents, refunded_cents, currency, status
                ) VALUES (
                    :scope, :order_id, :customer_name, :customer_email, :sku,
                    :authorized_cents, :captured_cents, :refunded_cents, :currency, :status
                )'
            );

            foreach ($fixtureOrders as $order) {
                $insOrder->execute(['scope' => $scope] + $order);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function ensureSeeded(string $scope = 'default'): void
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM merchant_accounts WHERE scope = :scope');
        $stmt->execute(['scope' => $scope]);
        if (0 === (int) $stmt->fetchColumn()) {
            $this->resetScope($scope);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getOrder(string $orderId, string $scope = 'default'): array
    {
        $this->ensureSeeded($scope);

        $stmt = $this->pdo->prepare('SELECT * FROM commerce_orders WHERE scope = :scope AND order_id = :order_id');
        $stmt->execute(['scope' => $scope, 'order_id' => $orderId]);
        $row = $stmt->fetch();

        if (!\is_array($row)) {
            throw new \InvalidArgumentException(\sprintf('Order "%s" not found in scope "%s".', $orderId, $scope));
        }

        $captured = (int) $row['captured_cents'];
        $refunded = (int) $row['refunded_cents'];

        return [
            'order_id' => (string) $row['order_id'],
            'customer_name' => (string) $row['customer_name'],
            'customer_email' => (string) $row['customer_email'],
            'sku' => (string) $row['sku'],
            'authorized_cents' => (int) $row['authorized_cents'],
            'captured_cents' => $captured,
            'refunded_cents' => $refunded,
            'net_settled_cents' => $captured - $refunded,
            'currency' => (string) $row['currency'],
            'status' => (string) $row['status'],
        ];
    }

    /**
     * Issues a settlement refund against an order.
     *
     * Without an explicit idempotency key, legacy settlement gateways append a new debit row on every invocation,
     * exposing unguarded retry loops to double-refund over-settlements.
     *
     * @return array<string, mixed>
     */
    public function issueRefund(
        string $orderId,
        int $amountCents,
        string $reason,
        ?string $idempotencyKey = null,
        string $scope = 'default',
    ): array {
        if ($amountCents <= 0) {
            throw new \InvalidArgumentException('Refund amountCents must be strictly positive.');
        }

        $this->ensureSeeded($scope);
        $this->pdo->beginTransaction();

        try {
            if (null !== $idempotencyKey && '' !== trim($idempotencyKey)) {
                $existing = $this->findEntryByIdempotencyKey($scope, $idempotencyKey);
                if (null !== $existing) {
                    $this->pdo->commit();

                    return $existing + ['deduplicated_upstream' => true];
                }
            }

            $order = $this->getOrder($orderId, $scope);
            $newRefunded = (int) $order['refunded_cents'] + $amountCents;
            $captured = (int) $order['captured_cents'];
            $status = $newRefunded > $captured
                ? 'over_refunded_anomaly'
                : ($newRefunded === $captured ? 'refunded' : 'partially_refunded');

            $updOrder = $this->pdo->prepare(
                'UPDATE commerce_orders SET refunded_cents = :refunded, status = :status WHERE scope = :scope AND order_id = :order_id'
            );
            $updOrder->execute([
                'refunded' => $newRefunded,
                'status' => $status,
                'scope' => $scope,
                'order_id' => $orderId,
            ]);

            $deltaCents = -$amountCents;
            $newBalance = $this->adjustMerchantBalance($scope, $deltaCents);
            $txId = $this->nextTransactionId($scope, 'RFND');

            $insEntry = $this->pdo->prepare(
                'INSERT INTO ledger_entries (
                    scope, tx_id, order_id, entry_type, delta_cents, balance_after_cents,
                    currency, reason, idempotency_key, created_at_us
                ) VALUES (
                    :scope, :tx_id, :order_id, :entry_type, :delta_cents, :balance_after_cents,
                    :currency, :reason, :idempotency_key, :created_at_us
                )'
            );
            $createdAtUs = (int) (microtime(true) * 1_000_000);
            $insEntry->execute([
                'scope' => $scope,
                'tx_id' => $txId,
                'order_id' => $orderId,
                'entry_type' => 'refund_debit',
                'delta_cents' => $deltaCents,
                'balance_after_cents' => $newBalance,
                'currency' => $order['currency'],
                'reason' => $reason,
                'idempotency_key' => (null !== $idempotencyKey && '' !== trim($idempotencyKey)) ? $idempotencyKey : null,
                'created_at_us' => $createdAtUs,
            ]);

            $this->pdo->commit();

            return [
                'tx_id' => $txId,
                'order_id' => $orderId,
                'entry_type' => 'refund_debit',
                'amount_cents' => $amountCents,
                'delta_cents' => $deltaCents,
                'order_refunded_total_cents' => $newRefunded,
                'order_captured_cents' => $captured,
                'order_status' => $status,
                'merchant_balance_after_cents' => $newBalance,
                'currency' => $order['currency'],
                'reason' => $reason,
                'idempotency_key' => $idempotencyKey,
                'deduplicated_upstream' => false,
            ];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Captures a payment charge against an authorized order.
     *
     * @return array<string, mixed>
     */
    public function capturePayment(
        string $orderId,
        int $amountCents,
        string $memo,
        ?string $idempotencyKey = null,
        string $scope = 'default',
    ): array {
        if ($amountCents <= 0) {
            throw new \InvalidArgumentException('Capture amountCents must be strictly positive.');
        }

        $this->ensureSeeded($scope);
        $this->pdo->beginTransaction();

        try {
            if (null !== $idempotencyKey && '' !== trim($idempotencyKey)) {
                $existing = $this->findEntryByIdempotencyKey($scope, $idempotencyKey);
                if (null !== $existing) {
                    $this->pdo->commit();

                    return $existing + ['deduplicated_upstream' => true];
                }
            }

            $order = $this->getOrder($orderId, $scope);
            $authorized = (int) $order['authorized_cents'];
            $newCaptured = (int) $order['captured_cents'] + $amountCents;
            $status = $newCaptured > $authorized ? 'double_charged_anomaly' : 'captured';

            $updOrder = $this->pdo->prepare(
                'UPDATE commerce_orders SET captured_cents = :captured, status = :status WHERE scope = :scope AND order_id = :order_id'
            );
            $updOrder->execute([
                'captured' => $newCaptured,
                'status' => $status,
                'scope' => $scope,
                'order_id' => $orderId,
            ]);

            $newBalance = $this->adjustMerchantBalance($scope, $amountCents);
            $txId = $this->nextTransactionId($scope, 'CAPT');

            $insEntry = $this->pdo->prepare(
                'INSERT INTO ledger_entries (
                    scope, tx_id, order_id, entry_type, delta_cents, balance_after_cents,
                    currency, reason, idempotency_key, created_at_us
                ) VALUES (
                    :scope, :tx_id, :order_id, :entry_type, :delta_cents, :balance_after_cents,
                    :currency, :reason, :idempotency_key, :created_at_us
                )'
            );
            $insEntry->execute([
                'scope' => $scope,
                'tx_id' => $txId,
                'order_id' => $orderId,
                'entry_type' => 'capture_credit',
                'delta_cents' => $amountCents,
                'balance_after_cents' => $newBalance,
                'currency' => $order['currency'],
                'reason' => $memo,
                'idempotency_key' => (null !== $idempotencyKey && '' !== trim($idempotencyKey)) ? $idempotencyKey : null,
                'created_at_us' => (int) (microtime(true) * 1_000_000),
            ]);

            $this->pdo->commit();

            return [
                'tx_id' => $txId,
                'order_id' => $orderId,
                'entry_type' => 'capture_credit',
                'amount_cents' => $amountCents,
                'delta_cents' => $amountCents,
                'order_captured_total_cents' => $newCaptured,
                'order_authorized_cents' => $authorized,
                'order_status' => $status,
                'merchant_balance_after_cents' => $newBalance,
                'currency' => $order['currency'],
                'reason' => $memo,
                'idempotency_key' => $idempotencyKey,
                'deduplicated_upstream' => false,
            ];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listEntries(?string $orderId = null, string $scope = 'default'): array
    {
        $this->ensureSeeded($scope);

        if (null !== $orderId && '' !== $orderId) {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM ledger_entries WHERE scope = :scope AND order_id = :order_id ORDER BY id ASC'
            );
            $stmt->execute(['scope' => $scope, 'order_id' => $orderId]);
        } else {
            $stmt = $this->pdo->prepare('SELECT * FROM ledger_entries WHERE scope = :scope ORDER BY id ASC');
            $stmt->execute(['scope' => $scope]);
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll();

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'tx_id' => (string) $row['tx_id'],
            'order_id' => (string) $row['order_id'],
            'entry_type' => (string) $row['entry_type'],
            'delta_cents' => (int) $row['delta_cents'],
            'balance_after_cents' => (int) $row['balance_after_cents'],
            'currency' => (string) $row['currency'],
            'reason' => (string) $row['reason'],
            'idempotency_key' => $row['idempotency_key'],
            'created_at_us' => (int) $row['created_at_us'],
        ], $rows);
    }

    /**
     * Captures a full state snapshot of a ledger scope for pre/post integrity diffing.
     *
     * @return array{
     *     scope: string,
     *     merchant_balance_cents: int,
     *     currency: string,
     *     orders: array<string, array<string, mixed>>,
     *     entries: list<array<string, mixed>>
     * }
     */
    public function snapshot(string $scope = 'default'): array
    {
        $this->ensureSeeded($scope);

        $accStmt = $this->pdo->prepare('SELECT balance_cents, currency FROM merchant_accounts WHERE scope = :scope');
        $accStmt->execute(['scope' => $scope]);
        /** @var array{balance_cents: int|string, currency: string}|false $acc */
        $acc = $accStmt->fetch();

        $ordStmt = $this->pdo->prepare('SELECT * FROM commerce_orders WHERE scope = :scope ORDER BY order_id ASC');
        $ordStmt->execute(['scope' => $scope]);
        $orders = [];
        foreach ($ordStmt->fetchAll() as $row) {
            $orders[(string) $row['order_id']] = [
                'order_id' => (string) $row['order_id'],
                'customer_name' => (string) $row['customer_name'],
                'sku' => (string) $row['sku'],
                'authorized_cents' => (int) $row['authorized_cents'],
                'captured_cents' => (int) $row['captured_cents'],
                'refunded_cents' => (int) $row['refunded_cents'],
                'net_settled_cents' => (int) $row['captured_cents'] - (int) $row['refunded_cents'],
                'currency' => (string) $row['currency'],
                'status' => (string) $row['status'],
            ];
        }

        return [
            'scope' => $scope,
            'merchant_balance_cents' => \is_array($acc) ? (int) $acc['balance_cents'] : 0,
            'currency' => \is_array($acc) ? (string) $acc['currency'] : 'EUR',
            'orders' => $orders,
            'entries' => $this->listEntries(null, $scope),
        ];
    }

    private function adjustMerchantBalance(string $scope, int $deltaCents): int
    {
        $stmt = $this->pdo->prepare('SELECT balance_cents FROM merchant_accounts WHERE scope = :scope');
        $stmt->execute(['scope' => $scope]);
        $current = (int) $stmt->fetchColumn();
        $next = $current + $deltaCents;

        $upd = $this->pdo->prepare('UPDATE merchant_accounts SET balance_cents = :balance WHERE scope = :scope');
        $upd->execute(['balance' => $next, 'scope' => $scope]);

        return $next;
    }

    private function nextTransactionId(string $scope, string $prefix): string
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM ledger_entries WHERE scope = :scope');
        $stmt->execute(['scope' => $scope]);
        $seq = (int) $stmt->fetchColumn() + 1;

        return \sprintf('%s-%04d', $prefix, $seq);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findEntryByIdempotencyKey(string $scope, string $idempotencyKey): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ledger_entries WHERE scope = :scope AND idempotency_key = :key LIMIT 1'
        );
        $stmt->execute(['scope' => $scope, 'key' => $idempotencyKey]);
        $entry = $stmt->fetch();
        if (!\is_array($entry)) {
            return null;
        }

        $order = $this->getOrder((string) $entry['order_id'], $scope);

        return [
            'tx_id' => (string) $entry['tx_id'],
            'order_id' => (string) $entry['order_id'],
            'entry_type' => (string) $entry['entry_type'],
            'amount_cents' => abs((int) $entry['delta_cents']),
            'delta_cents' => (int) $entry['delta_cents'],
            'order_refunded_total_cents' => $order['refunded_cents'],
            'order_captured_cents' => $order['captured_cents'],
            'order_status' => $order['status'],
            'merchant_balance_after_cents' => (int) $entry['balance_after_cents'],
            'currency' => (string) $entry['currency'],
            'reason' => (string) $entry['reason'],
            'idempotency_key' => (string) $entry['idempotency_key'],
        ];
    }

    private function initializeSchema(): void
    {
        $this->pdo->exec(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS merchant_accounts (
                scope TEXT PRIMARY KEY,
                merchant_id TEXT NOT NULL,
                currency TEXT NOT NULL,
                balance_cents INTEGER NOT NULL
            );

            CREATE TABLE IF NOT EXISTS commerce_orders (
                scope TEXT NOT NULL,
                order_id TEXT NOT NULL,
                customer_name TEXT NOT NULL,
                customer_email TEXT NOT NULL,
                sku TEXT NOT NULL,
                authorized_cents INTEGER NOT NULL,
                captured_cents INTEGER NOT NULL,
                refunded_cents INTEGER NOT NULL,
                currency TEXT NOT NULL,
                status TEXT NOT NULL,
                PRIMARY KEY (scope, order_id)
            );

            CREATE TABLE IF NOT EXISTS ledger_entries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                scope TEXT NOT NULL,
                tx_id TEXT NOT NULL,
                order_id TEXT NOT NULL,
                entry_type TEXT NOT NULL,
                delta_cents INTEGER NOT NULL,
                balance_after_cents INTEGER NOT NULL,
                currency TEXT NOT NULL,
                reason TEXT NOT NULL,
                idempotency_key TEXT DEFAULT NULL,
                created_at_us INTEGER NOT NULL
            );

            CREATE INDEX IF NOT EXISTS idx_ledger_scope_key ON ledger_entries (scope, idempotency_key);
            SQL
        );
    }
}
