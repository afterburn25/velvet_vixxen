<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;
use Throwable;

/** Scene scheduling and manual publication share the same durable section lock. */
trait SceneScheduleStoreTrait
{
    public function sceneSchedule(string $section): array
    {
        $section = SceneSchedule::section($section);
        [$state, $scenes] = $this->readSceneScheduleState($section);
        return SceneSchedule::snapshot($state, (array) ($scenes['draft'] ?? []), (array) ($scenes['published'] ?? []), $section);
    }

    /** Calendar metadata only: no initialization, worker, claim or mutation. */
    public function sceneScheduleCalendarRecords(): array
    {
        $records = [];
        foreach (['hero', 'footer'] as $section) {
            [$state] = $this->readSceneScheduleState($section);
            $records[$section] = array_map(static fn(array $job): array => SceneSchedule::metadata($job), $state['history']);
            if (is_array($state['schedule'])) $records[$section][] = SceneSchedule::metadata($state['schedule']);
        }
        return $records;
    }

    public function requireSceneScheduleOwner(array $identity): array
    {
        if ($this->mode === 'file') {
            [$data] = $this->loadFileStore();
            return $this->sceneScheduleOwner($identity, $data, null);
        }
        return $this->sceneScheduleOwner($identity, null, $this->pdo());
    }

    public function scheduleScene(string $section, array $input, array $ownerIdentity): array
    {
        $section = SceneSchedule::section($section);
        $starts = SceneSchedule::formDate($input['startsAt'] ?? null);
        $ends = SceneSchedule::formDate($input['endsAt'] ?? null, true);
        $policy = $input['returnPolicy'] ?? null;
        if (!in_array($policy, ['keep', 'restore_previous'], true)) throw new \InvalidArgumentException('Choose what happens when the schedule ends.');
        if ($ends !== null && SceneSchedule::epoch($ends) <= SceneSchedule::epoch($starts)) throw new \InvalidArgumentException('The end must be after the start.');
        if ($policy === 'restore_previous' && $ends === null) throw new \InvalidArgumentException('Choose an end time to restore the previous composition.');
        $result = $this->mutateSceneSection($section, $ownerIdentity,
            function (array &$state, array &$scenes, ?array &$data, ?PDO $pdo) use ($section, $input, $starts, $ends, $policy, $ownerIdentity): void {
                SceneSchedule::expect($input['expectedRevision'] ?? null, SceneSchedule::revision($state), 'The scene schedule');
                $draft = (array) ($scenes['draft'] ?? []);
                $published = (array) ($scenes['published'] ?? []);
                SceneSchedule::expect($input['expectedDraftRevision'] ?? null, SceneSchedule::sceneRevision($draft), 'The saved composition');
                SceneSchedule::expect($input['expectedPublishedRevision'] ?? null, SceneSchedule::sceneRevision($published), 'The live composition');
                if ($state['schedule'] !== null) throw new RuntimeException('This section already has an open schedule. Cancel it before scheduling another composition.', 409);
                $frozen = SceneSchedule::scene($draft, $section);
                if ($policy === 'restore_previous') {
                    if ($published === []) throw new \InvalidArgumentException('Publish a baseline composition before choosing Restore previous.');
                    SceneSchedule::scene($published, $section);
                }
                $at = SceneSchedule::at(time());
                $state['schedule'] = ['id' => bin2hex(random_bytes(16)), 'section' => $section, 'status' => 'scheduled',
                    'snapshotTitle' => SceneSchedule::title($frozen, $section), 'startsAt' => $starts, 'endsAt' => $ends,
                    'returnPolicy' => $policy, 'scene' => $frozen, 'snapshotRevision' => SceneSchedule::sceneRevision($frozen),
                    'owner' => ['id' => $ownerIdentity['id'], 'email' => $ownerIdentity['email']],
                    'baselineRevision' => SceneSchedule::sceneRevision($published), 'createdAt' => $at, 'updatedAt' => $at,
                    'claim' => null, 'previousScene' => null, 'previousRevision' => null, 'activatedRevision' => null];
                $this->sceneScheduleAudit($data, $pdo, $ownerIdentity, 'scene.schedule.created', ['section' => $section, 'schedule' => $state['schedule']['id']]);
            });
        return SceneSchedule::snapshot($result['state'], (array) ($result['scenes']['draft'] ?? []), (array) ($result['scenes']['published'] ?? []), $section);
    }

