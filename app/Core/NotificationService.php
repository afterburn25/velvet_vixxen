<?php
declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use Velvet\Core\CampaignMessage;
use Velvet\Core\MarketingAccess;
use Velvet\Core\MarketingPublication;
use Velvet\Core\MarketingStore;

require_once __DIR__ . '/MarketingStore.php';

/** Durable, consent-aware notices. Network work never holds a state transaction. */
final class NotificationService
{
    use NotificationDiscovery;
    use NotificationProviderEvents;

    public const TYPES = ['new_content', 'renewal_reminder', 'renewal', 'payment_failure', 'pending_registration', 'owner_security'];
    private readonly PrivateState $state;
    private readonly MailService $mailer;
    private ?MarketingStore $marketing = null;
    private readonly string $root;

    public function __construct(private readonly Config $config, private readonly Store $store, ?MailService $mailer = null, ?PrivateState $state = null)
    {
        $this->root = dirname($config->path(), 2);
        $this->state = $state ?? new PrivateState($config, $this->root);
        $this->mailer = $mailer ?? new MailService($config, $store);
    }

    /** Complete account-specific rows, only for the privacy projector and inventory revision. */
    public function privacyRows(string $memberId): array
    {
        $data = $this->normalized($this->state->read('notifications'));
        return array_values(array_filter($data['jobs'], static fn(array $row): bool => ($row['member_id'] ?? null) === $memberId));
    }

    public function privacyForget(string $memberId, callable $authorize): void
    {
        $this->mutate(static function (array &$data) use ($memberId, $authorize): void {
            $authorize(); $hash = hash('sha256', $memberId);
            $data['erased_members'][$hash] ??= time();
            foreach ($data['jobs'] as $id => $job) if (($job['member_id'] ?? null) === $memberId) unset($data['jobs'][$id]);
            $authorize();
        });
    }

    public function overview(): array
    {
        $data = $this->normalized($this->state->read('notifications'));
        $counts = array_fill_keys(['queued', 'claimed', 'sending', 'sent', 'failed', 'unknown', 'skipped', 'canceled'], 0);
        foreach ($data['jobs'] as $job) ++$counts[$job['status']];
        $jobs = array_values($data['jobs']);
        usort($jobs, static fn(array $a, array $b): int => $b['created_at'] <=> $a['created_at']);
        $public = array_map(static function (array $job): array {
            $job['revision'] = self::jobRevision($job);
            unset($job['claim'], $job['owner_email'], $job['recipient_hash'], $job['email_hash']);
            return $job;
        }, array_slice($jobs, 0, 100));
        return ['settings' => $data['settings'], 'revision' => $this->settingsRevision($data['settings']),
            'counts' => $counts, 'jobs' => $public, 'total' => count($jobs),
            'pending_content' => count(array_filter($data['content_work'], static fn(array $row): bool => empty($row['done'])))];
    }

    public function saveSettings(array $input, string $expectedRevision, array $owner): array
    {
        if (array_diff(array_keys($input), ['enabled', 'types']) !== [] || !is_bool($input['enabled'] ?? null)
            || !is_array($input['types'] ?? null) || !array_is_list($input['types'])
            || count(array_filter($input['types'], 'is_string')) !== count($input['types'])
            || array_diff($input['types'], self::TYPES) !== [] || count(array_unique($input['types'])) !== count($input['types'])) {
            throw new InvalidArgumentException('Choose valid notification settings.');
        }
        $sources = $this->contentSources();
        return $this->mutate(function (array &$data) use ($input, $expectedRevision, $owner, $sources): array {
            $this->requireOwner($owner);
            if (!hash_equals($this->settingsRevision($data['settings']), $expectedRevision)) throw new RuntimeException('Notification settings changed. Reload before saving.', 409);
            $firstEnable = $input['enabled'] && $data['settings']['enabled_at'] === null;
            $data['settings'] = ['enabled' => $input['enabled'], 'types' => $input['types'],
                'enabled_at' => $firstEnable ? time() : $data['settings']['enabled_at'], 'revision' => $data['settings']['revision'] + 1];
            if ($firstEnable) {
                // Enabling notices does not broadcast an installation's old catalog.
                foreach ($sources as $source) $data['content_seen'][$source['key']] = ['live' => $source['live'], 'announced' => $source['live']];
            }
            return $data['settings'];
        });
    }

    /** Trusted application API: fixed templates only, never a caller-supplied email body. */
    public function enqueue(string $type, string $sourceId, ?string $memberId, array $safeContext = []): array
    {
        $job = $this->newJob($type, $sourceId, $memberId, $safeContext);
        return $this->mutate(function (array &$data) use ($job): array { return $this->insert($data, $job); });
    }

