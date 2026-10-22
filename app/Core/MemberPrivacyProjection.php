<?php
declare(strict_types=1);

namespace App\Core;

/** Export fields are explicit. Adding a database column never silently widens a member archive. */
final class MemberPrivacyProjection
{
    public static function fields(array $row, array $fields): array
    {
        return array_intersect_key($row, array_fill_keys($fields, true));
    }

    private static function rows(array $rows, array $fields): array
    {
        return array_values(array_map(static fn(array $row): array => self::fields($row, $fields), $rows));
    }

    public static function origin(mixed $value): ?array
    {
        if (!is_array($value)) return null;
        return self::fields($value, ['referral_code', 'campaign_id', 'captured_at', 'expires_at']);
    }

    public static function core(array $data): array
    {
        $account = self::fields($data['member'], ['id', 'displayName', 'email', 'status', 'emailVerifiedAt', 'lastLoginAt', 'lastSeenAt', 'createdAt', 'updatedAt']);
        $account['preferences'] = self::fields((array) ($data['member']['preferences'] ?? []), ['emailUpdates', 'newContentAlerts', 'registrationReminder', 'registrationReminderConsentedAt']);
        $account['origin'] = self::origin($data['member']['preferences']['_attribution'] ?? null);
        $orders = self::rows($data['orders'], ['id', 'orderNumber', 'status', 'subtotal', 'discountTotal', 'taxTotal', 'grandTotal', 'refundedTotal', 'currency', 'couponCode', 'billingEmail', 'paidAt', 'createdAt', 'updatedAt']);
        foreach ($orders as $index => &$order) {
            $order['origin'] = self::origin($data['orders'][$index]['metadata']['attribution'] ?? null);
            foreach (['subtotal','discountTotal','taxTotal','grandTotal','refundedTotal'] as $field) if (isset($order[$field])) $order[$field . 'Minor'] = self::minor($order[$field]);
        }
        unset($order);
        $messages = array_values(array_filter($data['messages'], static fn(array $row): bool => ($row['visibility'] ?? '') === 'public'));
        $visible = array_fill_keys(array_map('strval', array_column($messages, 'id')), true);
        $attachments = array_merge(array_values(array_filter($data['messageAttachments'], static fn(array $row): bool => isset($visible[(string) ($row['messageId'] ?? '')]))),
            array_values(array_filter($data['requestAttachments'], static fn(array $row): bool => ($row['uploadedByType'] ?? '') === 'member')));
        $forms = [];
        foreach ($data['bookingForms'] as $form) {
            $answers = []; $values = (array) ($form['values'] ?? []);
            foreach ((array) ($form['fields'] ?? []) as $field) {
                if (!is_array($field)) continue;
                $key = (string) ($field['id'] ?? $field['key'] ?? '');
                if ($key === '' || !array_key_exists($key, $values)) continue;
                $value = $values[$key];
                if (!is_scalar($value) && $value !== null && (!is_array($value) || !array_is_list($value) || count(array_filter($value, 'is_scalar')) !== count($value))) continue;
                $answers[] = ['field' => $key, 'label' => (string) ($field['label'] ?? $key), 'type' => (string) ($field['type'] ?? 'text'), 'answer' => $value];
            }
            $forms[] = ['requestId' => $form['bookingRequestId'], 'submittedAt' => $form['submittedAt'] ?? $form['createdAt'] ?? null, 'answers' => $answers];
        }
        $orderItems = self::rows($data['orderItems'], ['id', 'orderId', 'itemType', 'itemId', 'title', 'quantity', 'unitPrice', 'discountTotal', 'lineTotal', 'fulfillmentStatus', 'createdAt']);
        $acceptedSubscriptions = []; $subscriptionStates = [];
        foreach ($data['orderItems'] as $index => $item) {
            if (!in_array($item['itemType'] ?? '', ['plan','bundle'], true)) continue;
            $orderItems[$index]['purchasedTermsState'] = 'legacy';
            foreach ($data['orders'] as $sourceOrder) {
                if ((string) $sourceOrder['id'] !== (string) $item['orderId']) continue;
                try {
                    $snapshot = PurchasedTerms::forItem($sourceOrder, $item);
                    if ($snapshot !== null) {
                        $orderItems[$index]['purchasedTermsState'] = 'accepted';
                        $orderItems[$index]['purchasedTerms'] = $snapshot;
                        if ($item['itemType'] === 'plan' && isset($item['metadata']['subscriptionId'])) {
                            $acceptedSubscriptions[(string) $item['metadata']['subscriptionId']] = $snapshot;
                            $subscriptionStates[(string) $item['metadata']['subscriptionId']] = 'accepted';
                        }
                    }
                } catch (\RuntimeException) {
                    $orderItems[$index]['purchasedTermsState'] = 'held';
                    if (is_string($item['metadata']['subscriptionId'] ?? null)) $subscriptionStates[$item['metadata']['subscriptionId']] = 'held';
                }
            }
        }
        $subscriptions = self::rows($data['subscriptions'], ['id', 'planId', 'planName', 'status', 'currentPeriodStart', 'currentPeriodEnd', 'cancelAtPeriodEnd', 'graceEndsAt', 'cancelledAt', 'createdAt', 'updatedAt']);
        foreach ($subscriptions as &$subscription) {
            $snapshot = $acceptedSubscriptions[(string) $subscription['id']] ?? null;
            $subscription['purchasedTermsState'] = $subscriptionStates[(string) $subscription['id']] ?? 'legacy';
            if ($snapshot !== null) { $subscription['purchasedTerms'] = $snapshot; $subscription['planName'] = $snapshot['terms']['title']; }
        }
        unset($subscription);
        return ['account' => $account,
            'subscriptions' => $subscriptions,
            'entitlements' => self::rows($data['entitlements'], ['id', 'contentType', 'contentId', 'sourceType', 'sourceId', 'startsAt', 'expiresAt', 'status', 'createdAt', 'updatedAt']),
            'orders' => $orders,
            'orderItems' => $orderItems,
            'transactions' => self::rows($data['transactions'], ['id', 'orderId', 'subscriptionId', 'providerKey', 'transactionType', 'status', 'amount', 'currency', 'createdAt', 'updatedAt']),
            'paymentOperations' => array_values(array_map(static function (array $row): array {
                $safe = self::fields($row, ['id','orderId','kind','state','createdAt','updatedAt']);
                if ($row['kind'] === 'operation') $safe += ['operationType' => $row['data']['binding']['kind']] + self::fields($row['data']['binding'], ['amountMinor','currency','provider']);
                $safe['reasonCode'] = $row['data']['issue'];
                return $safe;
            }, $data['paymentJournal'] ?? [])),
            'couponRedemptions' => self::rows($data['couponRedemptions'], ['id', 'couponId', 'orderId', 'discountAmount', 'discount', 'status', 'createdAt']),
            'mediaState' => self::rows($data['mediaState'], ['contentType', 'contentId', 'progressSeconds', 'isFavorite', 'viewCount', 'lastViewedAt', 'completedAt', 'updatedAt']),
            'devices' => self::rows($data['sessions'], ['id', 'deviceName', 'ipAddress', 'userAgent', 'createdAt', 'lastSeenAt', 'revokedAt']),
            'accountEvents' => self::rows($data['events'], ['id', 'event', 'createdAt']),
            'transactionalMail' => self::rows($data['mail'], ['id', 'template', 'status', 'createdAt', 'sentAt']),
            'conversations' => self::rows($data['threads'], ['id', 'type', 'relatedType', 'relatedId', 'subject', 'status', 'lastMessageAt', 'createdAt', 'updatedAt']),
            'messages' => self::rows($messages, ['id', 'threadId', 'senderType', 'body', 'createdAt']),
            'contentRequests' => self::rows($data['contentRequests'], ['id', 'threadId', 'category', 'title', 'description', 'budgetMin', 'budgetMax', 'currency', 'requestedDueAt', 'status', 'quotedAmount', 'createdAt', 'updatedAt']),
            'bookingRequests' => self::rows($data['bookingRequests'], ['id', 'threadId', 'inquiryType', 'contactName', 'contactEmail', 'phone', 'requestedDate', 'city', 'venue', 'budget', 'details', 'status', 'consentAt', 'createdAt', 'updatedAt']),
            'bookingForms' => $forms,
            'notifications' => self::rows($data['notices'], ['id', 'type', 'title', 'body', 'readAt', 'createdAt']),
            'communicationRestrictions' => self::rows($data['blocks'], ['scope', 'expiresAt', 'createdAt']),
            'attachments' => self::rows($attachments, ['id', 'kind', 'messageId', 'requestType', 'requestId', 'originalName', 'mimeType', 'fileSize', 'createdAt'])];
    }

