<?php
declare(strict_types=1);

namespace App\Core;

use App\Payments\PaymentContract;
use PDO;
use RuntimeException;
use Throwable;

/** One financial commit boundary shared by the journal, money, stock and access records. */
trait PaymentStoreTrait
{
    private bool $paymentAtomicActive = false;
    private ?array $paymentFileBuffer = null;
    private ?string $paymentFileDigest = null;
    private ?string $paymentMemberId = null;

    /** Adapter callbacks must never execute while a financial snapshot is open. */
    public function paymentRequireCallbackBoundary(): void
    {
        if ($this->paymentAtomicActive) throw new RuntimeException('Payment adapter callbacks cannot run inside a financial commit.');
    }

    /**
     * Only bounded core Store calls belong here: no provider, mail, addon or
     * secondary-store callbacks. The outer commerce mutex is mandatory. File
     * writers stage into one fresh snapshot under the normal common write lock;
     * legacy mutateFileStore's nested-writer prohibition remains unchanged.
     */
    public function paymentAtomic(callable $operation, ?string $memberId = null): mixed
    {
        if (!$this->commerceLockHeld || $this->paymentAtomicActive || $this->fileMutationActive) {
            throw new RuntimeException('Payment commits require an exclusive, non-nested commerce operation.');
        }
        if ($this->mode !== 'file') {
            $pdo = $this->pdo();
            if ($pdo->inTransaction()) throw new RuntimeException('Payment commits cannot join an unrelated transaction.');
            $pdo->beginTransaction(); $this->paymentAtomicActive = true; $this->paymentMemberId = $memberId;
            // Lock the member before the first consistent read. Waiting until a
            // later writer would allow an old SQL snapshot to miss a just-committed erasure.
            try { $result = $memberId === null ? $operation() : $this->privacyMemberWrite($memberId, $operation, true); $pdo->commit(); return $result; }
            catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
            finally { $this->paymentAtomicActive = false; $this->paymentMemberId = null; }
        }
        $lock = $this->lockFileStore(true);
        try {
            $this->initializeFileStore();
            [$before, $digest] = $this->loadFileStore();
            $this->fileReadDigest = $digest; $this->paymentFileDigest = $digest;
            $this->paymentFileBuffer = $before; $this->fileMutationActive = true; $this->paymentAtomicActive = true; $this->paymentMemberId = $memberId;
            $result = $memberId === null ? $operation() : $this->privacyMemberWrite($memberId, $operation, true); $after = $this->paymentFileBuffer;
            // activateFileStore must compare the real disk bytes, not the staged buffer.
            $this->paymentFileBuffer = null;
            if ($after !== $before) {
                $this->activateFileStore($after, $digest);
                $directory = @fopen(dirname($this->filePath), 'r');
                try {
                    if ($directory === false || !function_exists('fsync') || !@fsync($directory)) throw new RuntimeException('Payment storage directory could not be synchronized. No provider retry is authorized.');
                } finally { if (is_resource($directory)) fclose($directory); }
            }
            return $result;
        } finally {
            $this->paymentFileBuffer = null; $this->paymentFileDigest = null; $this->paymentAtomicActive = false;
            $this->paymentMemberId = null;
            $this->fileMutationActive = false; $this->fileReadDigest = null;
            flock($lock, LOCK_UN); fclose($lock);
        }
    }

    public function paymentJournal(string $id): ?array
    {
        if ($this->mode === 'file') $row = $this->loadFileStore()[0]['paymentJournal'][$id] ?? null;
        else {
            $statement = $this->pdo()->prepare('SELECT record_json FROM payment_journal WHERE id=?'); $statement->execute([$id]);
            $json = $statement->fetchColumn(); $row = $json === false ? null : json_decode((string) $json, true, 32, JSON_THROW_ON_ERROR);
        }
        if ($row === null) return null;
        return PaymentContract::journal($row);
    }

