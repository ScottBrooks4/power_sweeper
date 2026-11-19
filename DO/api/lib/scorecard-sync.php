<?php
declare(strict_types=1);

/**
 * Scorecard (data/scores.json) ↔ SharePoint list sync.
 *
 *   plan()        reads the list (GET only) and returns a previewable plan
 *   apply()       writes exactly what a plan previewed, after backing up
 *   restorePlan() builds a plan that writes a run's backups back
 *   status()      last run, open conflicts, lock
 *
 * Rules: nothing is deleted on either side, an empty value never overwrites a
 * filled one (except when restoring a backup), every PATCH carries If-Match, and
 * a plan applies at most once, unchanged, while it is fresh.
 */

class SyncRefused extends RuntimeException
{
    public function __construct(string $message, public int $status = 400)
    {
        parent::__construct($message);
    }
}

final class SyncBusy extends SyncRefused
{
    public function __construct()
    {
        parent::__construct('A scorecard sync is already running. Try again in a minute.', 409);
    }
}

final class FieldMap
{
    private const TYPES = ['score', 'level', 'text'];
    private const OWNERS = ['sharepoint', 'pivot', 'shared'];
    private const SYSTEM = [
        'id', 'author', 'editor', 'created', 'modified', 'attachments', 'contenttype', 'contenttypeid',
        'authorlookupid', 'editorlookupid', '_uiversionstring', '_moderationstatus', '_compliancetag',
        'appauthor', 'appeditor', 'edit', 'linktitle', 'linktitlenomenu', 'docicon', 'itemchildcount',
        'folderchildcount', 'guid', 'fileref', 'filedirref', 'uniqueid', 'version',
    ];

    /** @param array<string, array{column: string, type: string, owner: string}> $fields */
    private function __construct(private array $fields, private array $unmapped)
    {
    }

    public static function isPlaceholder(mixed $value): bool
    {
        return !is_string($value) || trim($value) === '' || str_starts_with($value, 'PLACEHOLDER');
    }

    public static function fromConfig(array $config): self
    {
        $key = (string) ($config['key_column'] ?? '');
        $fields = [];
        $unmapped = [];
        foreach ((array) ($config['fields'] ?? []) as $name => $field) {
            if (!is_string($name) || !preg_match('/^[A-Za-z][A-Za-z0-9]{0,40}$/', $name) || !is_array($field)) {
                throw new InvalidArgumentException('Bad scorecard field name.');
            }
            $column = $field['column'] ?? '';
            if (self::isPlaceholder($column)) {
                $unmapped[] = $name;
                continue;
            }
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,60}$/', $column)) {
                throw new InvalidArgumentException('Column for ' . $name . ' must be a SharePoint internal name.');
            }
            if (in_array(strtolower($column), self::SYSTEM, true) || strcasecmp($column, $key) === 0) {
                throw new InvalidArgumentException($column . ' is a system or key column and is never written.');
            }
            $type = (string) ($field['type'] ?? '');
            $owner = (string) ($field['owner'] ?? '');
            if (!in_array($type, self::TYPES, true) || !in_array($owner, self::OWNERS, true)) {
                throw new InvalidArgumentException('Field ' . $name . ' needs a known type and owner.');
            }
            $fields[$name] = ['column' => $column, 'type' => $type, 'owner' => $owner];
        }
        $columns = array_map('strtolower', array_column($fields, 'column'));
        if (count($columns) !== count(array_unique($columns))) {
            throw new InvalidArgumentException('Two scorecard fields map to the same column.');
        }
        return new self($fields, $unmapped);
    }

    /** @return array<int, string> */
    public function fields(): array
    {
        return array_keys($this->fields);
    }

    /** @return array<int, string> */
    public function unmapped(): array
    {
        return $this->unmapped;
    }

    public function column(string $field): string
    {
        return $this->fields[$field]['column'];
    }

    public function owner(string $field): string
    {
        return $this->fields[$field]['owner'];
    }

    /** @return array<int, string> */
    public function columns(): array
    {
        return array_column($this->fields, 'column');
    }

    /** A scores.json value in canonical form; null means empty. */
    public function local(string $field, mixed $value): int|string|null
    {
        return $this->canonical($this->fields[$field]['type'], $value, 'local');
    }

    /** A SharePoint value in canonical form; null means empty. */
    public function remote(string $field, mixed $value): int|string|null
    {
        return $this->canonical($this->fields[$field]['type'], $value, 'remote');
    }

    private function canonical(string $type, mixed $value, string $side): int|string|null
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($type === 'score') {
            if (is_int($value) || (is_float($value) && floor($value) === $value) || (is_string($value) && preg_match('/^\d+(?:\.0+)?$/', $value))) {
                $number = (int) $value;
                if ($number >= 1 && $number <= 5) {
                    return $number;
                }
            }
            throw new UnexpectedValueException('invalid_' . $side);
        }
        if (!is_string($value)) {
            throw new UnexpectedValueException('invalid_' . $side);
        }
        if ($type === 'level') {
            $level = trim($value);
            if (preg_match('/^L\d(?:\.5)?$/', $level)) {
                return $level;
            }
            throw new UnexpectedValueException('invalid_' . $side);
        }
        if (strlen($value) > 2000) {
            throw new UnexpectedValueException('invalid_' . $side);
        }
        return $value;
    }
}