    public function cancelSceneSchedule(string $section, string $id, string $expectedRevision, array $ownerIdentity): array
    {
        $section = SceneSchedule::section($section);
        $result = $this->mutateSceneSection($section, $ownerIdentity,
            function (array &$state, array &$scenes, ?array &$data, ?PDO $pdo) use ($section, $id, $expectedRevision, $ownerIdentity): void {
                SceneSchedule::expect($expectedRevision, SceneSchedule::revision($state), 'The scene schedule');
                if (!is_array($state['schedule']) || !hash_equals((string) ($state['schedule']['id'] ?? ''), $id)) {
                    throw new RuntimeException('This scene schedule is no longer open. Reload the saved state.', 409);
                }
                $this->finishSceneSchedule($state, 'cancelled', 'Cancelled by the owner. The current live composition was kept.', time());
                $this->sceneScheduleAudit($data, $pdo, $ownerIdentity, 'scene.schedule.cancelled', ['section' => $section, 'schedule' => $id]);
            });
        return SceneSchedule::snapshot($result['state'], (array) ($result['scenes']['draft'] ?? []), (array) ($result['scenes']['published'] ?? []), $section);
    }

    /** Explicit owner action or CLI only. Public rendering must never call this. */
    public function runSceneSchedules(int $limit = 10, ?array $ownerIdentity = null, ?int $nowEpoch = null): array
    {
        if ($limit < 1 || $limit > 25) throw new \InvalidArgumentException('Use a scene batch limit from 1 through 25.');
        if ($ownerIdentity !== null) $this->requireSceneScheduleOwner($ownerIdentity);
        $stats = ['claimed' => 0, 'activated' => 0, 'expired' => 0, 'skipped' => 0, 'held' => 0, 'superseded' => 0, 'recovered' => 0, 'unchanged' => 0, 'busy' => 0];
        $processed = 0;
        foreach (['hero', 'footer'] as $section) {
            if ($processed >= $limit) break;
            try { $claim = $this->claimSceneSchedule($section, $nowEpoch, $ownerIdentity); }
            catch (RuntimeException $error) {
                if ($error->getCode() !== 409 || $error->getMessage() !== 'File data is being updated. Reload and try again.') throw $error;
                ++$stats['busy'];
                continue;
            }
            $outcome = $claim['outcome'];
            if ($outcome === 'unchanged') { ++$stats['unchanged']; continue; }
            ++$processed;
            if ($outcome !== 'claimed') { ++$stats[$outcome]; continue; }
            ++$stats['claimed'];
            if ($claim['recovered']) ++$stats['recovered'];
            // A crash here leaves a durable processing claim. A later worker
            // can recover it after the lease, before any publication happened.
            try { $outcome = $this->finishSceneScheduleClaim($section, $claim['id'], $claim['token'], $nowEpoch, $ownerIdentity); }
            catch (RuntimeException $error) {
                if ($error->getCode() !== 409 || $error->getMessage() !== 'File data is being updated. Reload and try again.') throw $error;
                ++$stats['busy'];
                continue;
            }
            ++$stats[$outcome];
        }
        if ($ownerIdentity !== null) $this->requireSceneScheduleOwner($ownerIdentity);
        return $stats;
    }

