<?php
declare(strict_types=1);

/**
 * Offline tests for the scorecard ↔ SharePoint sync. Nothing here reaches the
 * network: Graph is FakeGraphHttp and Microsoft sign-in gets a fake token endpoint.
 *
 *   php tests/scorecard-sync/run.php            all tests
 *   php tests/scorecard-sync/run.php restore    tests whose name contains "restore"
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 2);
require $root . '/api/lib/admin.php';
require $root . '/api/lib/microsoft-signin.php';
require $root . '/api/lib/graph-client.php';
require $root . '/api/lib/scorecard-sync.php';
require __DIR__ . '/FakeGraphHttp.php';

const BASE = 'https://graph.microsoft.com/v1.0';
const SITE = 'contoso.sharepoint.com,11111111-1111-1111-1111-111111111111,22222222-2222-2222-2222-222222222222';
const LIST_ID = '33333333-3333-3333-3333-333333333333';
const TENANT_ID = '325b4494-1587-40d5-bb31-8b660b7f1038';

$tests = [];
$filter = $argv[1] ?? '';

function test(string $name, callable $body): void
{
    global $tests;
    $tests[$name] = $body;
}

final class Failed extends Exception
{
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new Failed($message);
    }
}

function same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new Failed(($message !== '' ? $message . ': ' : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function throwsLike(callable $body, string $class, string $message = ''): Throwable
{
    try {
        $body();
    } catch (Throwable $error) {
        if ($error instanceof $class) {
            return $error;
        }
        throw new Failed(($message ?: 'wrong exception') . ': ' . get_class($error) . ' ' . $error->getMessage());
    }
    throw new Failed($message ?: 'expected ' . $class);
}

function tempDir(): string
{
    $dir = sys_get_temp_dir() . '/pivot-sync-test-' . bin2hex(random_bytes(5));
    mkdir($dir, 0700, true);
    return $dir;
}

function syncConfig(string $dir, array $over = []): array
{
    return array_replace_recursive([
        'enabled' => true,
        'mode' => 'write',
        'site_id' => SITE,
        'list_id' => LIST_ID,
        'list_name' => 'Project Scores (test copy)',
        'key_column' => 'UseCaseIDLookupId',
        'fields' => [
            'level' => ['column' => 'Level', 'type' => 'level', 'owner' => 'sharepoint'],
            'strategic' => ['column' => 'StrategicValue', 'type' => 'score', 'owner' => 'shared'],
            'reach' => ['column' => 'ReachValue', 'type' => 'score', 'owner' => 'shared'],
            'business' => ['column' => 'BusinessValue', 'type' => 'score', 'owner' => 'shared'],
            'strategicWhy' => ['column' => 'StrategicWhy', 'type' => 'text', 'owner' => 'shared'],
            'reachWhy' => ['column' => 'ReachWhy', 'type' => 'text', 'owner' => 'pivot'],
        ],
        'max_writes_per_run' => 50,
        'plan_ttl_seconds' => 1800,
        'allow_create' => false,
        'storage_dir' => $dir . '/sync',
    ], $over);
}

function msConfig(): array
{
    return [
        'tenant' => TENANT_ID,
        'client' => '14d82eec-204b-4c2f-b7e8-296a70dab67e',
        'cloud' => 'commercial',
        'scopes' => [
            'scorecard' => ['Sites.ReadWrite.All'],
            'calendar' => ['Calendars.ReadBasic'],
        ],
    ];
}

final class Kit
{
    public FakeGraphHttp $fake;
    public ScorecardSync $sync;
    public string $scores;
    public array $config;
    public array $slept = [];
    public int $now;

    public function __construct(public string $dir, array $local = [], array $over = [], int $pageSize = 200)
    {
        $this->scores = $dir . '/scores.json';
        file_put_contents($this->scores, json_encode($local, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
        $this->config = syncConfig($dir, $over);
        $this->fake = FakeGraphHttp::fromFixture(BASE, SITE, LIST_ID, $pageSize);
        $this->now = 1794400000;
        $this->sync = $this->build();
    }

    public function build(): ScorecardSync
    {
        $graph = new GraphClient($this->fake, static fn (): string => 'token-abc', BASE, function (int $seconds): void {
            $this->slept[] = $seconds;
        });
        return new ScorecardSync(
            $this->config,
            $graph,
            new SyncStore($this->config['storage_dir']),
            $this->scores,
            ['upn' => 'scott.test@forces.gc.ca', 'name' => 'Scott Test'],
            fn (): int => $this->now
        );
    }

    public function local(): array
    {
        return json_decode((string) file_get_contents($this->scores), true);
    }

    /** Pull everything once so later tests start from a settled baseline. */
    public function settle(): void
    {
        $plan = $this->sync->plan(['direction' => 'both']);
        $this->sync->apply($plan['planId'], $plan['hash'], true);
        $this->fake->calls = [];
    }
}

function ops(array $plan, string $kind): array
{
    return array_values(array_filter($plan['operations'], static fn (array $op): bool => $op['op'] === $kind));
}

function opFor(array $plan, string $kind, string $useCase): ?array
{
    foreach (ops($plan, $kind) as $op) {
        if ($op['useCaseId'] === $useCase) {
            return $op;
        }
    }
    return null;
}

// 1. Round trip
test('1 round trip: pull into an empty scorecard, then plan again is empty', function (): void {
    $kit = new Kit(tempDir());
    $plan = $kit->sync->plan(['direction' => 'both']);
    check($plan['counts']['pull'] >= 3, 'expected pulls, got ' . json_encode($plan['counts']));
    same(0, $plan['counts']['push'], 'push count');
    $kit->sync->apply($plan['planId'], $plan['hash'], true);
    $local = $kit->local();
    same(3, $local['215']['strategic']);
    same('L2', $local['215']['level']);
    $again = $kit->sync->plan(['direction' => 'both']);
    same(0, $again['counts']['pull'] + $again['counts']['push'] + $again['counts']['conflict'] + $again['counts']['create'], 'second plan operations');
});