    public function enqueueOwnerAlert(string $type, string $sourceId, array $safeContext = []): array
    {
        if (!in_array($type, ['suspicious_admin_login', 'admin_recovery'], true)
            || array_diff(array_keys($safeContext), ['when', 'url']) !== []) throw new InvalidArgumentException('Choose a supported owner security notice.');
        $url = $safeContext['url'] ?? 'admin/';
        if (!is_string($url) || preg_match('/\Aadmin\/(?:\?page=[a-z][a-z0-9-]{0,60})?\z/D', $url) !== 1) throw new InvalidArgumentException('Choose an owner security page.');
        $when = $safeContext['when'] ?? gmdate('c');
        if (!is_string($when) || $this->epoch($when) === null) throw new InvalidArgumentException('Choose a valid security event time.');
        $queued = [];
        foreach ((new Store($this->config))->notificationOwners() as $owner) {
            $context = ['kind' => $type, 'owner_id' => $owner['id'], 'owner_email' => $owner['email'], 'url' => $url, 'event_at' => $when];
            $queued[] = $this->enqueue('owner_security', $sourceId, null, $context);
        }
        return $queued;
    }

    public function cancel(string $id, string $revision, array $owner): void
    {
        $this->mutate(function (array &$data) use ($id, $revision, $owner): void {
            $this->requireOwner($owner);
            $job = $data['jobs'][$id] ?? null;
            if (!is_array($job) || !hash_equals($this->jobRevision($job), $revision)) throw new RuntimeException('This notification changed. Reload before canceling.', 409);
            if (!in_array($job['status'], ['queued', 'claimed', 'failed'], true)) throw new RuntimeException('This outcome cannot be recalled or canceled.', 409);
            $data['jobs'][$id]['status'] = 'canceled';
            $data['jobs'][$id]['claim'] = null;
            $data['jobs'][$id]['last_error'] = 'Canceled by the owner before transmission.';
            $data['jobs'][$id]['updated_at'] = time();
        });
    }

    public function retry(string $id, string $revision, array $owner): void
    {
        $this->mutate(function (array &$data) use ($id, $revision, $owner): void {
            $this->requireOwner($owner);
            $job = $data['jobs'][$id] ?? null;
            if (!is_array($job) || !hash_equals($this->jobRevision($job), $revision)) throw new RuntimeException('This notification changed. Reload before retrying.', 409);
            if ($job['status'] !== 'failed' || !$job['retryable'] || $job['attempts'] >= 3 || $job['next_attempt_at'] > time()) {
                throw new RuntimeException('Only definitely failed, retryable notices below three attempts can be retried after one minute.', 409);
            }
            $data['jobs'][$id]['status'] = 'queued';
            $data['jobs'][$id]['updated_at'] = time();
        });
    }

    public function run(int $limit = 10, ?array $owner = null): array
    {
        if ($limit < 1 || $limit > 25) throw new InvalidArgumentException('Choose a notification batch of 1 to 25.');
        if ($owner !== null) $this->requireOwner($owner);
        $this->config->reload();
        $stats = ['processed' => 0, 'accepted' => 0, 'failed' => 0, 'unknown' => 0, 'skipped' => 0, 'recovered' => 0, 'held' => null];
        if (($problem = $this->mailer->configurationProblem()) !== null) { $stats['held'] = $problem; return $stats; }
        try { CampaignMessage::baseUrl((string) $this->config->get('base_url', '')); }
        catch (Throwable) { $stats['held'] = 'Configure a valid site URL before sending notifications.'; return $stats; }
        $configRevision = $this->transportRevision();
        for ($count = 0; $count < $limit; ++$count) {
            $claim = $this->claim($owner);
            $stats['recovered'] += $claim['recovered'];
            if ($claim['held'] !== null) { $stats['held'] = $claim['held']; break; }
            $job = $claim['job'];
            if ($job === null) break;
            ++$stats['processed'];
            try {
                $eligible = $this->eligible($job);
                if ($eligible === null) {
                    $outcome = ['status' => 'skipped', 'lastError' => 'The source, recipient, consent or suppression state no longer permits this notice.', 'retryable' => false];
                } else {
                    $message = $this->render($job, $owner);
                    $outcome = $this->mailer->deliver($eligible['email'], $message['subject'], $message['text'],
                        fn(): bool => $this->authorize($job['id'], $job['claim']['token'], $configRevision, $owner),
                        ['message_id' => $job['message_id']]);
                }
            } catch (Throwable) {
                $outcome = ['status' => 'unknown', 'lastError' => 'The notification operation was interrupted. Review its durable outcome before taking further action.', 'retryable' => false];
            }
            $finished = $this->finish($job['id'], $job['claim']['token'], $outcome);
            if ($finished['status'] === 'sent') ++$stats['accepted'];
            elseif (isset($stats[$finished['status']])) ++$stats[$finished['status']];
            else $stats['held'] = 'A notification was held before transmission.';
        }
        return $stats;
    }