    /** Commit a claim before publication; callers must never hold stale scene data. */
    private function claimSceneSchedule(string $section, ?int $nowEpoch = null, ?array $ownerIdentity = null): array
    {
        [$peek] = $this->readSceneScheduleState($section);
        if ($peek['schedule'] === null) return ['outcome' => 'unchanged'];
        $result = $this->mutateSceneSection($section, $ownerIdentity,
            function (array &$state, array &$scenes, ?array &$data, ?PDO $pdo) use ($section, $nowEpoch): array {
                $job = $state['schedule'];
                if (!is_array($job)) return ['outcome' => 'unchanged'];
                try {
                    $job = SceneSchedule::job($job, $section);
                    // A row lock can wait across a time boundary. Read the real
                    // clock only after both scene and current-owner locks.
                    $this->sceneScheduleOwner((array) $job['owner'], $data, $pdo, true);
                    $nowEpoch ??= time();
                    $status = $job['status'] ?? '';
                    $recovered = false;
                    if ($status === 'processing') {
                        if (!is_array($job['claim'] ?? null) || !is_int($job['claim']['until'] ?? null)
                            || !in_array($job['claim']['previousStatus'] ?? '', ['scheduled', 'active'], true)) throw new \InvalidArgumentException('The saved processing claim needs review.');
                        if ($job['claim']['until'] > $nowEpoch) return ['outcome' => 'unchanged'];
                        $status = $job['claim']['previousStatus'];
                        $recovered = true;
                    }
                    if (!in_array($status, ['scheduled', 'active'], true)) throw new \InvalidArgumentException('The saved scene state needs review.');
                    $start = SceneSchedule::epoch($job['startsAt'] ?? null);
                    $end = ($job['endsAt'] ?? null) === null ? null : SceneSchedule::epoch($job['endsAt']);
                    if ($end !== null && $end <= $start) throw new \InvalidArgumentException('The saved scene dates need review.');
                    if ($status === 'scheduled' && $start > $nowEpoch) return ['outcome' => 'unchanged'];
                    if ($status === 'active' && ($end === null || $end > $nowEpoch)) return ['outcome' => 'unchanged'];
                    if ($status === 'scheduled' && $end !== null && $end <= $nowEpoch) {
                        $this->finishSceneSchedule($state, 'skipped', 'The complete time window passed before a worker could activate it.', $nowEpoch);
                        return ['outcome' => 'skipped'];
                    }
                    if ($status === 'scheduled' && !hash_equals((string) ($job['baselineRevision'] ?? ''), SceneSchedule::sceneRevision((array) ($scenes['published'] ?? [])))) {
                        $this->finishSceneSchedule($state, 'superseded', 'The live composition changed after scheduling. It was kept.', $nowEpoch);
                        return ['outcome' => 'superseded'];
                    }
                    $token = bin2hex(random_bytes(24));
                    $state['schedule']['status'] = 'processing';
                    $state['schedule']['updatedAt'] = SceneSchedule::at($nowEpoch);
                    $state['schedule']['claim'] = ['token' => $token, 'until' => $nowEpoch + SceneSchedule::CLAIM_SECONDS,
                        'operation' => $status === 'scheduled' ? 'activate' : 'expire', 'previousStatus' => $status];
                    return ['outcome' => 'claimed', 'id' => $job['id'], 'token' => $token, 'recovered' => $recovered];
                } catch (\InvalidArgumentException|RuntimeException $error) {
                    // Invalid persisted data and revoked authorization are held.
                    // Database/IO failures must propagate so a later run can
                    // retry; they are not evidence that the schedule is bad.
                    if ($error instanceof RuntimeException && $error->getCode() !== 403) throw $error;
                    $reason = $error->getCode() === 403 ? 'The owner who scheduled this composition is no longer authorized. Review and schedule it again.'
                        : 'The saved schedule needs review. No composition was changed.';
                    $this->finishSceneSchedule($state, 'held', $reason, $nowEpoch ?? time());
                    return ['outcome' => 'held'];
                }
            }, true);
        return $result['result'];
    }