// 2. Value fidelity
test('2 fidelity: French text, numbers sent as 4.0, null vs empty all survive a round trip', function (): void {
    $kit = new Kit(tempDir());
    $kit->settle();
    $local = $kit->local();
    same('Outil propre à la CFD — portée régionale, révisé à l’été', $local['215']['strategicWhy']);
    same(4, $local['215']['business'], 'BusinessValue 4.0 reads as the integer 4');
    check(!array_key_exists('strategicWhy', $local['212']), 'an empty SharePoint string stays out of scores.json');
    check(!array_key_exists('strategic', $local['212']), 'a null SharePoint number stays out of scores.json');

    $local['215']['reachWhy'] = 'Portée : « toutes les L1 » — Défense nationale, été 2026';
    file_put_contents($kit->scores, json_encode($local, JSON_UNESCAPED_UNICODE));
    $plan = $kit->sync->plan(['direction' => 'both']);
    $kit->sync->apply($plan['planId'], $plan['hash'], true);
    same('Portée : « toutes les L1 » — Défense nationale, été 2026', $kit->fake->items['11']['fields']['ReachWhy']);
    $again = $kit->sync->plan(['direction' => 'both']);
    same(0, $again['counts']['pull'] + $again['counts']['push'] + $again['counts']['conflict']);
});

// 3. Local edit
test('3 local edit of one field sends exactly one PATCH, with If-Match, carrying only that column', function (): void {
    $kit = new Kit(tempDir());
    $kit->settle();
    $local = $kit->local();
    $local['209']['strategic'] = 5;
    file_put_contents($kit->scores, json_encode($local));
    $plan = $kit->sync->plan(['direction' => 'both']);
    same(1, $plan['counts']['push']);
    same(0, $plan['counts']['pull']);
    $result = $kit->sync->apply($plan['planId'], $plan['hash'], true);
    same(1, $result['applied']);
    $patches = $kit->fake->patches();
    same(1, count($patches), 'PATCH count');
    same(BASE . '/sites/' . SITE . '/lists/' . LIST_ID . '/items/13/fields', $patches[0]['url']);
    same('"6a1f0c2e-0b6e-4c55-9d3e-000000000013,7"', $patches[0]['headers']['If-Match']);
    same(['StrategicValue' => 5], json_decode((string) $patches[0]['body'], true));
});

// 4. Remote edit
test('4 remote edit is pulled after a local backup, with no PATCH', function (): void {
    $kit = new Kit(tempDir());
    $kit->settle();
    $kit->fake->remoteEdit('13', ['ReachValue' => 3]);
    $plan = $kit->sync->plan(['direction' => 'both']);
    same(1, $plan['counts']['pull']);
    $result = $kit->sync->apply($plan['planId'], $plan['hash'], true);
    same(3, $kit->local()['209']['reach']);
    same([], $kit->fake->patches());
    $backup = $result['backupDir'] . '/local-scores.json';
    check(is_file($backup), 'local backup exists');
    same(5, json_decode((string) file_get_contents($backup), true)['209']['reach'], 'backup holds the pre-pull value');
});

// 5. Both sides, different fields
test('5 both sides edit different fields: both land, no conflict', function (): void {
    $kit = new Kit(tempDir());
    $kit->settle();
    $local = $kit->local();
    $local['209']['strategic'] = 5;
    file_put_contents($kit->scores, json_encode($local));
    $kit->fake->remoteEdit('13', ['BusinessValue' => 2]);
    $plan = $kit->sync->plan(['direction' => 'both']);
    same(0, $plan['counts']['conflict']);
    $kit->sync->apply($plan['planId'], $plan['hash'], true);
    same(5, $kit->fake->items['13']['fields']['StrategicValue']);
    same(2, $kit->local()['209']['business']);
    same(5, $kit->local()['209']['strategic']);
});

// 6. Both sides, same field
test('6 both sides edit the same field differently: conflict and zero writes either way', function (): void {
    $kit = new Kit(tempDir());
    $kit->settle();
    $local = $kit->local();
    $local['209']['strategic'] = 5;
    file_put_contents($kit->scores, json_encode($local));
    $kit->fake->remoteEdit('13', ['StrategicValue' => 1]);
    $before = (string) file_get_contents($kit->scores);
    $plan = $kit->sync->plan(['direction' => 'both']);
    same(1, $plan['counts']['conflict']);
    $conflict = opFor($plan, 'conflict', '209');
    same(['column' => 'StrategicValue', 'baseline' => 4, 'local' => 5, 'remote' => 1], $conflict['fields']['strategic']);
    $result = $kit->sync->apply($plan['planId'], $plan['hash'], true);
    same([], $kit->fake->patches());
    same($before, (string) file_get_contents($kit->scores), 'scores.json untouched');
    same(1, $result['conflicts']);
    same(1, count($kit->sync->status()['openConflicts']));
});

