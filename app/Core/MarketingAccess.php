<?php
declare(strict_types=1);

namespace Velvet\Core;

use App\Core\Store;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Editorial policies are independent of media entitlements and newsletter audiences. */
final class MarketingAccess
{
    public const MAX_PLAN_IDS = 100;

    /** @return array{version:int,mode:string,plan_ids:list<string>} */
    public static function validate(mixed $policy): array
    {
        if (!is_array($policy) || count($policy) !== 3
            || array_diff(array_keys($policy), ['version', 'mode', 'plan_ids']) !== []
            || ($policy['version'] ?? null) !== 1
            || !in_array($policy['mode'] ?? null, ['public', 'members', 'tiers'], true)) {
            throw new InvalidArgumentException('Choose a valid publication access policy.');
        }
        $ids = self::ids($policy['plan_ids'] ?? null, true);
        if (($policy['mode'] === 'tiers') !== ($ids !== [])) {
            throw new InvalidArgumentException('Select at least one tier for tier access, or clear the tiers for another access mode.');
        }
        return ['version' => 1, 'mode' => $policy['mode'], 'plan_ids' => $ids];
    }

    /** Absence alone preserves public legacy behavior; an explicit null is invalid. */
    public static function policy(array $record): array
    {
        return array_key_exists('access', $record) ? self::validate($record['access'])
            : ['version' => 1, 'mode' => 'public', 'plan_ids' => []];
    }

    /** Safe owner presentation; malformed values are never echoed or made public. */
    public static function describe(array $record, ?array $availablePlanIds = null): array
    {
        $result = ['valid' => false, 'legacy' => !array_key_exists('access', $record),
            'mode' => '', 'plan_ids' => [], 'problem' => null];
        try {
            $policy = self::policy($record);
            $result['mode'] = $policy['mode'];
            $result['plan_ids'] = $policy['plan_ids'];
            if ($availablePlanIds !== null) self::validateAvailable($policy, $availablePlanIds);
            $result['valid'] = true;
        } catch (InvalidArgumentException $error) {
            $result['problem'] = $error->getMessage();
        }
        return $result;
    }

    /** All selected tiers must remain valid; a surviving match cannot hide a broken rule. */
    public static function validateAvailable(array $policy, array $availablePlanIds): array
    {
        $policy = self::validate($policy);
        $available = self::ids($availablePlanIds);
        if ($policy['mode'] === 'tiers' && array_diff($policy['plan_ids'], $available) !== []) {
            throw new InvalidArgumentException('One or more selected membership tiers are unavailable. Choose available tiers before saving.');
        }
        return $policy;
    }

    /** @return array{member:bool,plan_ids:list<string>,available_plan_ids:list<string>} */
    public static function guest(): array
    {
        return ['member' => false, 'plan_ids' => [], 'available_plan_ids' => []];
    }