    /** Publication, history and completion are one atomic store transaction. */
    private function finishSceneScheduleClaim(string $section, string $id, string $token, ?int $nowEpoch = null, ?array $ownerIdentity = null): string
    {
        $result = $this->mutateSceneSection($section, $ownerIdentity,
            function (array &$state, array &$scenes, ?array &$data, ?PDO $pdo) use ($section, $id, $token, $nowEpoch): string {
                $job = $state['schedule'];
                if (!is_array($job)) return 'unchanged';
                try { $job = SceneSchedule::job($job, $section); }
                catch (\InvalidArgumentException) {
                    $this->finishSceneSchedule($state, 'held', 'The saved schedule needs review. No composition was changed.', $nowEpoch ?? time());
                    return 'held';
                }
                if ($job['id'] !== $id || $job['status'] !== 'processing'
                    || !hash_equals($job['claim']['token'], $token)) return 'unchanged';
                try {
                    $this->sceneScheduleOwner((array) ($job['owner'] ?? []), $data, $pdo, true);
                } catch (RuntimeException $error) {
                    if ($error->getCode() !== 403) throw $error;
                    $this->finishSceneSchedule($state, 'held', 'The owner who scheduled this composition is no longer authorized. Review and schedule it again.', $nowEpoch ?? time());
                    return 'held';
                }
                $nowEpoch ??= time();
                if ($job['claim']['until'] <= $nowEpoch) return 'unchanged';
                $published = (array) ($scenes['published'] ?? []);
                $end = ($job['endsAt'] ?? null) === null ? null : SceneSchedule::epoch($job['endsAt']);
                if ($job['claim']['operation'] === 'activate') {
                    if ($end !== null && $end <= $nowEpoch) {
                        $this->finishSceneSchedule($state, 'skipped', 'The complete time window passed before activation.', $nowEpoch);
                        return 'skipped';
                    }
                    if (SceneSchedule::epoch($job['startsAt']) > $nowEpoch) return 'unchanged';
                    if (!hash_equals((string) $job['baselineRevision'], SceneSchedule::sceneRevision($published))) {
                        $this->finishSceneSchedule($state, 'superseded', 'A newer live composition was kept.', $nowEpoch);
                        return 'superseded';
                    }
                    try {
                        $scene = SceneSchedule::scene((array) ($job['scene'] ?? []), $section);
                        if ($job['returnPolicy'] === 'restore_previous') SceneSchedule::scene($published, $section);
                    } catch (\InvalidArgumentException|RuntimeException) {
                        $this->finishSceneSchedule($state, 'held', 'A saved composition needs review. No composition was changed.', $nowEpoch);
                        return 'held';
                    }
                    $scene['updatedAt'] = SceneSchedule::at($nowEpoch);
                    $this->writeSceneAtomic($section, $scene, true, false, $this->sceneScheduleAdminId($job['owner']), $scenes, $pdo);
                    $state['schedule']['previousScene'] = $job['returnPolicy'] === 'restore_previous' ? $published : null;
                    $state['schedule']['previousRevision'] = $job['returnPolicy'] === 'restore_previous' ? SceneSchedule::sceneRevision($published) : null;
                    $state['schedule']['activatedRevision'] = SceneSchedule::sceneRevision($scene);
                    $state['schedule']['activatedAt'] = SceneSchedule::at($nowEpoch);
                    $state['schedule']['updatedAt'] = SceneSchedule::at($nowEpoch);
                    $state['schedule']['status'] = 'active';
                    $state['schedule']['claim'] = null;
                    if ($end === null) $this->finishSceneSchedule($state, 'completed', 'The scheduled composition was published and will stay live until another publication.', $nowEpoch);
                    $this->sceneScheduleAudit($data, $pdo, $job['owner'], 'scene.schedule.activated', ['section' => $section, 'schedule' => $id]);
                    return 'activated';
                }
                if ($job['claim']['operation'] !== 'expire' || $end === null || $end > $nowEpoch) return 'unchanged';
                if (!hash_equals((string) ($job['activatedRevision'] ?? ''), SceneSchedule::sceneRevision($published))) {
                    $this->finishSceneSchedule($state, 'superseded', 'A newer live composition was kept; automatic return was cancelled.', $nowEpoch);
                    return 'superseded';
                }
                if ($job['returnPolicy'] === 'restore_previous') {
                    try { $previous = SceneSchedule::scene((array) ($job['previousScene'] ?? []), $section); }
                    catch (\InvalidArgumentException|RuntimeException) {
                        $this->finishSceneSchedule($state, 'held', 'The previous composition needs review. The current live composition was kept.', $nowEpoch);
                        return 'held';
                    }
                    $previous['updatedAt'] = SceneSchedule::at($nowEpoch);
                    $this->writeSceneAtomic($section, $previous, true, false, $this->sceneScheduleAdminId($job['owner']), $scenes, $pdo);
                }
                $reason = $job['returnPolicy'] === 'restore_previous' ? 'The previous live composition was restored.'
                    : 'The schedule ended and its composition was kept live as selected.';
                $this->finishSceneSchedule($state, 'expired', $reason, $nowEpoch);
                $this->sceneScheduleAudit($data, $pdo, $job['owner'], 'scene.schedule.expired', ['section' => $section, 'schedule' => $id]);
                return 'expired';
            }, true);
        return $result['result'];
    }