// 7. 412 on PATCH
test('7 a 412 on PATCH becomes a conflict, is never retried with *, and other items continue', function (): void {
    $kit = new Kit(tempDir());
    $kit->settle();
    $local = $kit->local();
    $local['209']['strategic'] = 5;
    $local['215']['strategic'] = 1;
    file_put_contents($kit->scores, json_encode($local));
    $plan = $kit->sync->plan(['direction' => 'both']);
    same(2, $plan['counts']['push']);
    $kit->fake->script[] = ['method' => 'PATCH', 'status' => 412, 'body' => ['error' => ['code' => 'resourceModified', 'message' => 'modified']]];
    $result = $kit->sync->apply($plan['planId'], $plan['hash'], true);
    same(1, $result['applied']);
    same(1, $result['conflicts']);
    $patches = $kit->fake->patches();
    same(2, count($patches), 'one failed PATCH and one good one, no retry');
    foreach ($patches as $patch) {
        check($patch['headers']['If-Match'] !== '*', 'never If-Match *');
    }
});

// 8. eTag drift between plan and apply
test('8 an item edited between plan and apply is refused, with no PATCH for it', function (): void {
    $kit = new Kit(tempDir());
    $kit->settle();
    $local = $kit->local();
    $local['209']['strategic'] = 5;
    file_put_contents($kit->scores, json_encode($local));
    $plan = $kit->sync->plan(['direction' => 'both']);
    $kit->fake->remoteEdit('13', ['Title' => 'Renamed in SharePoint']);
    $result = $kit->sync->apply($plan['planId'], $plan['hash'], true);
    same(0, $result['applied']);
    same(1, $result['conflicts']);
    same([], $kit->fake->patches());
});

// 9. Tampered or stale plan
test('9 a tampered or stale plan hash is rejected', function (): void {
    $kit = new Kit(tempDir());
    $kit->settle();
    $local = $kit->local();
    $local['209']['strategic'] = 5;
    file_put_contents($kit->scores, json_encode($local));
    $plan = $kit->sync->plan(['direction' => 'both']);
    $error = throwsLike(fn () => $kit->sync->apply($plan['planId'], str_repeat('0', 64), true), SyncRefused::class);
    same(409, $error->status);

    $file = $kit->config['storage_dir'] . '/plans/' . $plan['planId'] . '.json';
    $stored = json_decode((string) file_get_contents($file), true);
    $stored['operations'][0]['fields']['strategic']['after'] = 1;
    file_put_contents($file, json_encode($stored));
    $error = throwsLike(fn () => $kit->sync->apply($plan['planId'], $plan['hash'], true), SyncRefused::class, 'edited plan file');
    same(409, $error->status);

    $fresh = $kit->sync->plan(['direction' => 'both']);
    $kit->now += 3600;
    $error = throwsLike(fn () => $kit->sync->apply($fresh['planId'], $fresh['hash'], true), SyncRefused::class, 'old plan');
    same(409, $error->status);
    same([], $kit->fake->patches());

    throwsLike(fn () => $kit->sync->apply('../../etc/passwd', $fresh['hash'], true), SyncRefused::class, 'path in plan id');
    throwsLike(fn () => $kit->sync->apply($fresh['planId'], $fresh['hash'], false), SyncRefused::class, 'no confirm');
});

// 10. Dry-run mode
test('10 dry-run mode: plan is GET-only and apply answers 423 with the plan unchanged', function (): void {
    $dir = tempDir();
    $kit = new Kit($dir, ['209' => ['strategic' => 1]], ['mode' => 'dry-run']);
    $harness = handlerHarness($kit, 'scott.test@forces.gc.ca');
    [$status, $plan] = $harness('plan', ['direction' => 'both']);
    same(200, $status);
    [$status, $body] = $harness('apply', ['planId' => $plan['planId'], 'hash' => $plan['hash'], 'confirm' => true]);
    same(423, $status);
    same($plan['planId'], $body['plan']['planId']);
    same(['GET'], array_values(array_unique($kit->fake->methods())));
    same(1, json_decode((string) file_get_contents($kit->scores), true)['209']['strategic']);
});

// 11. Backups
test('11 a backup is written before each PATCH; if the backup cannot be written, the run stops with no PATCH', function (): void {
    $kit = new Kit(tempDir());
    $kit->settle();
    $local = $kit->local();
    $local['209']['strategic'] = 5;
    file_put_contents($kit->scores, json_encode($local));
    $plan = $kit->sync->plan(['direction' => 'both']);
    $result = $kit->sync->apply($plan['planId'], $plan['hash'], true);
    $backup = $result['backupDir'] . '/remote/' . LIST_ID . '/13.json';
    check(is_file($backup), 'remote pre-image written');
    same(4, json_decode((string) file_get_contents($backup), true)['item']['fields']['StrategicValue']);

    $local['209']['strategic'] = 2;
    file_put_contents($kit->scores, json_encode($local));
    $kit->fake->calls = [];
    $plan = $kit->sync->plan(['direction' => 'both']);
    $backups = $kit->config['storage_dir'] . '/backups';
    chmod($backups, 0500);
    try {
        $error = throwsLike(fn () => $kit->sync->apply($plan['planId'], $plan['hash'], true), SyncRefused::class);
        same(500, $error->status);
    } finally {
        chmod($backups, 0700);
    }
    same([], $kit->fake->patches());
    same(5, $kit->fake->items['13']['fields']['StrategicValue']);
});

// 12. Item deleted in SharePoint
test('12 an item gone from SharePoint keeps its local row and is flagged remote_missing', function (): void {
    $kit = new Kit(tempDir());
    $kit->settle();
    unset($kit->fake->items['13']);
    $plan = $kit->sync->plan(['direction' => 'both']);
    check(opFor($plan, 'remote_missing', '209') !== null, 'flagged');
    $kit->sync->apply($plan['planId'], $plan['hash'], true);
    same(4, $kit->local()['209']['strategic'], 'local row kept');
    same(['GET'], array_values(array_unique($kit->fake->methods())));
});