    public static function domains(array $data, SecureValue $vault): array
    {
        $conversions = [];
        foreach ($data['conversions'] as $row) $conversions[] = self::fields($row, ['type','source_id','recorded_at']) + [
            'origin' => self::origin($row['attribution'] ?? null),
            'facts' => self::fields((array) ($row['facts'] ?? []), ['status','amount_minor','currency','created_at','paid_at','coupon_code'])];
        $reports = [];
        foreach ($data['cases'] as $row) {
            $report = self::fields($row, ['id','label','category','status','created_at','updated_at','appeals']);
            foreach (['name','email','description','url'] as $field) $report[$field] = $vault->decrypt((string) ($row[$field . '_encrypted'] ?? ''));
            $report['events'] = [];
            foreach ($row['events'] ?? [] as $event) {
                $message = $vault->decrypt((string) ($event['public_message_encrypted'] ?? ''));
                if (in_array($event['type'] ?? '', ['appeal','reporter_reply'], true)) $message = $vault->decrypt((string) ($event['message_encrypted'] ?? ''));
                if ($message !== '') $report['events'][] = self::fields($event,['at','type']) + ['message'=>$message];
            }
            $reports[] = $report;
        }
        return ['newsletter' => ['contacts' => self::rows($data['contacts'], ['id','email','name','status','source','consented_at','confirmed_at','updated_at']),
            'consents' => self::rows($data['consents'], ['type','granted','source','recorded_at']),
            'deliveries' => self::rows($data['deliveries'], ['campaign_id','status','attempts','created_at','updated_at','sent_at','provider_status','provider_event_at']),
            'restrictions' => self::rows($data['suppressions'], ['active','reason','created_at'])],
            'optionalNotifications' => self::rows($data['jobs'], ['type','status','created_at','updated_at','due_at','attempts','provider_status','provider_event_at']),
            'mailProviderFeedback' => self::rows($data['providerEvents'], ['event','status','event_at','received_at','processed_at','delivery_kind']),
            'attribution' => $conversions, 'reports' => $reports];
    }

    private static function minor(mixed $amount): int
    {
        $text = is_float($amount) ? number_format($amount, 2, '.', '') : (string) $amount;
        if (!preg_match('/\A(-?)([0-9]{1,12})(?:\.([0-9]{1,2}))?\z/D', $text, $matches)) throw new \RuntimeException('A financial amount needs review before export.');
        $minor = (int) $matches[2] * 100 + (int) str_pad($matches[3] ?? '', 2, '0');
        return $matches[1] === '-' ? -$minor : $minor;
    }
}
