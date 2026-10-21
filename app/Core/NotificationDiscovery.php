<?php
declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;
use RuntimeException;
use Velvet\Core\MarketingAccess;
use Velvet\Core\MarketingPublication;

/** Reconcile actual committed source records so an interrupted caller cannot lose a notice. */
trait NotificationDiscovery
{
    public function discover(int $limit = 100, ?array $owner = null): array
    {
        if ($limit < 1 || $limit > 250) throw new InvalidArgumentException('Choose a notification discovery limit of 1 to 250.');
        if ($owner !== null) $this->requireOwner($owner);
        $initial = $this->normalized($this->state->read('notifications'));
        $stats = ['queued' => 0, 'examined_members' => 0, 'examined_content_recipients' => 0, 'held' => null];
        if (!$initial['settings']['enabled']) { $stats['held'] = 'Notification automation is paused.'; return $stats; }
        $sources = $this->contentSources();
        $this->mutate(function (array &$data) use ($sources, $owner): void {
            if ($owner !== null) $this->requireOwner($owner);
            if (!$data['settings']['enabled']) return;
            foreach ($sources as $source) {
                $seen = $data['content_seen'][$source['key']] ?? ['live' => false, 'announced' => false];
                if ($source['live'] && !$seen['announced'] && in_array('new_content', $data['settings']['types'], true)) {
                    $data['content_work'][$source['key']] = ['kind' => $source['kind'], 'object_id' => $source['id'],
                        'created_at' => time(), 'member_cursor' => '', 'done' => false];
                    $seen['announced'] = true;
                }
                $seen['live'] = $source['live'];
                $data['content_seen'][$source['key']] = $seen;
            }
            if (count($data['content_seen']) > 20000) throw new RuntimeException('The content-notification index needs archival review before it can grow further.');
        });
        $fresh = new Store($this->config);
        $members = $fresh->members();
        usort($members, static fn(array $a, array $b): int => strcmp((string) $a['id'], (string) $b['id']));
        $dates = $fresh->notificationMemberDates();
        $current = $this->normalized($this->state->read('notifications'));
        $known = array_fill_keys(array_keys($current['jobs']), true);
        $advanced = false;
        $lastCompleted = null;
        foreach ($members as $member) {
            $id = (string) $member['id'];
            if (strcmp($id, $current['member_cursor']) <= 0) continue;
            if ($stats['examined_members'] >= 25) break;
            ++$stats['examined_members'];
            foreach ($this->memberCandidates($member, $fresh, $dates) as [$type, $sourceId, $context]) {
                $job = $this->newJob($type, $sourceId, $id, $context);
                if (isset($known[$job['id']]) || !in_array($type, $current['settings']['types'], true)) continue;
                if ($stats['queued'] >= $limit) return $stats;
                $created = $this->discoveryInsert($job, $owner);
                $stats['queued'] += $created ? 1 : 0;
                $known[$job['id']] = true;
            }
            $this->mutate(function (array &$data) use ($id, $owner): void {
                if ($owner !== null) $this->requireOwner($owner);
                // The cursor is only an optimization; deterministic job identities
                // protect repeated work if concurrent planners move it differently.
                $data['member_cursor'] = $id;
            });
            $advanced = true;
            $lastCompleted = $id;
        }
        if (!$advanced || ($members !== [] && $lastCompleted === (string) end($members)['id'])) {
            $this->mutate(function (array &$data) use ($owner): void { if ($owner !== null) $this->requireOwner($owner); $data['member_cursor'] = ''; });
        }

        $current = $this->normalized($this->state->read('notifications'));
        foreach ($current['content_work'] as $key => $work) {
            if ($work['done'] || !in_array('new_content', $current['settings']['types'], true)) continue;
            $finished = true;
            foreach ($members as $member) {
                $id = (string) $member['id'];
                if (strcmp($id, $work['member_cursor']) <= 0) continue;
                if ($stats['examined_content_recipients'] >= 25 || $stats['queued'] >= $limit) { $finished = false; break; }
                ++$stats['examined_content_recipients'];
                if ($work['created_at'] >= time() - 7 * 86400 && ($member['status'] ?? '') === 'active'
                    && ($member['preferences']['newContentAlerts'] ?? false) === true && !empty($member['emailVerifiedAt'])
                    && $this->contentAllowed($work['kind'], $work['object_id'], $member, $fresh)) {
                    $job = $this->newJob('new_content', $key, $id, ['kind' => $work['kind'], 'object_id' => $work['object_id']]);
                    $stats['queued'] += $this->discoveryInsert($job, $owner) ? 1 : 0;
                }
                $this->mutate(function (array &$data) use ($key, $id, $owner): void {
                    if ($owner !== null) $this->requireOwner($owner);
                    if (isset($data['content_work'][$key])) $data['content_work'][$key]['member_cursor'] = $id;
                });
            }
            if ($finished) $this->mutate(function (array &$data) use ($key, $owner): void {
                if ($owner !== null) $this->requireOwner($owner);
                if (isset($data['content_work'][$key])) $data['content_work'][$key]['done'] = true;
            });
            if ($stats['examined_content_recipients'] >= 25 || $stats['queued'] >= $limit) break;
        }
        return $stats;
    }