// 13. Row removed in Pivot
test('13 a row cleared in Pivot sends no DELETE and blanks nothing in SharePoint', function (): void {
    $kit = new Kit(tempDir());
    $kit->settle();
    $local = $kit->local();
    unset($local['209']);
    $local['215']['strategicWhy'] = '';
    file_put_contents($kit->scores, json_encode($local));
    $plan = $kit->sync->plan(['direction' => 'both']);
    same(0, $plan['counts']['push']);
    check($plan['counts']['skip'] >= 2, 'reported as skips');
    $kit->sync->apply($plan['planId'], $plan['hash'], true);
    check(!in_array('DELETE', $kit->fake->methods(), true), 'no DELETE');
    same([], $kit->fake->patches());
    same(4, $kit->fake->items['13']['fields']['StrategicValue']);
});

// 14. No delete anywhere
test('14 GraphClient has no delete method and the HTTP layer refuses DELETE', function (): void {
    foreach ([GraphClient::class, CurlGraphHttp::class, ScorecardSync::class, SyncStore::class] as $class) {
        foreach ((new ReflectionClass($class))->getMethods() as $method) {
            check(!preg_match('/delete|remove|purge|recycle/i', $method->getName()), $class . '::' . $method->getName());
        }
    }
    $fake = FakeGraphHttp::fromFixture(BASE, SITE, LIST_ID);
    $client = new GraphClient($fake, static fn (): string => 't', BASE);
    $send = new ReflectionMethod(GraphClient::class, 'send');
    throwsLike(fn () => $send->invoke($client, 'DELETE', BASE . '/sites/' . SITE . '/lists/' . LIST_ID . '/items/13', null), LogicException::class);
    throwsLike(fn () => $send->invoke($client, 'PUT', BASE . '/sites/' . SITE . '/lists/' . LIST_ID . '/items/13', []), LogicException::class);
    same([], $fake->calls, 'nothing reached the wire');
    throwsLike(fn () => (new CurlGraphHttp())->send('DELETE', BASE . '/x', [], null), LogicException::class);
});

// 15. Field allowlist
test('15 unmapped, system and bookkeeping fields are never sent', function (): void {
    $kit = new Kit(tempDir());
    $kit->settle();
    $local = $kit->local();
    $local['209']['strategic'] = 5;
    $local['209']['Author'] = 'someone else';
    $local['209']['notes'] = 'local only';
    file_put_contents($kit->scores, json_encode($local));
    $plan = $kit->sync->plan(['direction' => 'both']);
    $kit->sync->apply($plan['planId'], $plan['hash'], true);
    same(['StrategicValue' => 5], json_decode((string) $kit->fake->patches()[0]['body'], true));
    same('local only', $kit->local()['209']['notes'], 'unmapped local fields are kept');

    foreach (['Author', 'Editor', 'Created', 'Modified', 'ID', 'Attachments', 'ContentType'] as $system) {
        $config = syncConfig(tempDir(), ['fields' => ['strategic' => ['column' => $system]]]);
        throwsLike(fn () => FieldMap::fromConfig($config), InvalidArgumentException::class, $system);
    }
    $map = FieldMap::fromConfig(syncConfig(tempDir(), ['fields' => ['reach' => ['column' => 'PLACEHOLDER:Reach']]]));
    check(!in_array('reach', $map->fields(), true), 'placeholder columns are left out');
    same(['reach'], $map->unmapped());
});

// 16. Write cap
test('16 a plan over the write cap is blocked unless an admin raises the cap', function (): void {
    $kit = new Kit(tempDir(), [], ['max_writes_per_run' => 2]);
    $plan = $kit->sync->plan(['direction' => 'both']);
    check($plan['blocked'], 'blocked');
    throwsLike(fn () => $kit->sync->apply($plan['planId'], $plan['hash'], true), SyncRefused::class);
    same([], $kit->fake->patches());
    same([], $kit->local(), 'nothing pulled');

    $raised = $kit->sync->plan(['direction' => 'both', 'maxWrites' => 10]);
    check(!$raised['blocked'], 'raised cap');
    $kit->sync->apply($raised['planId'], $raised['hash'], true);
    same(3, $kit->local()['215']['strategic']);
});

// 17. Throttling
test('17 429 and 503 with Retry-After are retried after the delay, then given up', function (): void {
    $kit = new Kit(tempDir());
    $kit->fake->script[] = ['method' => 'GET', 'status' => 429, 'headers' => ['Retry-After' => '7']];
    $kit->fake->script[] = ['method' => 'GET', 'status' => 503, 'headers' => ['Retry-After' => '2']];
    $plan = $kit->sync->plan(['direction' => 'both']);
    same([7, 2], $kit->slept);
    check($plan['counts']['pull'] >= 3, 'plan still complete');

    $kit->slept = [];
    $kit->fake->script[] = ['method' => 'GET', 'status' => 429, 'headers' => ['Retry-After' => '1'], 'times' => 10];
    $error = throwsLike(fn () => $kit->sync->plan(['direction' => 'both']), GraphError::class);
    same(429, $error->status);
    same(3, count($kit->slept), 'three retries, then stop');
    $kit->fake->script = [];

    $kit->slept = [];
    $kit->fake->script[] = ['method' => 'GET', 'status' => 429, 'headers' => ['Retry-After' => '86400']];
    $kit->sync->plan(['direction' => 'both']);
    same([60], $kit->slept, 'a huge Retry-After is capped');
});