final class SyncStore
{
    private const ID = '/^[A-Za-z0-9-]{8,64}$/';

    public function __construct(private string $dir)
    {
        foreach ([$dir, $dir . '/plans', $dir . '/backups'] as $path) {
            if (!is_dir($path) && !@mkdir($path, 0770, true) && !is_dir($path)) {
                throw new SyncRefused('The sync storage folder could not be created.', 500);
            }
        }
        // The folder sits inside the web root and holds list data, so no deny file means no sync.
        $deny = "Require all denied\n";
        if (!is_file($dir . '/.htaccess') && @file_put_contents($dir . '/.htaccess', $deny) !== strlen($deny)) {
            throw new SyncRefused('The sync storage folder could not be protected, so the sync stopped.', 500);
        }
    }

    public static function validId(string $id): bool
    {
        return (bool) preg_match(self::ID, $id);
    }

    public function state(): array
    {
        $data = is_file($this->dir . '/state.json') ? json_decode((string) file_get_contents($this->dir . '/state.json'), true) : null;
        return is_array($data) ? $data + ['items' => [], 'lastRun' => null, 'openConflicts' => []] : ['items' => [], 'lastRun' => null, 'openConflicts' => []];
    }

    public function saveState(array $state): void
    {
        $this->replace($this->dir . '/state.json', $this->encode($state));
    }

    public function savePlan(array $plan): void
    {
        $this->replace($this->dir . '/plans/' . $plan['planId'] . '.json', $this->encode($plan));
    }

    public function loadPlan(string $id): ?array
    {
        $path = $this->dir . '/plans/' . $id . '.json';
        if (!self::validId($id) || !is_file($path)) {
            return null;
        }
        $plan = json_decode((string) file_get_contents($path), true);
        return is_array($plan) ? $plan : null;
    }

    public function planApplied(string $id): bool
    {
        return self::validId($id) && is_file($this->dir . '/plans/' . $id . '.applied.json');
    }

    public function markPlanApplied(string $id): void
    {
        @rename($this->dir . '/plans/' . $id . '.json', $this->dir . '/plans/' . $id . '.applied.json');
    }

    public function runDir(string $runId): string
    {
        $path = $this->dir . '/backups/' . $runId;
        if (!self::validId($runId) || (!is_dir($path) && !@mkdir($path, 0770) && !is_dir($path))) {
            throw new SyncRefused('The backup folder could not be created, so nothing was written.', 500);
        }
        return $path;
    }

    public function backupRemote(string $runId, string $list, array $item, string $useCaseId): void
    {
        $dir = $this->runDir($runId) . '/remote/' . $list;
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new SyncRefused('The SharePoint backup could not be written, so the run stopped.', 500);
        }
        $this->durable($dir . '/' . $item['id'] . '.json', $this->encode(['useCaseId' => $useCaseId, 'item' => $item]));
    }

    public function backupLocal(string $runId, string $scoresFile): void
    {
        $raw = is_file($scoresFile) ? file_get_contents($scoresFile) : "{}\n";
        if ($raw === false) {
            throw new SyncRefused('scores.json could not be read for the backup, so nothing was written.', 500);
        }
        $this->durable($this->runDir($runId) . '/local-scores.json', $raw);
    }

    public function saveRun(string $runId, array $manifest): void
    {
        $this->durable($this->runDir($runId) . '/run.json', $this->encode($manifest));
    }

    /** @return array{manifest: array, remote: array<int, array>, local: array}|null */
    public function readRun(string $runId): ?array
    {
        $dir = $this->dir . '/backups/' . $runId;
        if (!self::validId($runId) || !is_file($dir . '/run.json')) {
            return null;
        }
        $manifest = json_decode((string) file_get_contents($dir . '/run.json'), true);
        $remote = [];
        foreach (glob($dir . '/remote/*/*.json') ?: [] as $file) {
            $saved = json_decode((string) file_get_contents($file), true);
            if (is_array($saved) && is_array($saved['item'] ?? null)) {
                $remote[] = $saved;
            }
        }
        $local = is_file($dir . '/local-scores.json') ? json_decode((string) file_get_contents($dir . '/local-scores.json'), true) : [];
        return ['manifest' => is_array($manifest) ? $manifest : [], 'remote' => $remote, 'local' => is_array($local) ? $local : []];
    }

    public function backupDir(string $runId): string
    {
        return $this->dir . '/backups/' . $runId;
    }

    public function audit(array $row): void
    {
        @file_put_contents($this->dir . '/audit.jsonl', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    }

    /** @return resource|null null when another run holds the lock */
    public function lock()
    {
        $handle = @fopen($this->dir . '/sync.lock', 'c');
        if ($handle === false) {
            throw new SyncRefused('The sync lock could not be opened.', 500);
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }
        return $handle;
    }

    /** @param resource $handle */
    public function unlock($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    public function locked(): bool
    {
        $handle = $this->lock();
        if ($handle === null) {
            return true;
        }
        $this->unlock($handle);
        return false;
    }

    private function encode(array $data): string
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new SyncRefused('Sync data could not be encoded.', 500);
        }
        return $json . "\n";
    }

    private function durable(string $path, string $contents): void
    {
        $handle = @fopen($path, 'xb');
        if ($handle === false) {
            throw new SyncRefused('A backup could not be written, so the run stopped before writing.', 500);
        }
        $written = fwrite($handle, $contents);
        $synced = fflush($handle) && fsync($handle);
        fclose($handle);
        if ($written !== strlen($contents) || !$synced || file_get_contents($path) !== $contents) {
            throw new SyncRefused('A backup could not be verified, so the run stopped before writing.', 500);
        }
    }

    private function replace(string $path, string $contents): void
    {
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $contents) !== strlen($contents) || !@rename($tmp, $path)) {
            @unlink($tmp);
            throw new SyncRefused('Sync state could not be saved.', 500);
        }
    }
}