    private function claim(?array $owner): array
    {
        return $this->mutate(function (array &$data) use ($owner): array {
            if ($owner !== null) $this->requireOwner($owner);
            $now = time(); $recovered = 0;
            foreach ($data['jobs'] as &$job) {
                if (in_array($job['status'], ['claimed', 'sending'], true) && $job['claim']['until'] <= $now) {
                    if ($job['status'] === 'claimed') $job['attempts'] = max(0, $job['attempts'] - 1);
                    $job['status'] = $job['status'] === 'sending' ? 'unknown' : 'queued';
                    $job['last_error'] = $job['status'] === 'unknown' ? 'An interrupted transmission has an unknown outcome; automatic retry is blocked.' : 'An interrupted claim was recovered before body transmission.';
                    $job['claim'] = null; $job['updated_at'] = $now; ++$recovered;
                }
            }
            unset($job);
            if (!$data['settings']['enabled']) return ['job' => null, 'held' => 'Notification automation is paused.', 'recovered' => $recovered];
            foreach ($data['jobs'] as &$job) {
                if ($job['status'] !== 'queued' || $job['due_at'] > $now || !in_array($job['type'], $data['settings']['types'], true)) continue;
                if ($job['attempts'] >= 3) { $job['status'] = 'failed'; $job['retryable'] = false; $job['last_error'] = 'The maximum number of delivery attempts was reached.'; continue; }
                $job['status'] = 'claimed';
                $job['claim'] = ['token' => bin2hex(random_bytes(24)), 'until' => $now + 300];
                ++$job['attempts']; $job['updated_at'] = $now;
                return ['job' => $job, 'held' => null, 'recovered' => $recovered];
            }
            return ['job' => null, 'held' => null, 'recovered' => $recovered];
        });
    }

    private function authorize(string $id, string $token, string $configRevision, ?array $owner): bool
    {
        return $this->mutate(function (array &$data) use ($id, $token, $configRevision, $owner): bool {
            if (!isset($data['jobs'][$id])) return false;
            $job = &$data['jobs'][$id];
            if ($job['status'] !== 'claimed' || !hash_equals((string) ($job['claim']['token'] ?? ''), $token)) return false;
            $this->config->reload();
            try {
                if ($owner !== null) $this->requireOwner($owner);
                if (!$data['settings']['enabled'] || !in_array($job['type'], $data['settings']['types'], true)
                    || !hash_equals($configRevision, $this->transportRevision()) || $this->mailer->configurationProblem() !== null) {
                    throw new RuntimeException('Notification delivery settings changed.');
                }
            } catch (Throwable) {
                $job['status'] = 'queued'; $job['claim'] = null; $job['updated_at'] = time();
                $job['attempts'] = max(0, $job['attempts'] - 1);
                $job['last_error'] = 'Delivery was paused before transmission because settings or owner authorization changed.';
                return false;
            }
            $recipient = $this->eligible($job);
            if ($job['claim']['until'] <= time() || $recipient === null) {
                $job['status'] = 'skipped'; $job['claim'] = null; $job['updated_at'] = time();
                $job['last_error'] = 'The current source, recipient or consent no longer permits this notice.';
                return false;
            }
            // Eligibility can wait on source/member/marketing storage. Recheck
            // the actor and transport after those dependent reads, before DATA.
            $this->config->reload();
            try {
                if ($owner !== null) $this->requireOwner($owner);
                if (!hash_equals($configRevision, $this->transportRevision()) || $this->mailer->configurationProblem() !== null) throw new RuntimeException('Notification settings changed.');
            } catch (Throwable) {
                $job['status'] = 'queued'; $job['claim'] = null; $job['updated_at'] = time();
                $job['attempts'] = max(0, $job['attempts'] - 1);
                $job['last_error'] = 'Delivery was paused before transmission because settings or owner authorization changed.';
                return false;
            }
            $job['status'] = 'sending';
            $job['recipient_hash'] = hash('sha256', $recipient['email']);
            $job['updated_at'] = time();
            return true;
        });
    }

