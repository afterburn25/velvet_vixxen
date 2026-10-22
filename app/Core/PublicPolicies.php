<?php
declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Five deliberate draft/published snapshots; GET never initializes storage. */
final class PublicPolicies
{
    public const DOCUMENTS = ['terms' => 'Terms of use', 'privacy' => 'Privacy policy', 'adults' => 'Adults & access', 'dmca' => 'Copyright & content reports', 'support' => 'Support & refunds'];
    private readonly PrivateState $state;

    public function __construct(private readonly Config $config, ?PrivateState $state = null)
    {
        $this->state = $state ?? new PrivateState($config);
    }

    public static function name(mixed $name): string
    {
        if (!is_string($name) || !isset(self::DOCUMENTS[$name])) throw new InvalidArgumentException('Choose a saved public policy.');
        return $name;
    }

    public function requireOwner(array $owner): void { (new ProtectionRecords($this->config))->requireOwner($owner); }

    public function read(string $name, array $owner): array
    {
        self::name($name); $this->requireOwner($owner); $snapshot = $this->snapshot(); $this->requireOwner($owner);
        return $snapshot['documents'][$name] + ['revision' => ProtectionRecords::revision($snapshot['documents'][$name])];
    }

    public function published(string $name): ?array
    {
        self::name($name); return $this->snapshot()['documents'][$name]['published'];
    }

    public function save(string $name, array $input, string $expected, array $owner, callable $privileged): void
    {
        self::name($name); $this->requireOwner($owner); $privileged();
        $title = ProtectionRecords::text($input['title'] ?? null, 160, 'Policy title', true);
        $body = ProtectionRecords::text($input['body'] ?? null, 30000, 'Policy text');
        $this->mutate($name, $expected, $owner, $privileged, static function (array &$record) use ($title, $body): void {
            $record['draft'] = ['title' => $title, 'body' => $body]; $record['updated_at'] = gmdate('c');
        });
    }

    public function publish(string $name, string $expected, bool $reviewed, array $owner, callable $privileged): void
    {
        self::name($name); $this->requireOwner($owner); $privileged();
        if (!$reviewed) throw new InvalidArgumentException('Review the saved draft and confirm it is ready for public publication.');
        $this->mutate($name, $expected, $owner, $privileged, static function (array &$record): void {
            if ($record['draft']['body'] === '') throw new InvalidArgumentException('Save the policy text before publishing.');
            $record['published'] = $record['draft'] + ['published_at' => gmdate('c')];
            $record['updated_at'] = gmdate('c');
        });
    }

    public function unpublish(string $name, string $expected, array $owner, callable $privileged): void
    {
        self::name($name); $this->requireOwner($owner); $privileged();
        $this->mutate($name, $expected, $owner, $privileged, static function (array &$record): void {
            $record['published'] = null; $record['updated_at'] = gmdate('c');
        });
    }

    private function mutate(string $name, string $expected, array $owner, callable $privileged, callable $change): void
    {
        $this->state->update('public_policies', function (array &$data) use ($name, $expected, $owner, $privileged, $change): void {
            $data = $this->validate($data);
            if (!hash_equals(ProtectionRecords::revision($data['documents'][$name]), $expected)) throw new RuntimeException('This policy changed. Reload its saved draft before continuing.', 409);
            $this->requireOwner($owner); $privileged();
            $change($data['documents'][$name]); ++$data['documents'][$name]['generation'];
            $this->validate($data); $this->requireOwner($owner); $privileged();
        });
    }

    private function snapshot(): array { return $this->validate($this->state->read('public_policies')); }

    private function validate(array $data): array
    {
        if ($data === []) {
            $data = ['schema' => 1, 'documents' => []];
            foreach (self::DOCUMENTS as $name => $title) $data['documents'][$name] = ['draft' => ['title' => $title, 'body' => ''], 'published' => null, 'updated_at' => '', 'generation' => 0];
            return $data;
        }
        try {
            if (array_keys($data) !== ['schema', 'documents'] || ($data['schema'] ?? null) !== 1 || !is_array($data['documents'] ?? null)
                || array_keys($data['documents']) !== array_keys(self::DOCUMENTS)) throw new RuntimeException();
            foreach ($data['documents'] as $record) {
                if (!is_array($record) || array_keys($record) !== ['draft', 'published', 'updated_at', 'generation']
                    || !is_int($record['generation'] ?? null) || $record['generation'] < 0 || $record['generation'] >= 2147483647
                    || !is_string($record['updated_at'] ?? null)
                    || !array_key_exists('published', $record) || !is_array($record['draft'] ?? null)) throw new RuntimeException();
                if ($record['updated_at'] !== '') YotiAgeProvider::timestamp($record['updated_at']);
                if (array_keys($record['draft']) !== ['title', 'body']) throw new RuntimeException();
                foreach ([$record['draft'], $record['published']] as $body) {
                    if ($body === null) continue;
                    if (!is_array($body) || ProtectionRecords::text($body['title'] ?? null, 160, 'Policy title', true) !== $body['title']
                        || ProtectionRecords::text($body['body'] ?? null, 30000, 'Policy text') !== $body['body']) throw new RuntimeException();
                }
                if ($record['published'] !== null) {
                    if (array_keys($record['published']) !== ['title', 'body', 'published_at'] || $record['published']['body'] === '') throw new RuntimeException();
                    YotiAgeProvider::timestamp($record['published']['published_at']);
                }
            }
        } catch (Throwable $error) { throw new RuntimeException('Public policies need a storage review. Saved drafts were preserved.', 503, $error); }
        return $data;
    }
}