    private function discoveryInsert(array $job, ?array $owner): bool
    {
        return $this->mutate(function (array &$data) use ($job, $owner): bool {
            if ($owner !== null) $this->requireOwner($owner);
            if (!$data['settings']['enabled'] || !in_array($job['type'], $data['settings']['types'], true) || isset($data['jobs'][$job['id']])) return false;
            $this->insert($data, $job);
            return true;
        });
    }

    private function memberCandidates(array $member, Store $fresh, array $dates): iterable
    {
        $id = (string) $member['id'];
        $prefs = $member['preferences'] ?? [];
        $created = $fresh->mode() === 'mysql' ? ($dates[$id] ?? 0) : ($this->epoch($member['createdAt'] ?? null) ?? 0);
        if (($member['status'] ?? '') === 'pending' && empty($member['emailVerifiedAt']) && ($prefs['registrationReminder'] ?? false) === true
            && $created <= time() - 86400 && $created >= time() - 7 * 86400) {
            yield ['pending_registration', 'registration:' . $id, []];
        }
        if (($member['status'] ?? '') !== 'active' || empty($member['emailVerifiedAt']) || ($prefs['emailUpdates'] ?? false) !== true) return;
        foreach ($fresh->subscriptions($id) as $subscription) {
            $end = $this->epoch($subscription['currentPeriodEnd'] ?? null, $fresh->mode() === 'mysql');
            if ($end === null) continue;
            $source = 'subscription:' . $subscription['id'] . ':' . $end;
            $context = ['kind' => 'subscription', 'object_id' => (string) $subscription['id'], 'period_end' => (string) $end];
            if (in_array($subscription['status'] ?? '', ['active', 'trialing'], true) && empty($subscription['cancelAtPeriodEnd'])
                && $end > time() && $end <= time() + 7 * 86400) yield ['renewal_reminder', $source, $context];
            if (($subscription['status'] ?? '') === 'past_due' && $end >= time() - 7 * 86400) yield ['payment_failure', $source, $context];
        }
        foreach ($fresh->notificationRecentOrders($id) as $order) {
            if ((string) $order['memberId'] !== $id) continue;
            $date = $fresh->mode() === 'mysql' ? ['created' => $order['_notification_created_epoch'], 'paid' => $this->epoch($order['paidAt'] ?? null, true)] : [
                'created' => $this->epoch($order['createdAt'] ?? null), 'paid' => $this->epoch($order['paidAt'] ?? null)];
            $context = ['kind' => 'order', 'object_id' => (string) $order['id']];
            if (($order['status'] ?? '') === 'paid' && ($order['metadata']['renewal'] ?? false) === true
                && (int) ($date['paid'] ?? 0) >= time() - 7 * 86400 && (int) $date['paid'] <= time()) yield ['renewal', 'order:' . $order['id'], $context];
            if (($order['status'] ?? '') === 'failed' && (int) ($date['created'] ?? 0) >= time() - 7 * 86400
                && (int) $date['created'] <= time()) yield ['payment_failure', 'order:' . $order['id'], $context];
        }
    }

