<?php
declare(strict_types=1);

// This package is copied only into an explicitly owned disposable fixture root.
if (!defined('ROOT_PATH') || !str_starts_with(ROOT_PATH, sys_get_temp_dir() . '/vvx-payment-foundation-')
    || !is_file(ROOT_PATH . '/.payment-foundation-fixture')) throw new RuntimeException('Owned payment fixture required.');

return static function (\App\Core\AddonContext $addon): void {
    foreach (['order.completed','order.refunded','order.chargeback','subscription.started','subscription.cancelled'] as $event) {
        $addon->on($event, static function (array $payload) use ($event): array {
            $path = ROOT_PATH . '/addon-deliveries.jsonl';
            $handle = fopen($path, 'ab'); chmod($path, 0600);
            fwrite($handle, json_encode(['event' => $event, 'operationId' => $payload['paymentOperationId'] ?? null,
                'effectId' => $payload['paymentEffectId'] ?? null, 'orderId' => $payload['order']['id'] ?? null,
                'memberName' => $payload['order']['memberName'] ?? null], JSON_THROW_ON_ERROR) . "\n");
            fflush($handle); fsync($handle); fclose($handle);
            $mode = is_file(ROOT_PATH . '/addon-mode') ? trim((string) file_get_contents(ROOT_PATH . '/addon-mode')) : 'success';
            if ($mode === 'kill') { posix_kill(getmypid(), SIGKILL); exit(98); }
            if ($mode === 'throw') throw new RuntimeException('Local fixture accepted the effect before losing its acknowledgement.');
            return $payload;
        });
    }
};