    private function finishSceneSchedule(array &$state, string $status, string $reason, int $at): void
    {
        if (!is_array($state['schedule'])) return;
        $job = $state['schedule'];
        $job['status'] = $status;
        $job['reason'] = $reason;
        $job['updatedAt'] = $job['finishedAt'] = SceneSchedule::at($at);
        $state['history'][] = SceneSchedule::metadata($job);
        $state['history'] = array_slice($state['history'], -SceneSchedule::MAX_HISTORY);
        $state['schedule'] = null;
    }

    private function readSceneScheduleState(string $section): array
    {
        $key = 'scene_schedule_' . SceneSchedule::section($section);
        if ($this->mode === 'file') {
            [$data] = $this->loadFileStore();
            return [SceneSchedule::state($data['settings'][$key] ?? null), (array) ($data['scenes'][$section] ?? [])];
        }
        $query = $this->pdo()->prepare('SELECT setting_value FROM settings WHERE setting_key=? LIMIT 1');
        $query->execute([$key]);
        $raw = $query->fetchColumn();
        return [SceneSchedule::state($raw === false ? null : $raw), $this->sceneSectionRows($section, false)];
    }

    private function sceneSectionRows(string $section, bool $lock): array
    {
        $rows = ['draft' => [], 'published' => [], 'versions' => []];
        $query = $this->pdo()->prepare('SELECT scene_json FROM scenes WHERE section_key=? AND status=? ORDER BY id DESC LIMIT 1' . ($lock ? ' FOR UPDATE' : ''));
        foreach (['draft', 'published'] as $status) {
            $query->execute([$section, $status]);
            $raw = $query->fetchColumn();
            if ($raw !== false) {
                try { $row = json_decode((string) $raw, true, 128, JSON_THROW_ON_ERROR); }
                catch (\JsonException) { throw new RuntimeException('The saved composition needs review.'); }
                if (!is_array($row)) throw new RuntimeException('The saved composition needs review.');
                $rows[$status] = $row;
            }
        }
        return $rows;
    }