    /** Internal keyset page. Public/owner screens must use PaymentRecoveryService's projection. */
    public function paymentJournalPage(?string $afterId = null, int $limit = 50, bool $openOnly = true, ?string $orderId = null): array
    {
        if ($limit < 1 || $limit > 200 || ($afterId !== null && preg_match('/\A(?:pay_[a-f0-9]{32}|evt_[a-f0-9]{64}|fx_[a-f0-9]{64})\z/D', $afterId) !== 1)) throw new RuntimeException('Invalid payment recovery page.');
        if ($orderId !== null) {
            PaymentContract::reference($orderId);
            if ($this->mode !== 'file' && !ctype_digit($orderId)) throw new RuntimeException('Invalid payment order identifier.');
        }
        $terminal = ['applied','declined','ignored','done','skipped'];
        if ($this->mode === 'file') {
            $rows = array_values(array_filter($this->loadFileStore()[0]['paymentJournal'] ?? [], static fn(array $row): bool =>
                ($afterId === null || strcmp($row['id'], $afterId) > 0) && ($orderId === null || $row['orderId'] === $orderId)
                && (!$openOnly || !in_array($row['state'], $terminal, true))));
            usort($rows, static fn(array $a, array $b): int => strcmp($a['id'], $b['id']));
            $rows = array_slice($rows, 0, $limit + 1);
        } else {
            $where = []; $parameters = [];
            if ($afterId !== null) { $where[] = 'id>?'; $parameters[] = $afterId; }
            if ($orderId !== null) { $where[] = 'order_id=?'; $parameters[] = $orderId; }
            if ($openOnly) $where[] = "JSON_UNQUOTE(JSON_EXTRACT(record_json,'$.state')) NOT IN ('applied','declined','ignored','done','skipped')";
            $statement = $this->pdo()->prepare('SELECT record_json FROM payment_journal' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id LIMIT ' . ($limit + 1));
            $statement->execute($parameters);
            $rows = array_map(static fn(string $json): array => json_decode($json, true, 32, JSON_THROW_ON_ERROR), $statement->fetchAll(PDO::FETCH_COLUMN));
        }
        $hasMore = count($rows) > $limit; if ($hasMore) array_pop($rows);
        $rows = array_map(PaymentContract::journal(...), $rows);
        return ['records' => $rows, 'nextCursor' => $hasMore ? $rows[array_key_last($rows)]['id'] : null];
    }

    /** Complete order-scoped rows; never use the screen transaction list limit for correctness. */
    public function paymentJournalForOrder(string $orderId): array
    {
        if ($this->mode === 'file') $rows = array_values(array_filter($this->loadFileStore()[0]['paymentJournal'] ?? [], static fn(array $row): bool => ($row['orderId'] ?? null) === $orderId));
        else {
            $statement = $this->pdo()->prepare('SELECT record_json FROM payment_journal WHERE order_id=? ORDER BY id'); $statement->execute([$orderId]);
            $rows = array_map(static fn(string $json): array => json_decode($json, true, 32, JSON_THROW_ON_ERROR), $statement->fetchAll(PDO::FETCH_COLUMN));
        }
        $rows = array_map(PaymentContract::journal(...), $rows);
        usort($rows, static fn(array $a, array $b): int => strcmp($a['id'], $b['id']));
        return $rows;
    }

    public function savePaymentJournal(array $row): array
    {
        if (!$this->paymentAtomicActive || !$this->commerceLockHeld) throw new RuntimeException('Payment journal writes require the financial commit boundary.');
        $row = PaymentContract::journal($row); $previous = $this->paymentJournal($row['id']);
        PaymentContract::transition($previous, $row);
        if ($previous !== null) {
            foreach (['id','kind','orderId','createdAt'] as $key) if ($previous[$key] !== $row[$key]) throw new RuntimeException('Payment journal identity cannot change.');
            $immutable = match ($row['kind']) { 'operation' => ['binding','source'], 'event' => ['provider','account','environment','eventId','eventType','fingerprint','operationId','event'], 'effect' => ['operationId','effect','event','subscriptionId','amountMinor','source'] };
            foreach ($immutable as $key) if (($previous['data'][$key] ?? null) !== ($row['data'][$key] ?? null)) throw new RuntimeException('Payment journal binding cannot change.');
        }
        if ($row['kind'] === 'effect') {
            $operation = $this->paymentJournal($row['data']['operationId']);
            if ($operation === null || $operation['kind'] !== 'operation' || $operation['orderId'] !== $row['orderId']) throw new RuntimeException('Payment obligation has no matching durable operation.');
        }
        if ($row['orderId'] !== null) {
            $order = $this->order($row['orderId']);
            if (!$order) throw new RuntimeException('Payment journal order no longer exists.');
            if ($this->paymentMemberId !== (string) $order['memberId']) throw new RuntimeException('Payment commit is not fenced to this member.');
            // The journal contains retained financial facts only. The same member
            // fence serializes it with privacy inventory/freeze, even after erasure.
            if ($this->privacyWriteDepth === 0) return $this->privacyMemberWrite((string) $order['memberId'], fn(): array => $this->savePaymentJournal($row), true);
        }
        if ($this->mode === 'file') {
            $all = $this->readFileStore(); $all['paymentJournal'][$row['id']] = $row; $this->writeFileStore($all);
        } else {
            $json = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if ($previous === null) $this->pdo()->prepare('INSERT INTO payment_journal (id,order_id,record_kind,record_json) VALUES (?,?,?,?)')->execute([$row['id'],$row['orderId'],$row['kind'],$json]);
            else $this->pdo()->prepare('UPDATE payment_journal SET record_json=? WHERE id=?')->execute([$json,$row['id']]);
        }
        return $row;
    }

    /** All matches, including ambiguous legacy references; callers must fail closed. */
    public function paymentTransactionsByReference(string $provider, string $reference): array
    {
        if ($this->mode === 'file') return array_values(array_filter($this->loadFileStore()[0]['paymentTransactions'] ?? [], static fn(array $row): bool => ($row['providerKey'] ?? null) === $provider && ($row['providerTransactionId'] ?? null) === $reference));
        $statement = $this->pdo()->prepare('SELECT * FROM payment_transactions WHERE BINARY provider_key=? AND BINARY provider_transaction_id=?'); $statement->execute([$provider,$reference]);
        return array_map(fn(array $row): array => $this->mapTransactionRow($row), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function paymentTransactionsForOrder(string $orderId): array
    {
        if ($this->mode === 'file') return array_values(array_filter($this->loadFileStore()[0]['paymentTransactions'] ?? [], static fn(array $row): bool => (string) ($row['orderId'] ?? '') === $orderId));
        $statement = $this->pdo()->prepare('SELECT * FROM payment_transactions WHERE order_id=? ORDER BY id'); $statement->execute([$orderId]);
        return array_map(fn(array $row): array => $this->mapTransactionRow($row), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Includes confirmed-but-not-applied operations, before a transaction row exists. */
    public function paymentOperationsByReference(string $provider, string $reference): array
    {
        if ($this->mode === 'file') $rows = array_values(array_filter($this->loadFileStore()[0]['paymentJournal'] ?? [], static fn(array $row): bool => ($row['kind'] ?? '') === 'operation' && ($row['data']['binding']['provider'] ?? null) === $provider && ($row['data']['result']['transactionId'] ?? null) === $reference));
        else {
            $statement = $this->pdo()->prepare("SELECT record_json FROM payment_journal WHERE record_kind='operation' AND BINARY JSON_UNQUOTE(JSON_EXTRACT(record_json,'$.data.binding.provider'))=? AND BINARY JSON_UNQUOTE(JSON_EXTRACT(record_json,'$.data.result.transactionId'))=?");
            $statement->execute([$provider, $reference]);
            $rows = array_map(static fn(string $json): array => json_decode($json, true, 32, JSON_THROW_ON_ERROR), $statement->fetchAll(PDO::FETCH_COLUMN));
        }
        return array_map(PaymentContract::journal(...), $rows);
    }
}