    private function finish(string $id, string $token, array $outcome): array
    {
        return $this->mutate(function (array &$data) use ($id, $token, $outcome): array {
            if (!isset($data['jobs'][$id])) throw new RuntimeException('The notification claim is unavailable. Review the provider before repeating the operation.');
            $job = &$data['jobs'][$id];
            if (!in_array($job['status'], ['claimed', 'sending'], true) || !hash_equals((string) ($job['claim']['token'] ?? ''), $token)) return $job;
            $status = $outcome['status'] ?? 'unknown';
            if (!in_array($status, ['sent', 'failed', 'unknown', 'skipped'], true)) $status = 'unknown';
            if ($status === 'unknown' && $job['status'] === 'claimed') $status = 'failed';
            $job['status'] = $status; $job['claim'] = null;
            $job['retryable'] = $status === 'failed' && ($outcome['retryable'] ?? false) === true;
            $job['next_attempt_at'] = time() + 60; $job['updated_at'] = time();
            $job['last_error'] = is_string($outcome['lastError'] ?? null) ? substr($outcome['lastError'], 0, 500) : null;
            return $job;
        });
    }

    private function eligible(array $job): ?array
    {
        if ($job['created_at'] < time() - 7 * 86400) return null;
        $fresh = new Store($this->config);
        $recipient = $this->currentRecipient($job, $fresh);
        if ($recipient === null) return null;
        if (!in_array($job['type'], ['pending_registration', 'owner_security'], true) && !$this->sourceCurrent($job, $recipient, $fresh)) return null;
        $email = EmailAddress::normalize((string) ($recipient['email'] ?? ''));
        if (!EmailAddress::isValid($email) || !hash_equals($job['email_hash'], hash('sha256', $email)) || $this->marketing()->emailSuppressed($email)) return null;
        // Source and suppression reads can wait. Recipient status and explicit
        // preferences must still permit the notice after those dependent reads.
        $latest = $this->currentRecipient($job, new Store($this->config));
        if ($latest === null || EmailAddress::normalize((string) ($latest['email'] ?? '')) !== $email) return null;
        return ['email' => $email];
    }

    private function currentRecipient(array $job, Store $fresh): ?array
    {
        if ($job['type'] === 'owner_security') {
            $recipient = $fresh->findAdminByEmail($job['owner_email']);
            if (!$recipient || empty($recipient['is_active']) || ($recipient['role'] ?? '') !== 'owner' || (string) $recipient['id'] !== $job['owner_id']) return null;
        } else {
            $recipient = $fresh->member($job['member_id']);
            if (!$recipient || (string) $recipient['id'] !== $job['member_id'] || !empty($recipient['deletedAt'])) return null;
            $prefs = $recipient['preferences'] ?? [];
            if ($job['type'] === 'pending_registration') {
                $created = $fresh->mode() === 'mysql' ? ($fresh->notificationMemberDates()[$job['member_id']] ?? 0) : ($this->epoch($recipient['createdAt'] ?? null) ?? 0);
                if (($recipient['status'] ?? '') !== 'pending' || !empty($recipient['emailVerifiedAt'])
                    || ($prefs['registrationReminder'] ?? false) !== true || $created > time() - 86400 || $created < time() - 7 * 86400) return null;
            } else {
                if (($recipient['status'] ?? '') !== 'active' || empty($recipient['emailVerifiedAt'])
                    || ($fresh->mode() === 'mysql' ? !$fresh->databaseMemberVerificationIsCurrent($job['member_id'])
                        : (($this->epoch($recipient['emailVerifiedAt']) ?? PHP_INT_MAX) > time()))) return null;
                if (($prefs[$job['type'] === 'new_content' ? 'newContentAlerts' : 'emailUpdates'] ?? false) !== true) return null;
            }
        }
        return $recipient;
    }

    private function sourceCurrent(array $job, array $member, Store $fresh): bool
    {
        $context = $job['context'];
        if ($job['type'] === 'new_content') return $this->contentAllowed($context['kind'], $context['object_id'], $member, $fresh);
        if ($context['kind'] === 'subscription') {
            $source = null;
            foreach ($fresh->subscriptions($job['member_id']) as $row) if ((string) $row['id'] === $context['object_id']) $source = $row;
            if ($source === null || (string) ($this->epoch($source['currentPeriodEnd'] ?? null, $fresh->mode() === 'mysql') ?? 0) !== $context['period_end']) return false;
            if ($job['type'] === 'payment_failure') return ($source['status'] ?? '') === 'past_due';
            $end = (int) $context['period_end'];
            return $job['type'] === 'renewal_reminder' && in_array($source['status'] ?? '', ['active', 'trialing'], true)
                && empty($source['cancelAtPeriodEnd']) && $end > time() && $end <= time() + 7 * 86400;
        }
        $order = $fresh->notificationOrder($context['object_id'], $job['member_id']);
        return $order !== null && (string) $order['memberId'] === $job['member_id']
            && ($job['type'] === 'renewal' ? (($order['status'] ?? '') === 'paid' && ($order['metadata']['renewal'] ?? false) === true)
                : ($job['type'] === 'payment_failure' && ($order['status'] ?? '') === 'failed'));
    }