    /** Callback mutates freshly locked arrays only; never call another writer inside it. */
    private function mutateSceneSection(string $section, ?array $identity, callable $mutation, bool $waitForLock = false): array
    {
        $key = 'scene_schedule_' . SceneSchedule::section($section);
        if ($identity !== null) $this->requireSceneScheduleOwner($identity);
        if ($this->mode === 'file') {
            return $this->mutateFileStore(function (array &$data) use ($section, $key, $identity, $mutation): array {
                if ($identity !== null) $this->sceneScheduleOwner($identity, $data, null);
                $state = SceneSchedule::state($data['settings'][$key] ?? null);
                $before = $state;
                $scenes = (array) ($data['scenes'][$section] ?? []);
                $beforeScenes = $scenes;
                $result = $mutation($state, $scenes, $data, null);
                if ($state !== $before) {
                    ++$state['revision'];
                    SceneSchedule::state($state);
                    $data['settings'][$key] = $state;
                }
                if ($scenes !== $beforeScenes) $data['scenes'][$section] = $scenes;
                // mutateFileStore rereads the actual persisted file immediately
                // before the atomic activation; no pre-publication copy escapes.
                return ['state' => $state, 'scenes' => $scenes, 'result' => $result];
            }, $waitForLock);
        }
        $pdo = $this->pdo();
        if ($pdo->inTransaction()) throw new RuntimeException('Finish the current database operation before changing a composition.');
        $pdo->beginTransaction();
        try {
            $insert = $pdo->prepare('INSERT INTO settings (setting_key,setting_value,updated_at) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE setting_key=VALUES(setting_key)');
            $insert->execute([$key, SceneSchedule::json(SceneSchedule::emptyState())]);
            $query = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=? FOR UPDATE');
            $query->execute([$key]);
            $state = SceneSchedule::state((string) $query->fetchColumn());
            $before = $state;
            // Every scene mutation takes the section row before any owner row.
            // Rechecking under that lock prevents owner revocation races.
            if ($identity !== null) $this->sceneScheduleOwner($identity, null, $pdo, true);
            $scenes = $this->sceneSectionRows($section, true);
            $data = null;
            $result = $mutation($state, $scenes, $data, $pdo);
            if ($state !== $before) {
                ++$state['revision'];
                SceneSchedule::state($state);
                $update = $pdo->prepare('UPDATE settings SET setting_value=?,updated_at=NOW() WHERE setting_key=?');
                $update->execute([SceneSchedule::json($state), $key]);
            }
            $pdo->commit();
            return ['state' => $state, 'scenes' => $scenes, 'result' => $result];
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    /** Called only while mutateSceneSection holds the common lock/transaction. */
    private function writeSceneAtomic(string $section, array $scene, bool $publish, bool $replaceDraft, ?int $adminId, array &$scenes, ?PDO $pdo): int|string
    {
        if ($pdo === null) {
            if ($replaceDraft) $scenes['draft'] = $scene;
            if ($publish) {
                $scenes['published'] = $scene;
                $scenes['versions'][] = ['id' => bin2hex(random_bytes(6)), 'publishedAt' => now_iso(), 'scene' => $scene];
                $scenes['versions'] = array_slice($scenes['versions'], -20);
            }
            $versions = (array) ($scenes['versions'] ?? []);
            return (string) ($versions[array_key_last($versions)]['id'] ?? 'draft');
        }
        $json = SceneSchedule::json($scene);
        if (!$publish) $pdo->prepare("DELETE FROM scenes WHERE section_key=? AND status='draft'")->execute([$section]);
        $insert = $pdo->prepare('INSERT INTO scenes (section_key,status,scene_json,created_by,created_at,updated_at) VALUES (?,?,?,?,NOW(),NOW())');
        $insert->execute([$section, $publish ? 'published' : 'draft', $json, $adminId]);
        $id = (int) $pdo->lastInsertId();
        if ($publish) {
            $scenes['published'] = $scene;
            if ($replaceDraft) {
                $pdo->prepare("DELETE FROM scenes WHERE section_key=? AND status='draft'")->execute([$section]);
                $insert->execute([$section, 'draft', $json, $adminId]);
            }
            $version = $pdo->prepare('INSERT INTO scene_versions (scene_id,section_key,scene_json,created_by,created_at) VALUES (?,?,?,?,NOW())');
            $version->execute([$id, $section, $json, $adminId]);
        }
        if ($replaceDraft) $scenes['draft'] = $scene;
        return $id;
    }

    private function sceneScheduleOwner(array $identity, ?array $data, ?PDO $pdo, bool $lock = false): array
    {
        $id = $identity['id'] ?? null;
        $email = $identity['email'] ?? null;
        if ((!is_string($id) && !is_int($id)) || (string) $id === '' || strlen((string) $id) > 80
            || !is_string($email) || $email === '' || strlen($email) > 254) throw new RuntimeException('Only the active site owner can manage scene schedules.', 403);
        if ($pdo !== null) {
            $query = $pdo->prepare('SELECT id,email,role,is_active FROM admins WHERE id=? LIMIT 1' . ($lock ? ' FOR UPDATE' : ''));
            $query->execute([(string) $id]);
            $row = $query->fetch(PDO::FETCH_ASSOC);
            $rows = is_array($row) ? [$row] : [];
        } else {
            $rows = (array) ($data['admins'] ?? []);
        }
        foreach ($rows as $row) {
            if (is_array($row) && (string) ($row['id'] ?? '') === (string) $id
                && strtolower((string) ($row['email'] ?? '')) === strtolower($email)
                && ($row['role'] ?? '') === 'owner' && in_array($row['is_active'] ?? null, [true, 1, '1'], true)) return $row;
        }
        throw new RuntimeException('Only the active site owner can manage scene schedules.', 403);
    }

    private function sceneScheduleAdminId(array $identity): ?int
    {
        return isset($identity['id']) && is_numeric($identity['id']) ? (int) $identity['id'] : null;
    }

    private function sceneScheduleAudit(?array &$data, ?PDO $pdo, array $identity, string $action, array $context): void
    {
        if ($pdo === null) {
            $data['audit'][] = ['id' => bin2hex(random_bytes(6)), 'adminId' => $identity['id'] ?? null, 'action' => $action,
                'context' => $context, 'ip' => $_SERVER['REMOTE_ADDR'] ?? '', 'createdAt' => now_iso()];
            $data['audit'] = array_slice($data['audit'], -500);
            return;
        }
        $query = $pdo->prepare('INSERT INTO audit_log (admin_id,action_key,context_json,ip_address,created_at) VALUES (?,?,?,?,NOW())');
        $query->execute([$this->sceneScheduleAdminId($identity), $action, SceneSchedule::json($context), $_SERVER['REMOTE_ADDR'] ?? null]);
    }
}