final class ScorecardSync
{
    private const ORDER = ['push' => 0, 'restore_remote' => 0, 'pull' => 1, 'restore_local' => 1, 'create' => 2, 'conflict' => 3, 'skip' => 4, 'remote_missing' => 5];

    private FieldMap $map;
    private Closure $now;

    public function __construct(
        private array $config,
        private GraphClient $graph,
        private SyncStore $store,
        private string $scoresFile,
        private array $user,
        ?callable $now = null
    ) {
        $this->map = FieldMap::fromConfig($config);
        $this->now = $now !== null ? Closure::fromCallable($now) : static fn (): int => time();
    }

    public static function configured(array $config): bool
    {
        foreach (['site_id', 'list_id', 'key_column'] as $key) {
            if (FieldMap::isPlaceholder($config[$key] ?? null)) {
                return false;
            }
        }
        try {
            return FieldMap::fromConfig($config)->fields() !== [];
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    public function status(): array
    {
        $state = $this->store->state();
        return [
            'enabled' => !empty($this->config['enabled']),
            'mode' => $this->mode(),
            'configured' => self::configured($this->config),
            'list' => ['name' => (string) ($this->config['list_name'] ?? ''), 'id' => (string) ($this->config['list_id'] ?? '')],
            'lastRun' => $state['lastRun'],
            'openConflicts' => $state['openConflicts'],
            'locked' => $this->store->locked(),
            'mapped' => $this->map->fields(),
            'unmapped' => $this->map->unmapped(),
            'maxWrites' => $this->cap(),
            'allowCreate' => !empty($this->config['allow_create']),
        ];
    }

    public function plan(array $options): array
    {
        $direction = (string) ($options['direction'] ?? 'both');
        if (!in_array($direction, ['both', 'pull', 'push'], true)) {
            throw new SyncRefused('direction is both, pull or push.');
        }
        $cap = $this->cap();
        if (isset($options['maxWrites'])) {
            $cap = (int) $options['maxWrites'];
            if ($cap < 1 || $cap > 5000) {
                throw new SyncRefused('maxWrites is 1 to 5000.');
            }
        }
        $this->requireConfigured();
        return $this->locked(function () use ($direction, $cap): array {
            $remote = $this->graph->listItems($this->config['site_id'], $this->config['list_id'], array_merge([$this->config['key_column']], $this->map->columns()));
            [$operations, $snapshot, $warnings] = $this->diff($remote, $this->readScores(), $this->store->state());
            if ($direction === 'pull') {
                $operations = array_values(array_filter($operations, static fn (array $op): bool => !in_array($op['op'], ['push', 'create'], true)));
            } elseif ($direction === 'push') {
                $operations = array_values(array_filter($operations, static fn (array $op): bool => $op['op'] !== 'pull'));
            }
            return $this->savePlan('sync', $direction, $operations, $snapshot, $warnings, $cap, null);
        });
    }

    public function storedPlan(string $planId): array
    {
        $plan = $this->store->loadPlan($planId);
        if ($plan === null) {
            throw new SyncRefused('That plan was not found. Make a new one.', 404);
        }
        return $this->view($plan);
    }

    public function apply(string $planId, string $hash, bool $confirm): array
    {
        if (!$confirm) {
            throw new SyncRefused('Send confirm: true to apply a plan.');
        }
        if (!SyncStore::validId($planId)) {
            throw new SyncRefused('Unknown plan id.');
        }
        if ($this->mode() !== 'write') {
            throw new SyncRefused('Dry-run mode: nothing was written.', 423);
        }
        $this->requireConfigured();
        return $this->locked(function () use ($planId, $hash): array {
            $plan = $this->store->loadPlan($planId);
            if ($plan === null) {
                throw $this->store->planApplied($planId)
                    ? new SyncRefused('That plan was already applied. Make a new one.', 409)
                    : new SyncRefused('That plan was not found. Make a new one.', 404);
            }
            if (!hash_equals((string) $plan['hash'], $hash) || !hash_equals($this->hash($plan), $hash)) {
                throw new SyncRefused('The plan does not match what was previewed. Make a new one.', 409);
            }
            if (($this->now)() - (int) $plan['createdAt'] > (int) ($this->config['plan_ttl_seconds'] ?? 1800)) {
                throw new SyncRefused('The plan is too old to apply. Make a new one.', 409);
            }
            if (!empty($plan['blocked'])) {
                throw new SyncRefused('The plan has ' . $plan['writes'] . ' writes, over the cap of ' . $plan['maxWrites'] . '. An admin can raise the cap for one plan.', 409);
            }
            return $this->run($plan);
        });
    }

    public function restorePlan(string $runId): array
    {
        if (!SyncStore::validId($runId)) {
            throw new SyncRefused('Unknown run id.');
        }
        $this->requireConfigured();
        return $this->locked(function () use ($runId): array {
            $run = $this->store->readRun($runId);
            if ($run === null) {
                throw new SyncRefused('No backups were found for that run.', 404);
            }
            $operations = [];
            $warnings = [];
            foreach ($run['remote'] as $saved) {
                $pre = $saved['item'];
                $current = $this->graph->getItem($this->config['site_id'], $this->config['list_id'], (string) $pre['id']);
                if ($current === null) {
                    $operations[] = ['op' => 'remote_missing', 'useCaseId' => (string) $saved['useCaseId'], 'itemId' => (string) $pre['id']];
                    continue;
                }
                $fields = [];
                foreach ($this->map->fields() as $field) {
                    $column = $this->map->column($field);
                    try {
                        $was = $this->map->remote($field, $pre['fields'][$column] ?? null);
                        $now = $this->map->remote($field, $current['fields'][$column] ?? null);
                    } catch (UnexpectedValueException) {
                        $warnings[] = 'Item ' . $pre['id'] . ' ' . $column . ' holds a value Pivot cannot write back.';
                        continue;
                    }
                    if ($was !== $now) {
                        $fields[$field] = ['column' => $column, 'before' => $now, 'after' => $was];
                    }
                }
                if ($fields) {
                    $operations[] = ['op' => 'restore_remote', 'useCaseId' => (string) $saved['useCaseId'], 'itemId' => (string) $pre['id'], 'eTag' => $current['eTag'], 'fields' => $fields];
                }
            }
            $scores = $this->readScores();
            foreach ((array) ($run['manifest']['local'] ?? []) as $useCaseId => $names) {
                $useCaseId = (string) $useCaseId;
                $fields = [];
                foreach ((array) $names as $field) {
                    if (!in_array($field, $this->map->fields(), true)) {
                        continue;
                    }
                    $was = $this->safeLocal($field, $run['local'][$useCaseId][$field] ?? null);
                    $now = $this->safeLocal($field, $scores[$useCaseId][$field] ?? null);
                    if ($was !== $now) {
                        $fields[$field] = ['column' => $this->map->column($field), 'before' => $now, 'after' => $was];
                    }
                }
                if ($fields) {
                    $operations[] = ['op' => 'restore_local', 'useCaseId' => $useCaseId, 'fields' => $fields];
                }
            }
            foreach ((array) ($run['manifest']['created'] ?? []) as $created) {
                $warnings[] = 'List item ' . $created . ' was created by that run. Restore never deletes, so it stays.';
            }
            usort($operations, fn (array $a, array $b): int => [(int) $a['useCaseId'], self::ORDER[$a['op']]] <=> [(int) $b['useCaseId'], self::ORDER[$b['op']]]);
            return $this->savePlan('restore', 'both', $operations, [], $warnings, max($this->cap(), count($operations)), $runId);
        });
    }

    /** @return array{0: array, 1: array, 2: array} operations, snapshot, warnings */
    private function diff(array $remote, array $local, array $state): array
    {
        $key = $this->config['key_column'];
        $byCase = [];
        $duplicates = [];
        $warnings = [];
        foreach ($remote as $item) {
            $value = $item['fields'][$key] ?? null;
            $caseId = is_int($value) || is_float($value) ? (string) (int) $value : trim((string) $value);
            if (!preg_match('/^\d{1,7}$/', $caseId)) {
                $warnings[] = 'List item ' . $item['id'] . ' has no use case id in ' . $key . ', so it is not synced.';
                continue;
            }
            if (isset($byCase[$caseId])) {
                $duplicates[$caseId] = true;
            }
            $byCase[$caseId] = $item;
        }

        $ids = array_unique(array_merge(
            array_map('strval', array_keys($byCase)),
            array_filter(array_map('strval', array_keys($local)), static fn (string $id): bool => (bool) preg_match('/^\d{1,7}$/', $id)),
            array_map('strval', array_keys($state['items'] ?? []))
        ));
        usort($ids, static fn (string $a, string $b): int => (int) $a <=> (int) $b);

        $operations = [];
        $snapshot = [];
        foreach ($ids as $caseId) {
            $row = is_array($local[$caseId] ?? null) ? $local[$caseId] : [];
            $base = is_array($state['items'][$caseId] ?? null) ? $state['items'][$caseId] : null;
            $item = $byCase[$caseId] ?? null;

            if (isset($duplicates[$caseId])) {
                $operations[] = ['op' => 'skip', 'useCaseId' => $caseId, 'reason' => 'duplicate_remote_key'];
                continue;
            }
            if ($item === null) {
                if ($base !== null && ($base['itemId'] ?? '') !== '') {
                    $operations[] = ['op' => 'remote_missing', 'useCaseId' => $caseId, 'itemId' => (string) $base['itemId']];
                } elseif ($row) {
                    $operations[] = $this->createOp($caseId, $row);
                }
                continue;
            }

            $push = [];
            $pull = [];
            $conflict = [];
            $skip = [];
            $equal = [];
            foreach ($this->map->fields() as $field) {
                $column = $this->map->column($field);
                $owner = $this->map->owner($field);
                try {
                    $l = $this->map->local($field, $row[$field] ?? null);
                } catch (UnexpectedValueException) {
                    $skip[$field] = ['column' => $column, 'reason' => 'invalid_local'];
                    continue;
                }
                try {
                    $r = $this->map->remote($field, $item['fields'][$column] ?? null);
                } catch (UnexpectedValueException) {
                    $skip[$field] = ['column' => $column, 'reason' => 'invalid_remote'];
                    continue;
                }
                if ($l === $r) {
                    $equal[$field] = $r;
                    continue;
                }
                $hasBase = is_array($base['fields'] ?? null) && array_key_exists($field, $base['fields']);
                $b = $hasBase ? $base['fields'][$field] : null;
                $action = match (true) {
                    $owner === 'sharepoint' => $r === null ? 'remote_empty' : 'pull',
                    $owner === 'pivot' => $l === null ? 'local_cleared' : 'push',
                    !$hasBase => $l === null ? 'pull' : ($r === null ? 'push' : 'conflict'),
                    $l !== $b && $r === $b => $l === null ? 'local_cleared' : 'push',
                    $l === $b && $r !== $b => $r === null ? 'remote_cleared' : 'pull',
                    default => 'conflict',
                };
                if ($action === 'push') {
                    $push[$field] = ['column' => $column, 'before' => $r, 'after' => $l];
                } elseif ($action === 'pull') {
                    $pull[$field] = ['column' => $column, 'before' => $l, 'after' => $r];
                } elseif ($action === 'conflict') {
                    $conflict[$field] = ['column' => $column, 'baseline' => $b, 'local' => $l, 'remote' => $r];
                } else {
                    $skip[$field] = ['column' => $column, 'reason' => $action];
                }
            }
            $common = ['useCaseId' => $caseId, 'itemId' => $item['id']];
            if ($push) {
                $operations[] = ['op' => 'push'] + $common + ['eTag' => $item['eTag'], 'fields' => $push];
            }
            if ($pull) {
                $operations[] = ['op' => 'pull'] + $common + ['fields' => $pull];
            }
            if ($conflict) {
                $operations[] = ['op' => 'conflict'] + $common + ['fields' => $conflict];
            }
            if ($skip) {
                $reasons = array_values(array_unique(array_column($skip, 'reason')));
                $operations[] = ['op' => 'skip'] + $common + ['reason' => implode(',', $reasons), 'fields' => $skip];
            }
            $snapshot[] = ['useCaseId' => $caseId, 'itemId' => $item['id'], 'eTag' => $item['eTag'], 'equal' => $equal];
        }
        return [$operations, $snapshot, $warnings];
    }

    private function createOp(string $caseId, array $row): array
    {
        if (empty($this->config['allow_create'])) {
            return ['op' => 'skip', 'useCaseId' => $caseId, 'reason' => 'no_remote_item'];
        }
        $fields = [];
        foreach ($this->map->fields() as $field) {
            if ($this->map->owner($field) === 'sharepoint') {
                continue;
            }
            $value = $this->safeLocal($field, $row[$field] ?? null);
            if ($value !== null) {
                $fields[$field] = ['column' => $this->map->column($field), 'before' => null, 'after' => $value];
            }
        }
        return $fields
            ? ['op' => 'create', 'useCaseId' => $caseId, 'fields' => $fields]
            : ['op' => 'skip', 'useCaseId' => $caseId, 'reason' => 'no_remote_item'];
    }

    private function run(array $plan): array
    {
        $now = ($this->now)();
        $runId = gmdate('Ymd-His', $now) . '-' . bin2hex(random_bytes(4));
        $this->store->runDir($runId);
        $touchesLocal = (bool) array_filter($plan['operations'], static fn (array $op): bool => in_array($op['op'], ['pull', 'restore_local'], true));
        if ($touchesLocal) {
            $this->store->backupLocal($runId, $this->scoresFile);
        }

        $state = $this->store->state();
        $manifest = [
            'runId' => $runId, 'planId' => $plan['planId'], 'kind' => $plan['kind'], 'user' => $this->user['upn'] ?? '',
            'startedAt' => gmdate('c', $now), 'siteId' => $this->config['site_id'], 'listId' => $this->config['list_id'],
            'local' => [], 'remote' => [], 'created' => [],
        ];
        $applied = 0;
        $conflicts = 0;
        $skipped = 0;
        $open = [];
        $results = [];
        $pushed = [];
        $stopped = null;

        try {
            foreach ($plan['operations'] as $op) {
                $result = $this->runOp($op, $runId, $state, $manifest, $pushed);
                $results[] = ['op' => $op['op'], 'useCaseId' => $op['useCaseId'], 'result' => $result];
                if ($result === 'applied') {
                    $applied++;
                } elseif ($result === 'conflict' || str_starts_with($result, 'conflict:')) {
                    $conflicts++;
                    $open[] = $op + ['found' => $result];
                } else {
                    $skipped++;
                }
            }
            foreach ($plan['snapshot'] as $entry) {
                $caseId = (string) $entry['useCaseId'];
                $item = is_array($state['items'][$caseId] ?? null) ? $state['items'][$caseId] : ['fields' => []];
                $item['itemId'] = (string) $entry['itemId'];
                if (!isset($pushed[$caseId])) {
                    $item['eTag'] = (string) $entry['eTag'];
                }
                unset($item['remoteMissing']);
                foreach ((array) $entry['equal'] as $field => $value) {
                    $item['fields'][$field] = $value;
                }
                $state['items'][$caseId] = $item;
            }
        } catch (Throwable $error) {
            $stopped = $error;
        }

        $state['lastRun'] = [
            'runId' => $runId, 'planId' => $plan['planId'], 'kind' => $plan['kind'], 'at' => gmdate('c', $now),
            'user' => $this->user['upn'] ?? '', 'applied' => $applied, 'conflicts' => $conflicts, 'skipped' => $skipped,
            'stopped' => $stopped?->getMessage(),
        ];
        $state['openConflicts'] = $open;
        $this->store->saveState($state);
        $this->store->saveRun($runId, $manifest + ['finishedAt' => gmdate('c', ($this->now)()), 'results' => $results]);
        $this->store->markPlanApplied($plan['planId']);

        if ($stopped !== null) {
            $this->store->audit(['at' => gmdate('c'), 'user' => $this->user['upn'] ?? '', 'runId' => $runId, 'planId' => $plan['planId'], 'op' => 'run', 'result' => 'stopped', 'error' => $stopped->getMessage()]);
            throw $stopped instanceof SyncRefused || $stopped instanceof GraphError ? $stopped : new SyncRefused('The run stopped: ' . $stopped->getMessage(), 500);
        }
        return [
            'runId' => $runId,
            'applied' => $applied,
            'conflicts' => $conflicts,
            'skipped' => $skipped,
            'backupDir' => $this->store->backupDir($runId),
            'results' => $results,
        ];
    }

    private function runOp(array $op, string $runId, array &$state, array &$manifest, array &$pushed): string
    {
        $caseId = (string) $op['useCaseId'];
        $site = $this->config['site_id'];
        $list = $this->config['list_id'];
        $result = 'skipped';
        $before = [];
        $after = [];
        foreach ((array) ($op['fields'] ?? []) as $field => $change) {
            if (array_key_exists('after', $change)) {
                $before[$field] = $change['before'];
                $after[$field] = $change['after'];
            }
        }

        if ($op['op'] === 'push' || $op['op'] === 'restore_remote') {
            $current = $this->graph->getItem($site, $list, (string) $op['itemId']);
            if ($current === null) {
                $result = 'remote_missing';
            } elseif ($current['eTag'] !== $op['eTag']) {
                $result = 'conflict:changed_since_plan';
            } else {
                $this->store->backupRemote($runId, $list, $current, $caseId);
                $manifest['remote'][] = (string) $op['itemId'];
                $body = [];
                foreach ($op['fields'] as $change) {
                    $body[$change['column']] = $change['after'];
                }
                try {
                    $saved = $this->graph->updateItemFields($site, $list, (string) $op['itemId'], $body, $op['eTag']);
                    $item = is_array($state['items'][$caseId] ?? null) ? $state['items'][$caseId] : ['fields' => []];
                    $item['itemId'] = (string) $op['itemId'];
                    $item['eTag'] = $saved['eTag'];
                    foreach ($after as $field => $value) {
                        $item['fields'][$field] = $value;
                    }
                    $state['items'][$caseId] = $item;
                    $pushed[$caseId] = true;
                    $result = 'applied';
                } catch (PreconditionFailed) {
                    $result = 'conflict:precondition_failed';
                }
            }
        } elseif ($op['op'] === 'create') {
            $body = [$this->config['key_column'] => $caseId];
            foreach ($op['fields'] as $change) {
                $body[$change['column']] = $change['after'];
            }
            $created = $this->graph->createItem($site, $list, $body);
            $manifest['created'][] = $created['id'];
            $state['items'][$caseId] = ['itemId' => $created['id'], 'eTag' => $created['eTag'], 'fields' => $after];
            $pushed[$caseId] = true;
            $result = 'applied';
        } elseif ($op['op'] === 'pull' || $op['op'] === 'restore_local') {
            $result = $this->writeLocal($caseId, $op['fields']) ? 'applied' : 'conflict:local_changed_since_plan';
            if ($result === 'applied') {
                $manifest['local'][$caseId] = array_values(array_unique(array_merge($manifest['local'][$caseId] ?? [], array_keys($op['fields']))));
                $item = is_array($state['items'][$caseId] ?? null) ? $state['items'][$caseId] : ['fields' => []];
                if (isset($op['itemId'])) {
                    $item['itemId'] = (string) $op['itemId'];
                }
                foreach ($after as $field => $value) {
                    $item['fields'][$field] = $value;
                }
                $state['items'][$caseId] = $item;
            }
        } elseif ($op['op'] === 'conflict') {
            $result = 'conflict';
        } elseif ($op['op'] === 'remote_missing') {
            if (is_array($state['items'][$caseId] ?? null)) {
                $state['items'][$caseId]['remoteMissing'] = true;
            }
            $result = 'remote_missing';
        }

        $this->store->audit([
            'at' => gmdate('c'), 'user' => $this->user['upn'] ?? '', 'runId' => $runId, 'planId' => $manifest['planId'],
            'op' => $op['op'], 'useCaseId' => $caseId, 'itemId' => (string) ($op['itemId'] ?? ''), 'result' => $result,
            'before' => $before, 'after' => $after, 'reason' => $op['reason'] ?? null,
        ]);
        return $result;
    }

    /** Write the given fields into scores.json if each still holds the value the plan saw. */
    private function writeLocal(string $caseId, array $fields): bool
    {
        $handle = @fopen($this->scoresFile, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            throw new SyncRefused('scores.json could not be opened for writing.', 500);
        }
        try {
            $raw = stream_get_contents($handle);
            $scores = json_decode((string) $raw, true);
            if (trim((string) $raw) !== '' && !is_array($scores)) {
                throw new SyncRefused('scores.json is not valid JSON, so it was left alone.', 500);
            }
            $scores = is_array($scores) ? $scores : [];
            $row = is_array($scores[$caseId] ?? null) ? $scores[$caseId] : [];
            foreach ($fields as $field => $change) {
                if ($this->safeLocal($field, $row[$field] ?? null) !== $change['before']) {
                    return false;
                }
            }
            foreach ($fields as $field => $change) {
                if ($change['after'] === null) {
                    unset($row[$field]);
                } else {
                    $row[$field] = $change['after'];
                }
            }
            if ($row) {
                $scores[$caseId] = $row;
            } else {
                unset($scores[$caseId]);
            }
            $encoded = json_encode($scores, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            if ($encoded === false) {
                throw new SyncRefused('scores.json could not be encoded.', 500);
            }
            ftruncate($handle, 0);
            rewind($handle);
            if (fwrite($handle, $encoded . "\n") === false || !fflush($handle)) {
                throw new SyncRefused('scores.json could not be written.', 500);
            }
            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function readScores(): array
    {
        if (!is_file($this->scoresFile)) {
            return [];
        }
        $handle = fopen($this->scoresFile, 'rb');
        if ($handle === false) {
            throw new SyncRefused('scores.json could not be read.', 500);
        }
        flock($handle, LOCK_SH);
        $raw = stream_get_contents($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
        $scores = json_decode((string) $raw, true);
        if (trim((string) $raw) !== '' && !is_array($scores)) {
            throw new SyncRefused('scores.json is not valid JSON, so the sync stopped.', 500);
        }
        $out = [];
        foreach (is_array($scores) ? $scores : [] as $id => $row) {
            $out[(string) $id] = $row;
        }
        return $out;
    }

    private function safeLocal(string $field, mixed $value): int|string|null
    {
        try {
            return $this->map->local($field, $value);
        } catch (UnexpectedValueException) {
            return null;
        }
    }

    private function savePlan(string $kind, string $direction, array $operations, array $snapshot, array $warnings, int $cap, ?string $runId): array
    {
        $counts = ['pull' => 0, 'push' => 0, 'create' => 0, 'conflict' => 0, 'skip' => 0];
        foreach ($operations as $op) {
            $bucket = match ($op['op']) {
                'push', 'restore_remote' => 'push',
                'pull', 'restore_local' => 'pull',
                'create' => 'create',
                'conflict' => 'conflict',
                default => 'skip',
            };
            $counts[$bucket]++;
        }
        $writes = $counts['pull'] + $counts['push'] + $counts['create'];
        $now = ($this->now)();
        $plan = [
            'planId' => bin2hex(random_bytes(12)),
            'kind' => $kind,
            'restoresRun' => $runId,
            'direction' => $direction,
            'createdAt' => $now,
            'expiresAt' => $now + (int) ($this->config['plan_ttl_seconds'] ?? 1800),
            'user' => $this->user['upn'] ?? '',
            'mode' => $this->mode(),
            'list' => ['name' => (string) ($this->config['list_name'] ?? ''), 'id' => $this->config['list_id'], 'site' => $this->config['site_id']],
            'counts' => $counts,
            'writes' => $writes,
            'maxWrites' => $cap,
            'blocked' => $writes > $cap,
            'unmapped' => $this->map->unmapped(),
            'warnings' => $warnings,
            'operations' => $operations,
            'snapshot' => $snapshot,
        ];
        $plan['hash'] = $this->hash($plan);
        $this->store->savePlan($plan);
        return $this->view($plan);
    }

    private function view(array $plan): array
    {
        unset($plan['snapshot']);
        return $plan;
    }

    private function hash(array $plan): string
    {
        unset($plan['hash']);
        return hash('sha256', (string) json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function locked(callable $body): array
    {
        $handle = $this->store->lock();
        if ($handle === null) {
            throw new SyncBusy();
        }
        try {
            return $body();
        } finally {
            $this->store->unlock($handle);
        }
    }

    private function requireConfigured(): void
    {
        if (!self::configured($this->config)) {
            throw new SyncRefused('The SharePoint list is not configured yet (placeholders in api/lib/microsoft-config.php).', 503);
        }
    }

    private function mode(): string
    {
        return ($this->config['mode'] ?? '') === 'write' ? 'write' : 'dry-run';
    }

    private function cap(): int
    {
        return max(1, (int) ($this->config['max_writes_per_run'] ?? 50));
    }
}

/**
 * The force-sync endpoint, without globals so tests can drive it.
 *
 * @param array $deps config (whole microsoft-config.php array), signIn (fn (): TokenProvider),
 *                    sync (fn (string $token, array $user): ScorecardSync), isAdmin (fn (string $upn): bool),
 *                    release (optional fn (): void, called once the session is no longer written)
 * @return array{0: int, 1: array} HTTP status and JSON body
 */
function scorecardSyncHandle(string $action, string $method, array $body, array $headers, array &$session, array $deps): array
{
    if (!in_array($action, ['plan', 'apply', 'status', 'restore-plan'], true)) {
        return [404, ['ok' => false, 'error' => 'Unknown action. Use plan, apply, status or restore-plan.']];
    }
    if ($method !== 'POST' && !($action === 'status' && $method === 'GET')) {
        return [405, ['ok' => false, 'error' => 'Use POST.']];
    }
    $config = (array) ($deps['config']['scorecard_sync'] ?? []);
    $mode = ($config['mode'] ?? '') === 'write' ? 'write' : 'dry-run';
    if (empty($config['enabled'])) {
        if ($action === 'status') {
            return [200, ['ok' => true, 'enabled' => false, 'mode' => $mode, 'configured' => ScorecardSync::configured($config)]];
        }
        return [503, ['ok' => false, 'error' => 'Scorecard sync with SharePoint is turned off.', 'enabled' => false]];
    }

    $signIn = ($deps['signIn'])();
    $user = $signIn->user();
    if ($user === null) {
        return [401, ['ok' => false, 'error' => 'Sign in with Microsoft to sync the scorecard with SharePoint.', 'login' => true, 'signIn' => 'api/microsoft.php']];
    }

    if (!is_string($session['scorecard_sync_csrf'] ?? null) || strlen($session['scorecard_sync_csrf']) < 32) {
        $session['scorecard_sync_csrf'] = bin2hex(random_bytes(32));
    }
    $csrf = $session['scorecard_sync_csrf'];
    if ($action !== 'status') {
        $given = '';
        foreach ($headers as $name => $value) {
            if (strcasecmp((string) $name, 'X-CSRF-Token') === 0) {
                $given = (string) $value;
            }
        }
        if (!hash_equals($csrf, $given)) {
            return [403, ['ok' => false, 'error' => 'Missing or stale X-CSRF-Token. Read csrf from the status action.']];
        }
    }

    $isAdmin = (bool) ($deps['isAdmin'])((string) $user['upn']);
    if ($action !== 'status' && !ScorecardSync::configured($config)) {
        return [503, ['ok' => false, 'error' => 'The SharePoint list is not configured yet (placeholders in api/lib/microsoft-config.php).', 'configured' => false]];
    }
    if (($action === 'apply' || $action === 'restore-plan') && !$isAdmin) {
        return [403, ['ok' => false, 'error' => 'Only a Pivot admin can apply a sync or restore one.']];
    }
    $cap = max(1, (int) ($config['max_writes_per_run'] ?? 50));
    if ($action === 'plan' && isset($body['maxWrites']) && (int) $body['maxWrites'] > $cap && !$isAdmin) {
        return [403, ['ok' => false, 'error' => 'Only a Pivot admin can raise the write cap.']];
    }
    if ($action === 'apply' && ($body['confirm'] ?? null) !== true) {
        return [400, ['ok' => false, 'error' => 'Send confirm: true to apply a plan.']];
    }

    try {
        $token = in_array($action, ['plan', 'apply', 'restore-plan'], true) ? $signIn->accessToken('scorecard') : '';
        if (isset($deps['release'])) {
            ($deps['release'])();
        }
        $sync = ($deps['sync'])($token, $user);
        switch ($action) {
            case 'status':
                return [200, ['ok' => true] + $sync->status() + ['user' => $user, 'isAdmin' => $isAdmin, 'csrf' => $csrf]];
            case 'plan':
                return [200, ['ok' => true] + $sync->plan([
                    'direction' => (string) ($body['direction'] ?? 'both'),
                    'maxWrites' => isset($body['maxWrites']) ? (int) $body['maxWrites'] : null,
                ])];
            case 'apply':
                $planId = (string) ($body['planId'] ?? '');
                if ($mode !== 'write') {
                    return [423, ['ok' => false, 'error' => 'Dry-run mode: nothing was written. Set mode to write in api/lib/microsoft-config.php to apply.', 'mode' => 'dry-run', 'plan' => $sync->storedPlan($planId)]];
                }
                return [200, ['ok' => true] + $sync->apply($planId, (string) ($body['hash'] ?? ''), true)];
            default:
                return [200, ['ok' => true] + $sync->restorePlan((string) ($body['runId'] ?? ''))];
        }
    } catch (SyncRefused $error) {
        return [$error->status, ['ok' => false, 'error' => $error->getMessage()]];
    } catch (NotSignedIn $error) {
        return [401, ['ok' => false, 'error' => $error->getMessage(), 'login' => true, 'signIn' => 'api/microsoft.php']];
    } catch (GraphError $error) {
        if ($error->status === 401) {
            return [401, ['ok' => false, 'error' => 'Microsoft sign-in expired. Sign in again.', 'login' => true, 'signIn' => 'api/microsoft.php']];
        }
        if ($error->status === 403) {
            return [403, ['ok' => false, 'error' => 'Your Microsoft account cannot open that SharePoint list.']];
        }
        return [502, ['ok' => false, 'error' => 'SharePoint answered: ' . $error->getMessage()]];
    }
}