// 18. Paging
test('18 paging follows @odata.nextLink across pages and refuses links to another host', function (): void {
    $kit = new Kit(tempDir(), [], [], 2);
    $plan = $kit->sync->plan(['direction' => 'both']);
    same(3, count(array_filter($kit->fake->calls, static fn (array $c): bool => $c['method'] === 'GET')), 'three pages');
    check(opFor($plan, 'pull', '213') !== null, 'last page item present');
    check(str_contains($kit->fake->calls[0]['url'], '$expand=fields($select='), 'first page selects only mapped columns');

    $evil = new class implements GraphHttp {
        public array $urls = [];
        public function send(string $method, string $url, array $headers, ?string $body): array
        {
            $this->urls[] = $url;
            return ['status' => 200, 'headers' => [], 'body' => json_encode(['value' => [], '@odata.nextLink' => 'https://evil.example/steal'])];
        }
    };
    $client = new GraphClient($evil, static fn (): string => 't', BASE);
    throwsLike(fn () => $client->listItems(SITE, LIST_ID, ['Level']), GraphError::class);
    same(1, count($evil->urls), 'the token never went to the other host');
});

// 19. Auth
test('19 auth: no Microsoft session is 401 with no Graph call; non-admins get 403 on apply and restore-plan; CSRF is required', function (): void {
    $kit = new Kit(tempDir(), ['209' => ['strategic' => 1]]);
    $built = 0;
    $session = [];
    $deps = handlerDeps($kit, null, $built);
    [$status, $body] = scorecardSyncHandle('plan', 'POST', [], [], $session, $deps);
    same(401, $status);
    same(true, $body['login']);
    same('api/microsoft.php', $body['signIn']);
    same(0, $built, 'no sync built');
    same([], $kit->fake->calls);

    $harness = handlerHarness($kit, 'editor@forces.gc.ca');
    [$status, $plan] = $harness('plan', ['direction' => 'both']);
    same(200, $status, json_encode($plan));
    [$status] = $harness('apply', ['planId' => $plan['planId'], 'hash' => $plan['hash'], 'confirm' => true]);
    same(403, $status);
    [$status] = $harness('restore-plan', ['runId' => 'whatever']);
    same(403, $status);
    [$status] = $harness('plan', ['direction' => 'both', 'maxWrites' => 500]);
    same(403, $status, 'only an admin can raise the cap');
    same([], $kit->fake->patches());

    [$status] = $harness('plan', ['direction' => 'both'], 'wrong-token');
    same(403, $status, 'bad CSRF token');
    [$status] = $harness('status', [], 'wrong-token');
    same(200, $status, 'status hands out the token, so it does not need one');
    [$status] = $harness('nope', []);
    same(404, $status);
    [$status] = $harness('plan', [], null, 'GET');
    same(405, $status);
});

// 20. Concurrency
test('20 a second run gets 409, and the lock is released after an exception', function (): void {
    $kit = new Kit(tempDir());
    $store = new SyncStore($kit->config['storage_dir']);
    $held = $store->lock();
    check($held !== null, 'took the lock');
    $error = throwsLike(fn () => $kit->sync->plan(['direction' => 'both']), SyncBusy::class);
    same(409, $error->status);
    $harness = handlerHarness($kit, 'scott.test@forces.gc.ca');
    [$status] = $harness('plan', ['direction' => 'both']);
    same(409, $status);
    same(true, $kit->sync->status()['locked']);
    $store->unlock($held);

    $kit->fake->script[] = ['method' => 'GET', 'status' => 500];
    throwsLike(fn () => $kit->sync->plan(['direction' => 'both']), GraphError::class);
    same(false, $kit->sync->status()['locked'], 'released after the error');
    $kit->sync->plan(['direction' => 'both']);
});

// 21. Restore
test('21 restore from backup puts both sides back to the pre-run state', function (): void {
    $kit = new Kit(tempDir());
    $kit->settle();
    $remoteBefore = $kit->fake->items['13']['fields'];
    $localBefore = $kit->local();

    $local = $localBefore;
    $local['209']['strategic'] = 1;
    file_put_contents($kit->scores, json_encode($local));
    $kit->fake->remoteEdit('11', ['ReachValue' => 4]);
    $plan = $kit->sync->plan(['direction' => 'both']);
    $run = $kit->sync->apply($plan['planId'], $plan['hash'], true);
    same(1, $kit->fake->items['13']['fields']['StrategicValue']);
    same(4, $kit->local()['215']['reach']);

    $undo = $kit->sync->restorePlan($run['runId']);
    same(1, count(ops($undo, 'restore_remote')));
    same(1, count(ops($undo, 'restore_local')));
    $kit->fake->calls = [];
    $kit->sync->apply($undo['planId'], $undo['hash'], true);
    same($remoteBefore['StrategicValue'], $kit->fake->items['13']['fields']['StrategicValue']);
    $patch = $kit->fake->patches()[0];
    check(($patch['headers']['If-Match'] ?? '') !== '' && $patch['headers']['If-Match'] !== '*', 'restore uses If-Match');
    $after = $kit->local();
    same($localBefore['215'], $after['215'], 'local row back to pre-run');
    throwsLike(fn () => $kit->sync->restorePlan('../x'), SyncRefused::class);
});

// 22. Disabled
test('22 disabled: the endpoint says so and never builds a sign-in or Graph client', function (): void {
    $kit = new Kit(tempDir(), [], ['enabled' => false]);
    $built = 0;
    $session = [];
    $signIns = 0;
    $deps = handlerDeps($kit, 'scott.test@forces.gc.ca', $built);
    $deps['signIn'] = function () use (&$signIns) {
        $signIns++;
        throw new LogicException('must not be built');
    };
    [$status, $body] = scorecardSyncHandle('status', 'POST', [], [], $session, $deps);
    same(200, $status);
    same(false, $body['enabled']);
    [$status, $body] = scorecardSyncHandle('plan', 'POST', [], [], $session, $deps);
    same(503, $status);
    same(0, $signIns);
    same(0, $built);
    same([], $kit->fake->calls);
});