    private function contentSources(): array
    {
        $sources = [];
        foreach ($this->marketing()->publicationRecords('posts') as $post) {
            if (!is_string($post['id'] ?? null)) continue;
            $sources[] = ['key' => 'post:' . $post['id'], 'kind' => 'post', 'id' => $post['id'],
                'live' => empty($post['deleted_at']) && MarketingPublication::visibility('posts', $post)['visible']];
        }
        $media = $this->store->calendarRecords()['media'];
        foreach (['galleries' => 'gallery', 'videos' => 'video', 'tracks' => 'track'] as $collection => $type) {
            foreach ($media[$collection] as $row) {
                if (!is_scalar($row['id'] ?? null)) continue;
                $published = $this->epoch($row['publishedAt'] ?? null, $this->store->mode() === 'mysql');
                $dateValid = ($row['publishedAt'] ?? null) === null || ($row['publishedAt'] ?? '') === '' || $published !== null;
                $sources[] = ['key' => $type . ':' . $row['id'], 'kind' => $type, 'id' => (string) $row['id'],
                    'live' => ($row['status'] ?? '') === 'published' && $dateValid && ($published === null || $published <= time())];
            }
        }
        return $sources;
    }

    private function contentAllowed(string $kind, string $id, array $member, Store $fresh): bool
    {
        if ($kind === 'post') {
            $post = $this->marketing()->publicationRecord('posts', $id);
            if ($post === null || !empty($post['deleted_at']) || !MarketingPublication::visibility('posts', $post)['visible']) return false;
            $available = MarketingAccess::availablePlanIds($fresh);
            $eligible = [];
            foreach ($fresh->subscriptions((string) $member['id']) as $subscription) {
                if (MarketingAccess::eligibleSubscription($subscription, time(), $fresh->mode() === 'mysql')
                    && in_array((string) $subscription['planId'], $available, true)) $eligible[] = (string) $subscription['planId'];
            }
            return MarketingAccess::allows($post, ['member' => true, 'plan_ids' => array_values(array_unique($eligible)), 'available_plan_ids' => $available]);
        }
        $resolver = new EntitlementResolver($fresh);
        $content = $resolver->content($kind, $id);
        if ($content === null || !empty($content['deletedAt']) || ($content['status'] ?? '') !== 'published'
            || !in_array($content['accessMode'] ?? 'public', ['public', 'registered', 'subscription', 'ppv', 'rental', 'bundle', 'complimentary'], true)) return false;
        $published = $this->epoch($content['publishedAt'] ?? null, $fresh->mode() === 'mysql');
        if (($content['publishedAt'] ?? null) !== null && ($content['publishedAt'] ?? '') !== '' && $published === null) return false;
        if ($published !== null && $published > time()) return false;
        $decision = $resolver->resolve($kind, $content, $member);
        if (!$decision['allowed']) return false;
        if (($decision['source'] ?? '') === 'subscription') {
            $required = $fresh->contentPlanIds($kind, $id);
            $available = MarketingAccess::availablePlanIds($fresh);
            foreach ($fresh->subscriptions((string) $member['id']) as $subscription) {
                $planId = (string) $subscription['planId'];
                if (in_array($planId, $available, true) && ($required === [] || in_array($planId, $required, true))
                    && MarketingAccess::eligibleSubscription($subscription, time(), $fresh->mode() === 'mysql')) return true;
            }
            return false;
        }
        $expires = $decision['expiresAt'] ?? null;
        return $expires === null || (($this->epoch($expires, $fresh->mode() === 'mysql') ?? 0) > time());
    }
}