    private function render(array $job, ?array $owner = null): array
    {
        [$subject, $body, $target] = match ($job['type']) {
            'new_content' => ['New content is available', 'New content you can access is available. Sign in to explore the site.', 'account/'],
            'renewal_reminder' => ['Your membership period is ending soon', 'Your membership period ends within seven days. Sign in to review your current billing status and renewal options.', 'account/billing.php'],
            'renewal' => ['Your membership renewal is recorded', 'A membership renewal has been recorded for your account. Sign in to review its current status and receipt.', 'account/billing.php'],
            'payment_failure' => ['Your account billing needs attention', 'A payment or renewal could not be completed. Sign in to review the current billing status before trying another payment.', 'account/billing.php'],
            'pending_registration' => ['Finish creating your account', 'You asked for one reminder if your account was not confirmed. Open the fresh verification link below to finish creating your account. This link expires in 24 hours. If you no longer want to join, you can ignore this message; no further registration reminders will be sent.', 'account/verify.php'],
            'owner_security' => ['Review an administrator security event', 'A security event requires your review. Sign in to the administrator area to review the current security activity.', $job['context']['url']],
        };
        $base = CampaignMessage::baseUrl((string) $this->config->get('base_url', ''));
        if ($job['type'] === 'pending_registration') {
            $target .= '?token=' . rawurlencode($this->registrationToken($job, $owner));
        }
        $text = $body . "\n\n" . \vvx_public_destination($base, '/' . $target);
        if (!in_array($job['type'], ['owner_security', 'pending_registration'], true)) {
            $text .= "\n\nManage optional notification preferences: " . \vvx_public_destination($base, '/account/security.php');
        }
        if (in_array($job['type'], ['renewal', 'renewal_reminder', 'payment_failure'], true)) {
            $text .= "\n\nIf this installation is using a sandbox payment provider, its billing activity is a test and does not represent a live charge.";
        }
        return ['subject' => $subject, 'text' => $text];
    }

    private function registrationToken(array $claim, ?array $owner): string
    {
        $token = $this->mutate(function (array &$data) use ($claim, $owner): ?string {
            if (!isset($data['jobs'][$claim['id']])) throw new RuntimeException('The registration reminder claim is unavailable.', 409);
            $job = &$data['jobs'][$claim['id']];
            if (!is_array($job) || $job['status'] !== 'claimed' || $job['claim']['until'] <= time()
                || !is_string($claim['claim']['token'] ?? null) || !hash_equals($job['claim']['token'], $claim['claim']['token'])) {
                throw new RuntimeException('The registration reminder claim changed before token issuance.', 409);
            }
            if ($this->eligible($job) === null) {
                $job['status'] = 'skipped'; $job['claim'] = null; $job['updated_at'] = time();
                $job['last_error'] = 'The current recipient no longer permits this registration reminder.';
                return null;
            }
            try { if ($owner !== null) $this->requireOwner($owner); }
            catch (Throwable) {
                $job['status'] = 'queued'; $job['claim'] = null; $job['updated_at'] = time();
                $job['attempts'] = max(0, $job['attempts'] - 1);
                $job['last_error'] = 'The owner authorization changed before token issuance.';
                return null;
            }
            if ($job['claim']['until'] <= time()) throw new RuntimeException('The registration reminder lease expired before token issuance.', 409);
            // Hold the live claim lock while rotating member tokens. A stale
            // worker cannot invalidate a verification link sent by its successor.
            // The raw token is returned in memory and never enters journal data.
            return (new Store($this->config))->createMemberToken($job['member_id'], 'email_verification', 86400)['token'];
        });
        if ($token === null) throw new RuntimeException('The registration reminder was held before token issuance.');
        return $token;
    }