test('shipped config: off, dry-run, and every SharePoint name a marked placeholder', function (): void {
    $config = require dirname(__DIR__, 2) . '/api/lib/microsoft-config.php';
    $sync = $config['scorecard_sync'];
    same(false, $sync['enabled']);
    same('dry-run', $sync['mode']);
    check(str_starts_with($sync['site_id'], 'PLACEHOLDER'), 'site');
    check(str_starts_with($sync['list_id'], 'PLACEHOLDER'), 'list');
    check(str_starts_with($sync['key_column'], 'PLACEHOLDER'), 'key column');
    foreach ($sync['fields'] as $name => $field) {
        check(str_starts_with($field['column'], 'PLACEHOLDER'), $name);
    }
    same([], FieldMap::fromConfig($sync)->fields());
    same(false, ScorecardSync::configured($sync));
    same(TENANT_ID, $config['microsoft']['tenant'], 'same tenant as devops.php and teams.php');
    same('14d82eec-204b-4c2f-b7e8-296a70dab67e', $config['microsoft']['client'], 'same public client as teams.php');
    check(isset(MicrosoftSignIn::CLOUDS[$config['microsoft']['cloud']]), 'known cloud');
});

test('placeholder config: plan answers 503 not configured, with no Graph call', function (): void {
    $kit = new Kit(tempDir(), [], ['site_id' => 'PLACEHOLDER_SITE_ID']);
    $harness = handlerHarness($kit, 'scott.test@forces.gc.ca');
    [$status, $body] = $harness('plan', []);
    same(503, $status);
    same(false, $body['configured']);
    [$status, $body] = $harness('status', []);
    same(200, $status);
    same(false, $body['configured']);
    same([], $kit->fake->calls);
});

test('admin allowlist: shared vascIsAdmin helper decides apply, and it fails closed', function (): void {
    putenv('VASC_ADMINS=Scott.Test@forces.gc.ca, other@forces.gc.ca');
    try {
        check(vascIsAdmin('scott.test@forces.gc.ca'), 'listed');
        check(!vascIsAdmin('editor@forces.gc.ca'), 'not listed');
        check(!vascIsAdmin(''), 'empty');
        $kit = new Kit(tempDir(), ['209' => ['strategic' => 1]]);
        $built = 0;
        $deps = handlerDeps($kit, 'Scott.Test@forces.gc.ca', $built);
        $deps['isAdmin'] = 'vascIsAdmin';
        $session = [];
        [, $status] = scorecardSyncHandle('status', 'POST', [], [], $session, $deps);
        same(true, $status['isAdmin']);
    } finally {
        putenv('VASC_ADMINS');
    }
});

test('handler: apply through the endpoint with a valid admin, CSRF and confirm writes and audits', function (): void {
    $kit = new Kit(tempDir());
    $kit->settle();
    $local = $kit->local();
    $local['209']['strategic'] = 5;
    file_put_contents($kit->scores, json_encode($local));
    $harness = handlerHarness($kit, 'scott.test@forces.gc.ca');
    [, $status] = $harness('status', []);
    check(is_string($status['csrf']) && strlen($status['csrf']) >= 32, 'csrf handed out');
    [$code, $plan] = $harness('plan', ['direction' => 'both']);
    same(200, $code);
    same(['pull', 'push', 'create', 'conflict', 'skip'], array_keys($plan['counts']));
    [$code, $body] = $harness('apply', ['planId' => $plan['planId'], 'hash' => $plan['hash']]);
    same(400, $code, 'confirm is required');
    [$code, $body] = $harness('apply', ['planId' => $plan['planId'], 'hash' => $plan['hash'], 'confirm' => true]);
    same(200, $code, json_encode($body));
    same(1, $body['applied']);
    foreach (['runId', 'applied', 'conflicts', 'skipped', 'backupDir'] as $key) {
        check(array_key_exists($key, $body), $key);
    }
    $audit = array_map(static fn (string $line): array => json_decode($line, true), file($kit->config['storage_dir'] . '/audit.jsonl', FILE_IGNORE_NEW_LINES));
    $pushes = array_values(array_filter($audit, static fn (array $row): bool => $row['op'] === 'push' && $row['runId'] === $body['runId']));
    same(1, count($pushes));
    $last = $pushes[0];
    same('applied', $last['result']);
    same('scott.test@forces.gc.ca', $last['user']);
    same(5, $last['after']['strategic']);
    same(4, $last['before']['strategic']);
    [, $status] = $harness('status', []);
    same($body['runId'], $status['lastRun']['runId']);
    [$code] = $harness('apply', ['planId' => $plan['planId'], 'hash' => $plan['hash'], 'confirm' => true]);
    same(409, $code, 'a plan applies once');
});

test('create stays off unless allow_create is set, and then only for rows with a use case id', function (): void {
    $kit = new Kit(tempDir(), ['999' => ['strategic' => 2, 'level' => 'L3']]);
    $plan = $kit->sync->plan(['direction' => 'push']);
    same(0, $plan['counts']['create']);
    check(opFor($plan, 'skip', '999') !== null, 'reported');

    $kit = new Kit(tempDir(), ['999' => ['strategic' => 2, 'level' => 'L3']], ['allow_create' => true]);
    $plan = $kit->sync->plan(['direction' => 'push']);
    same(1, $plan['counts']['create']);
    $kit->sync->apply($plan['planId'], $plan['hash'], true);
    $created = end($kit->fake->items);
    same('999', (string) $created['fields']['UseCaseIDLookupId']);
    same(2, $created['fields']['StrategicValue']);
    check(!array_key_exists('Level', $created['fields']), 'a sharepoint-owned column is not pushed');
    $again = $kit->sync->plan(['direction' => 'both']);
    same(0, $again['counts']['create'] + $again['counts']['push'], 'no double create');
});