    /**
     * The optional viewer is trusted server state, never request form/query data.
     * Public readers default to guests; owner preview uses its separate guarded route.
     */
    public static function allows(array $record, ?array $viewer = null): bool
    {
        try {
            $policy = self::policy($record);
            if ($policy['mode'] === 'public') return true;
            if (($viewer['member'] ?? null) !== true) return false;
            if ($policy['mode'] === 'members') return true;
            $available = self::ids($viewer['available_plan_ids'] ?? null);
            self::validateAvailable($policy, $available);
            $eligible = self::ids($viewer['plan_ids'] ?? null);
            return array_intersect($policy['plan_ids'], $eligible) !== [];
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /** Exact enabled IDs only: membershipPlan() also accepts keys and is unsuitable here. */
    public static function availablePlanIds(Store $store): array
    {
        try {
            $seen = [];
            $available = [];
            foreach ($store->membershipPlans(false) as $plan) {
                if (!is_array($plan) || !self::validId($plan['id'] ?? null)) continue;
                $id = $plan['id'];
                // Prefix keys so PHP cannot turn a numeric string into an integer.
                $key = 'id:' . $id;
                if (isset($seen[$key])) {
                    unset($available[$key]);
                    continue;
                }
                $seen[$key] = true;
                if (!in_array($plan['isActive'] ?? null, [true, 1, '1'], true)
                    || !is_string($plan['name'] ?? null) || trim($plan['name']) === ''
                    || !in_array($plan['deletedAt'] ?? null, [null, ''], true)) continue;
                $available[$key] = $id;
            }
            return array_values($available);
        } catch (Throwable $error) {
            throw new RuntimeException('Membership tiers could not be checked. Reload and try again.', 0, $error);
        }
    }

    /**
     * Resolve the persisted device, member, plans and subscriptions afresh.
     * No cached MemberAuth decision, heartbeat, session change or event write occurs.
     * $session, when supplied by server code, has the existing member-session id/token shape.
     */
    public static function viewer(Store $store, ?array $session = null): array
    {
        $guest = self::guest();
        $session ??= is_array($_SESSION['member'] ?? null) ? $_SESSION['member'] : [];
        $id = $session['id'] ?? null;
        $token = $session['token'] ?? null;
        if (!self::validId($id) || !is_string($token) || $token === '' || strlen($token) > 256) return $guest;
        try {
            $databaseDates = $store->mode() === 'mysql';
            $device = $store->memberSession($token);
            if (!is_array($device) || ($device['memberId'] ?? null) !== $id
                || !is_string($device['tokenHash'] ?? null) || !hash_equals(hash('sha256', $token), $device['tokenHash'])
                || !in_array($device['revokedAt'] ?? null, [null, ''], true)) return $guest;
            $member = $store->member($id);
            $now = time();
            if (!is_array($member) || ($member['id'] ?? null) !== $id || ($member['status'] ?? null) !== 'active'
                || !in_array($member['deletedAt'] ?? null, [null, ''], true)) return $guest;
            $verified = self::timestamp($member['emailVerifiedAt'] ?? null, $databaseDates);
            // MySQL converts verification's explicit ISO offset into its connection
            // timezone. Validate the saved date here, but compare its chronology there.
            if ($verified === null || ($databaseDates ? !$store->databaseMemberVerificationIsCurrent($id) : $verified > $now)) return $guest;
            $available = self::availablePlanIds($store);
            $eligible = [];
            // The older activeSubscriptions() prefilter applies site-local strtotime()
            // to UTC SQL DATETIME values, so only this strict reader selects eligibility.
            foreach ($store->subscriptions($id) as $subscription) {
                if (!is_array($subscription) || ($subscription['memberId'] ?? null) !== $id
                    || !self::eligibleSubscription($subscription, $now, $databaseDates)) continue;
                $planId = $subscription['planId'];
                if (in_array($planId, $available, true) && !in_array($planId, $eligible, true)) $eligible[] = $planId;
            }
            return ['member' => true, 'plan_ids' => $eligible, 'available_plan_ids' => $available];
        } catch (Throwable) {
            // Membership/catalog failures may deny restricted content, never grant it.
            return $guest;
        }
    }

    /** Current subscription semantics, with malformed nonempty bounds held closed. */
    public static function eligibleSubscription(array $subscription, ?int $now = null, bool $databaseDates = false): bool
    {
        $now ??= time();
        if (!self::validId($subscription['planId'] ?? null)
            || !in_array($subscription['status'] ?? null, ['trialing', 'active', 'past_due'], true)) return false;
        try {
            $start = self::timestamp($subscription['currentPeriodStart'] ?? null, $databaseDates);
            $end = self::timestamp($subscription['currentPeriodEnd'] ?? null, $databaseDates);
            $grace = self::timestamp($subscription['graceEndsAt'] ?? null, $databaseDates);
            if (($start !== null && $start > $now) || ($start !== null && $end !== null && $end <= $start)) return false;
            if ($subscription['status'] === 'past_due') {
                return $grace !== null && $grace > $now;
            }
            return $end === null || $end > $now;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    private static function timestamp(mixed $value, bool $databaseDates = false): ?int
    {
        // MemberStore writes SQL subscription bounds as UTC without an offset. File records keep
        // explicit ISO offsets or their legacy site-local format. Never change the
        // global timezone or the separate publication scheduling interpretation.
        if ($databaseDates && is_string($value)
            && preg_match('/\A\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2})?)?\z/D', $value)) {
            $value = str_replace(' ', 'T', $value) . (strlen($value) === 10 ? 'T00:00:00' : '') . 'Z';
        }
        require_once __DIR__ . '/MarketingPublication.php';
        return MarketingPublication::storedDate($value)?->getTimestamp();
    }

    private static function validId(mixed $id): bool
    {
        return is_string($id) && preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,79}\z/D', $id) === 1;
    }

    /** @return list<string> */
    private static function ids(mixed $values, bool $bounded = false): array
    {
        if (!is_array($values) || !array_is_list($values) || ($bounded && count($values) > self::MAX_PLAN_IDS)) {
            throw new InvalidArgumentException('Choose a list of at most ' . self::MAX_PLAN_IDS . ' membership tiers.');
        }
        $seen = [];
        foreach ($values as $id) {
            if (!self::validId($id) || isset($seen['id:' . $id])) {
                throw new InvalidArgumentException('Choose each membership tier once using its saved identity.');
            }
            $seen['id:' . $id] = true;
        }
        return $values;
    }
}