    private function newJob(string $type, string $sourceId, ?string $memberId, array $context): array
    {
        if (!in_array($type, self::TYPES, true) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9:._-]{0,189}\z/D', $sourceId) !== 1
            || ($memberId !== null && preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,79}\z/D', $memberId) !== 1)) throw new InvalidArgumentException('Choose a valid notification source and recipient.');
        $allowed = $type === 'owner_security' ? ['kind', 'owner_id', 'owner_email', 'url', 'event_at']
            : ($type === 'pending_registration' ? [] : ['kind', 'object_id', 'period_end']);
        if (array_diff(array_keys($context), $allowed) !== []) throw new InvalidArgumentException('Notification context must contain only safe source metadata.');
        foreach ($context as $value) if (!is_string($value) || strlen($value) > 250 || preg_match('/[\x00-\x1f\x7f]/', $value)) throw new InvalidArgumentException('Notification context contains an invalid value.');
        if ($type === 'owner_security') {
            if ($memberId !== null || !isset($context['owner_id'], $context['owner_email'], $context['url'], $context['kind'], $context['event_at'])) throw new InvalidArgumentException('A current owner security recipient is required.');
            if (!in_array($context['kind'], ['suspicious_admin_login', 'admin_recovery'], true)
                || preg_match('/\Aadmin\/(?:\?page=[a-z][a-z0-9-]{0,60})?\z/D', $context['url']) !== 1
                || $this->epoch($context['event_at']) === null) throw new InvalidArgumentException('Choose safe owner security metadata.');
            $recipient = (new Store($this->config))->findAdminByEmail($context['owner_email']);
            if (!$recipient || (string) $recipient['id'] !== $context['owner_id'] || empty($recipient['is_active']) || ($recipient['role'] ?? '') !== 'owner') throw new InvalidArgumentException('A current owner security recipient is required.');
        } else {
            if ($memberId === null || !($recipient = (new Store($this->config))->member($memberId))) throw new InvalidArgumentException('A current member recipient is required.');
            if ($type !== 'pending_registration' && (!isset($context['kind'], $context['object_id'])
                || preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,79}\z/D', $context['object_id']) !== 1)) throw new InvalidArgumentException('A saved notification source is required.');
            $kinds = match ($type) { 'new_content' => ['post', 'gallery', 'video', 'track'], 'renewal_reminder' => ['subscription'], 'renewal' => ['order'], 'payment_failure' => ['order', 'subscription'], default => [] };
            if ($type !== 'pending_registration' && !in_array($context['kind'], $kinds, true)) throw new InvalidArgumentException('Choose a supported notification source.');
            if (($context['kind'] ?? '') === 'subscription' && preg_match('/\A[0-9]{1,12}\z/D', $context['period_end'] ?? '') !== 1) throw new InvalidArgumentException('A saved subscription period is required.');
            $canonicalSource = $type === 'pending_registration' ? 'registration:' . $memberId
                : $context['kind'] . ':' . $context['object_id'] . (($context['kind'] ?? '') === 'subscription' ? ':' . $context['period_end'] : '');
            if ($sourceId !== $canonicalSource) throw new InvalidArgumentException('The notification identity must match its saved source.');
        }
        $identity = $memberId ?? ('owner:' . $context['owner_id']);
        $id = hash('sha256', $type . '|' . $sourceId . '|' . $identity);
        $due = time();
        if ($type === 'pending_registration') {
            $created = $this->store->mode() === 'mysql' ? ($this->store->notificationMemberDates()[$memberId] ?? 0) : ($this->epoch($recipient['createdAt'] ?? null) ?? 0);
            $due = max($due, $created + 86400);
        } elseif ($type === 'renewal_reminder') $due = max($due, (int) $context['period_end'] - 7 * 86400);
        return ['id' => $id, 'type' => $type, 'source_id' => $sourceId, 'member_id' => $memberId,
            'owner_id' => $context['owner_id'] ?? null, 'owner_email' => $context['owner_email'] ?? null,
            'email_hash' => hash('sha256', EmailAddress::normalize((string) $recipient['email'])), 'recipient_hash' => null,
            'context' => array_diff_key($context, ['owner_id' => true, 'owner_email' => true]), 'status' => 'queued',
            'created_at' => time(), 'updated_at' => time(), 'due_at' => $due, 'next_attempt_at' => 0,
            'claim' => null, 'attempts' => 0, 'retryable' => false, 'last_error' => null,
            'message_id' => CampaignMessage::messageId('notification', $id)];
    }

    private function insert(array &$data, array $job): array
    {
        if ($job['member_id'] !== null && (isset($data['erased_members'][hash('sha256', $job['member_id'])])
            || (new Store($this->config))->privacyMemberErased($job['member_id']))) throw new RuntimeException('This account is closed for optional notifications.', 409);
        if (isset($data['jobs'][$job['id']])) return $data['jobs'][$job['id']];
        if (count($data['jobs']) >= 20000) throw new RuntimeException('The notification journal is full. Review and archive completed entries before adding work.');
        $data['jobs'][$job['id']] = $job;
        return $job;
    }

    public static function jobRevision(array $job): string
    {
        unset($job['revision']);
        return hash('sha256', json_encode($job, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function settingsRevision(array $settings): string { return hash('sha256', json_encode($settings, JSON_THROW_ON_ERROR)); }
    private function transportRevision(): string { return hash('sha256', json_encode([$this->config->get('mail'), $this->config->get('base_url')], JSON_THROW_ON_ERROR)); }
    private function marketing(): MarketingStore { return $this->marketing ??= new MarketingStore($this->root); }

    private function requireOwner(array $identity): void
    {
        $email = $identity['email'] ?? null; $id = $identity['id'] ?? null;
        if (!is_string($email) || !is_scalar($id)) throw new RuntimeException('Current owner authentication is required.', 403);
        $owner = (new Store($this->config))->findAdminByEmail($email);
        if (!$owner || empty($owner['is_active']) || ($owner['role'] ?? '') !== 'owner' || (string) $owner['id'] !== (string) $id) throw new RuntimeException('Current owner authentication is required.', 403);
    }

    private function epoch(mixed $date, bool $utc = false): ?int
    {
        if (!is_string($date) || trim($date) === '' || strlen($date) > 40) return null;
        try {
            if ($utc && preg_match('/\A\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\z/D', $date)) return (new DateTimeImmutable($date, new DateTimeZone('UTC')))->getTimestamp();
            return MarketingPublication::storedDate($date)?->getTimestamp();
        } catch (Throwable) { return null; }
    }

    private function normalized(array $data): array
    {
        if ($data === []) return ['schema' => 1, 'settings' => ['enabled' => false, 'types' => self::TYPES, 'enabled_at' => null, 'revision' => 0],
            'jobs' => [], 'content_seen' => [], 'content_work' => [], 'member_cursor' => ''];
        if (($data['schema'] ?? null) !== 1 || !is_array($data['settings'] ?? null) || !is_bool($data['settings']['enabled'] ?? null)
            || !is_array($data['settings']['types'] ?? null) || !array_is_list($data['settings']['types'])
            || count(array_filter($data['settings']['types'], 'is_string')) !== count($data['settings']['types'])
            || array_diff($data['settings']['types'], self::TYPES) !== []
            || count(array_unique($data['settings']['types'])) !== count($data['settings']['types'])
            || !array_key_exists('enabled_at', $data['settings']) || ($data['settings']['enabled_at'] !== null && !is_int($data['settings']['enabled_at']))
            || !is_int($data['settings']['revision'] ?? null) || !is_array($data['jobs'] ?? null)
            || !is_array($data['content_seen'] ?? null) || !is_array($data['content_work'] ?? null) || !is_string($data['member_cursor'] ?? null)) {
            throw new RuntimeException('The notification journal needs review; delivery is held.');
        }
        foreach ($data['erased_members'] ?? [] as $hash => $at) if (!is_string($hash) || preg_match('/\A[a-f0-9]{64}\z/D', $hash) !== 1 || !is_int($at) || $at < 1) throw new RuntimeException('Notification privacy markers need review.');
        foreach ($data['jobs'] as $key => $job) {
            if (!is_array($job) || !is_string($job['id'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $job['id']) !== 1
                || $key !== $job['id'] || !in_array($job['type'] ?? '', self::TYPES, true)
                || !in_array($job['status'] ?? '', ['queued', 'claimed', 'sending', 'sent', 'failed', 'unknown', 'skipped', 'canceled'], true)
                || !is_array($job['context'] ?? null) || !is_int($job['attempts'] ?? null) || !is_int($job['due_at'] ?? null)
                || !is_int($job['created_at'] ?? null) || !is_int($job['updated_at'] ?? null) || !is_int($job['next_attempt_at'] ?? null)
                || !is_bool($job['retryable'] ?? null) || !is_string($job['message_id'] ?? null)
                || $job['attempts'] < 0 || $job['attempts'] > 3 || $job['due_at'] < 0 || $job['created_at'] < 0 || $job['updated_at'] < 0
                || !is_string($job['source_id'] ?? null) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9:._-]{0,189}\z/D', $job['source_id']) !== 1
                || !is_string($job['email_hash'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $job['email_hash']) !== 1
                || !array_key_exists('recipient_hash', $job) || ($job['recipient_hash'] !== null && $job['recipient_hash'] !== $job['email_hash'])
                || !array_key_exists('last_error', $job) || ($job['last_error'] !== null && (!is_string($job['last_error']) || strlen($job['last_error']) > 500))
                || $job['message_id'] !== CampaignMessage::messageId('notification', $job['id'])
                || (in_array($job['status'], ['claimed', 'sending'], true) && (!is_array($job['claim'] ?? null)
                    || !is_string($job['claim']['token'] ?? null) || preg_match('/\A[a-f0-9]{48}\z/D', $job['claim']['token']) !== 1
                    || !is_int($job['claim']['until'] ?? null))) || !array_key_exists('claim', $job)
                || (!in_array($job['status'], ['claimed', 'sending'], true) && $job['claim'] !== null)
                || !$this->savedContextValid($job)) {
                throw new RuntimeException('A saved notification needs review; delivery is held.');
            }
        }
        foreach ($data['content_seen'] as $key => $seen) {
            if (!is_string($key) || preg_match('/\A(?:post|gallery|video|track):[A-Za-z0-9][A-Za-z0-9_-]{0,79}\z/D', $key) !== 1
                || !is_array($seen) || !is_bool($seen['live'] ?? null) || !is_bool($seen['announced'] ?? null)) throw new RuntimeException('The notification content index needs review.');
        }
        foreach ($data['content_work'] as $key => $work) {
            if (!isset($data['content_seen'][$key]) || !is_array($work) || !is_string($work['kind'] ?? null) || !is_string($work['object_id'] ?? null)
                || $key !== $work['kind'] . ':' . $work['object_id'] || !is_int($work['created_at'] ?? null)
                || !is_string($work['member_cursor'] ?? null) || !is_bool($work['done'] ?? null)) throw new RuntimeException('The notification content queue needs review.');
        }
        return $data;
    }

    private function savedContextValid(array $job): bool
    {
        $context = $job['context'];
        foreach ($context as $value) if (!is_string($value) || strlen($value) > 250 || preg_match('/[\x00-\x1f\x7f]/', $value)) return false;
        if ($job['type'] === 'owner_security') {
            if (!array_key_exists('member_id', $job) || $job['member_id'] !== null || !is_string($job['owner_id'] ?? null)
                || !is_string($job['owner_email'] ?? null) || !EmailAddress::isValid($job['owner_email'])
                || array_diff(array_keys($context), ['kind', 'url', 'event_at']) !== []
                || !in_array($context['kind'] ?? '', ['suspicious_admin_login', 'admin_recovery'], true)
                || preg_match('/\Aadmin\/(?:\?page=[a-z][a-z0-9-]{0,60})?\z/D', $context['url'] ?? '') !== 1
                || $this->epoch($context['event_at'] ?? null) === null) return false;
            $identity = 'owner:' . $job['owner_id'];
        } else {
            if (!is_string($job['member_id'] ?? null) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,79}\z/D', $job['member_id']) !== 1
                || ($job['owner_id'] ?? null) !== null || ($job['owner_email'] ?? null) !== null) return false;
            $identity = $job['member_id'];
            if ($job['type'] === 'pending_registration') {
                if ($context !== [] || $job['source_id'] !== 'registration:' . $identity) return false;
            } else {
                $kinds = match ($job['type']) { 'new_content' => ['post', 'gallery', 'video', 'track'], 'renewal_reminder' => ['subscription'], 'renewal' => ['order'], 'payment_failure' => ['order', 'subscription'] };
                if (!in_array($context['kind'] ?? '', $kinds, true) || !is_string($context['object_id'] ?? null)
                    || preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,79}\z/D', $context['object_id']) !== 1
                    || array_diff(array_keys($context), ['kind', 'object_id', 'period_end']) !== []) return false;
                $source = $context['kind'] . ':' . $context['object_id'];
                if ($context['kind'] === 'subscription') {
                    if (preg_match('/\A[0-9]{1,12}\z/D', $context['period_end'] ?? '') !== 1) return false;
                    $source .= ':' . $context['period_end'];
                } elseif (isset($context['period_end'])) return false;
                if ($source !== $job['source_id']) return false;
            }
        }
        return hash_equals(hash('sha256', $job['type'] . '|' . $job['source_id'] . '|' . $identity), $job['id']);
    }

    private function mutate(callable $operation): mixed
    {
        return $this->state->update('notifications', function (array &$data) use ($operation): mixed {
            $data = $this->normalized($data);
            $erased = $data['erased_members'] ?? [];
            $result = $operation($data);
            $markers = array_replace($data['erased_members'] ?? [], $erased);
            if ($markers !== []) $data['erased_members'] = $markers;
            foreach ($data['jobs'] as $id => $job) if ($job['member_id'] !== null && isset($markers[hash('sha256', $job['member_id'])])) unset($data['jobs'][$id]);
            $this->normalized($data);
            return $result;
        });
    }
}