test('a duplicate use case id in SharePoint is skipped, not guessed', function (): void {
    $kit = new Kit(tempDir());
    $kit->fake->items['16'] = $kit->fake->items['13'];
    $kit->fake->items['16']['id'] = '16';
    $plan = $kit->sync->plan(['direction' => 'both']);
    same(null, opFor($plan, 'pull', '209'));
    $skip = opFor($plan, 'skip', '209');
    same('duplicate_remote_key', $skip['reason']);
});

test('invalid SharePoint values are skipped, not pulled', function (): void {
    $kit = new Kit(tempDir());
    $kit->fake->remoteEdit('13', ['StrategicValue' => 9, 'Level' => 'Level one']);
    $plan = $kit->sync->plan(['direction' => 'both']);
    $pull = opFor($plan, 'pull', '209');
    check(!isset($pull['fields']['strategic']) && !isset($pull['fields']['level']), 'not pulled');
    $kit->sync->apply($plan['planId'], $plan['hash'], true);
    check(!isset($kit->local()['209']['strategic']), 'not written');
});

test('scores.json edited between plan and apply refuses that pull', function (): void {
    $kit = new Kit(tempDir());
    $kit->settle();
    $kit->fake->remoteEdit('13', ['ReachValue' => 3]);
    $plan = $kit->sync->plan(['direction' => 'both']);
    $local = $kit->local();
    $local['209']['reach'] = 2;
    file_put_contents($kit->scores, json_encode($local));
    $result = $kit->sync->apply($plan['planId'], $plan['hash'], true);
    same(1, $result['conflicts']);
    same(2, $kit->local()['209']['reach']);
});

test('direction pull or push filters the plan', function (): void {
    $kit = new Kit(tempDir());
    $kit->settle();
    $local = $kit->local();
    $local['209']['strategic'] = 5;
    file_put_contents($kit->scores, json_encode($local));
    $kit->fake->remoteEdit('11', ['ReachValue' => 4]);
    $pull = $kit->sync->plan(['direction' => 'pull']);
    same([1, 0], [$pull['counts']['pull'], $pull['counts']['push']]);
    $push = $kit->sync->plan(['direction' => 'push']);
    same([0, 1], [$push['counts']['pull'], $push['counts']['push']]);
    throwsLike(fn () => $kit->sync->plan(['direction' => 'sideways']), SyncRefused::class);
});

test('storage directory is created with a deny-all .htaccess', function (): void {
    $dir = tempDir() . '/nested/sync';
    new SyncStore($dir);
    same("Require all denied\n", (string) file_get_contents($dir . '/.htaccess'));

    $locked = tempDir() . '/sync';
    mkdir($locked . '/plans', 0700, true);
    mkdir($locked . '/backups', 0700);
    chmod($locked, 0500);
    try {
        $error = throwsLike(fn () => new SyncStore($locked), SyncRefused::class, 'no deny file, no sync');
        same(500, $error->status);
    } finally {
        chmod($locked, 0700);
    }
});

// Microsoft sign-in (delegated, device code, shared with the org chart calendar)

function idToken(array $claims): string
{
    $part = static fn (array $data): string => rtrim(strtr(base64_encode((string) json_encode($data)), '+/', '-_'), '=');
    return $part(['alg' => 'none']) . '.' . $part($claims) . '.sig';
}

function fakeTokenEndpoint(array &$posts, array $replies): Closure
{
    return function (string $url, array $fields) use (&$posts, &$replies): array {
        $posts[] = ['url' => $url, 'fields' => $fields];
        return array_shift($replies) ?? ['status' => 500, 'data' => []];
    };
}

test('sign-in: device code login asks for every feature scope and records who signed in', function (): void {
    $session = [];
    $posts = [];
    $now = 1794400000;
    $post = fakeTokenEndpoint($posts, [
        ['status' => 200, 'data' => ['user_code' => 'ABCD1234', 'device_code' => 'dev-1', 'verification_uri' => 'https://login.microsoftonline.com/device', 'interval' => 5, 'expires_in' => 900]],
        ['status' => 400, 'data' => ['error' => 'authorization_pending']],
        ['status' => 200, 'data' => [
            'access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_in' => 3600,
            'id_token' => idToken(['tid' => TENANT_ID, 'oid' => 'oid-1', 'preferred_username' => 'Scott.Test@forces.gc.ca', 'name' => 'Scott Test']),
        ]],
    ]);
    $signIn = new MicrosoftSignIn(msConfig(), $session, $post, function () use (&$now): int {
        return $now;
    });
    $start = $signIn->startLogin();
    same('ABCD1234', $start['user_code']);
    same('https://login.microsoftonline.com/' . TENANT_ID . '/oauth2/v2.0/devicecode', $posts[0]['url']);
    same('14d82eec-204b-4c2f-b7e8-296a70dab67e', $posts[0]['fields']['client_id']);
    $scopes = explode(' ', $posts[0]['fields']['scope']);
    foreach (['openid', 'profile', 'offline_access', 'https://graph.microsoft.com/User.Read', 'https://graph.microsoft.com/Sites.ReadWrite.All', 'https://graph.microsoft.com/Calendars.ReadBasic'] as $scope) {
        check(in_array($scope, $scopes, true), $scope);
    }
    same(null, $signIn->user());
    $now += 5;
    same(['pending' => true], $signIn->pollLogin());
    $now += 5;
    $done = $signIn->pollLogin();
    same(true, $done['signedIn']);
    same(['upn' => 'scott.test@forces.gc.ca', 'name' => 'Scott Test', 'oid' => 'oid-1'], $signIn->user());
    same('access-1', $signIn->accessToken('scorecard'));
    same('access-1', $signIn->accessToken('calendar'));
    check(!str_contains(json_encode($session), 'dev-1'), 'device code cleared');
});

test('sign-in: a token from another tenant is refused', function (): void {
    $session = [];
    $posts = [];
    $post = fakeTokenEndpoint($posts, [
        ['status' => 200, 'data' => ['user_code' => 'ABCD1234', 'device_code' => 'dev-1', 'verification_uri' => 'https://login.microsoftonline.com/device', 'interval' => 5]],
        ['status' => 200, 'data' => ['access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 3600, 'id_token' => idToken(['tid' => 'ffffffff-0000-0000-0000-000000000000', 'preferred_username' => 'x@evil.example'])]],
    ]);
    $now = 1794400000;
    $signIn = new MicrosoftSignIn(msConfig(), $session, $post, function () use (&$now): int {
        return $now;
    });
    $signIn->startLogin();
    $now += 10;
    throwsLike(fn () => $signIn->pollLogin(), NotSignedIn::class);
    same(null, $signIn->user());
});

test('sign-in: an expired token refreshes for the feature scope; invalid_grant signs out', function (): void {
    $session = ['microsoft' => [
        'refresh' => 'refresh-1',
        'user' => ['upn' => 'scott.test@forces.gc.ca', 'name' => 'Scott Test', 'oid' => 'oid-1'],
        'tokens' => ['scorecard' => ['access' => 'old', 'expires' => 100]],
    ]];
    $posts = [];
    $post = fakeTokenEndpoint($posts, [
        ['status' => 200, 'data' => ['access_token' => 'new', 'refresh_token' => 'refresh-2', 'expires_in' => 3600]],
        ['status' => 400, 'data' => ['error' => 'invalid_grant']],
    ]);
    $signIn = new MicrosoftSignIn(msConfig(), $session, $post, static fn (): int => 1794400000);
    same('new', $signIn->accessToken('scorecard'));
    same('refresh_token', $posts[0]['fields']['grant_type']);
    same('https://graph.microsoft.com/Sites.ReadWrite.All offline_access', $posts[0]['fields']['scope']);
    same('refresh-2', $session['microsoft']['refresh']);
    throwsLike(fn () => $signIn->accessToken('calendar'), NotSignedIn::class);
    same(null, $signIn->user());
    check(!isset($session['microsoft']), 'session cleared');
});

test('sign-in: a government cloud uses its own login and Graph hosts', function (): void {
    $session = [];
    $ms = msConfig();
    $ms['cloud'] = 'usgov-high';
    $signIn = new MicrosoftSignIn($ms, $session, static fn (): array => ['status' => 500, 'data' => []]);
    same('https://login.microsoftonline.us', $signIn->authority());
    same('https://graph.microsoft.us/v1.0', $signIn->graphBase());
    same('https://graph.microsoft.us/Sites.ReadWrite.All offline_access', $signIn->scopesFor('scorecard'));
    $ms['cloud'] = 'PLACEHOLDER';
    throwsLike(fn () => new MicrosoftSignIn($ms, $session), InvalidArgumentException::class);
});

// Endpoint harness

final class FixedSignIn implements TokenProvider
{
    public function __construct(private ?array $who)
    {
    }

    public function user(): ?array
    {
        return $this->who;
    }

    public function accessToken(string $feature): string
    {
        if ($this->who === null) {
            throw new NotSignedIn('Sign in with Microsoft.');
        }
        return 'token-' . $feature;
    }
}

function handlerDeps(Kit $kit, ?string $upn, int &$built): array
{
    return [
        'config' => ['microsoft' => msConfig(), 'scorecard_sync' => $kit->config],
        'signIn' => static fn (): TokenProvider => new FixedSignIn($upn === null ? null : ['upn' => strtolower($upn), 'name' => 'Test', 'oid' => 'oid']),
        'sync' => function (string $token, array $user) use ($kit, &$built): ScorecardSync {
            $built++;
            return $kit->build();
        },
        'isAdmin' => static fn (string $email): bool => $email === 'scott.test@forces.gc.ca',
    ];
}

function handlerHarness(Kit $kit, string $upn): Closure
{
    $session = [];
    $built = 0;
    $deps = handlerDeps($kit, $upn, $built);
    return function (string $action, array $body, ?string $csrf = null, string $method = 'POST') use (&$session, $deps): array {
        if ($csrf === null) {
            [, $status] = scorecardSyncHandle('status', 'POST', [], [], $session, $deps);
            $csrf = (string) ($status['csrf'] ?? '');
        }
        return scorecardSyncHandle($action, $method, $body, ['X-CSRF-Token' => $csrf], $session, $deps);
    };
}

$passed = 0;
$failed = 0;
foreach ($tests as $name => $body) {
    if ($filter !== '' && !str_contains($name, $filter)) {
        continue;
    }
    try {
        $body();
        $passed++;
        echo "ok    $name\n";
    } catch (Throwable $error) {
        $failed++;
        echo "FAIL  $name\n      " . get_class($error) . ': ' . $error->getMessage() . "\n";
        if (!$error instanceof Failed) {
            echo '      at ' . $error->getFile() . ':' . $error->getLine() . "\n";
        }
    }
}
echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
