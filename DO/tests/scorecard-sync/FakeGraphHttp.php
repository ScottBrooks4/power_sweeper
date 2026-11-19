<?php
declare(strict_types=1);

/**
 * An in-memory SharePoint list behind the GraphHttp interface. It answers the
 * same URLs and shapes Graph does, keeps eTags honest, honours If-Match, and
 * records every request so tests can see exactly what would have been sent.
 */
final class FakeGraphHttp implements GraphHttp
{
    /** @var array<int, array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public array $calls = [];

    /** @var array<int, array{method: string, status: int, headers?: array<string, string>, body?: array<string, mixed>, times?: int}> */
    public array $script = [];

    /** @var array<string, array<string, mixed>> */
    public array $items = [];

    private int $nextId = 100;

    public function __construct(
        private string $base,
        private string $site,
        private string $list,
        array $items,
        private int $pageSize = 200
    ) {
        foreach ($items as $item) {
            $this->items[(string) $item['id']] = $item;
        }
    }

    public static function fromFixture(string $base, string $site, string $list, int $pageSize = 200): self
    {
        $data = json_decode((string) file_get_contents(__DIR__ . '/fixtures/project-scores-items.json'), true);
        return new self($base, $site, $list, $data['value'], $pageSize);
    }

    public function send(string $method, string $url, array $headers, ?string $body): array
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

        foreach ($this->script as $index => $step) {
            if ($step['method'] === $method) {
                $times = ($step['times'] ?? 1) - 1;
                if ($times <= 0) {
                    unset($this->script[$index]);
                    $this->script = array_values($this->script);
                } else {
                    $this->script[$index]['times'] = $times;
                }
                return $this->reply($step['status'], $step['body'] ?? ['error' => ['message' => 'scripted']], $step['headers'] ?? []);
            }
        }

        $prefix = $this->base . '/sites/' . $this->site . '/lists/' . $this->list . '/items';
        if (!str_starts_with($url, $prefix)) {
            return $this->reply(404, ['error' => ['code' => 'itemNotFound', 'message' => 'Unknown site or list']]);
        }
        $rest = substr($url, strlen($prefix));
        $query = [];
        if (str_contains($rest, '?')) {
            [$rest, $qs] = explode('?', $rest, 2);
            parse_str($qs, $query);
        }

        if ($method === 'GET' && $rest === '') {
            $skip = (int) ($query['$skiptoken'] ?? 0);
            $all = array_values($this->items);
            $page = array_slice($all, $skip, $this->pageSize);
            $out = ['value' => $page];
            if ($skip + $this->pageSize < count($all)) {
                $out['@odata.nextLink'] = $prefix . '?$expand=fields&$top=' . $this->pageSize . '&$skiptoken=' . ($skip + $this->pageSize);
            }
            return $this->reply(200, $out);
        }

        if ($method === 'POST' && $rest === '') {
            $payload = json_decode((string) $body, true);
            $id = (string) $this->nextId++;
            $etag = $this->etag($id, 1);
            $fields = array_merge(is_array($payload['fields'] ?? null) ? $payload['fields'] : [], ['id' => $id, '@odata.etag' => $etag]);
            $this->items[$id] = ['id' => $id, 'eTag' => $etag, 'lastModifiedDateTime' => '2026-11-11T15:00:00Z', 'fields' => $fields];
            return $this->reply(201, $this->items[$id]);
        }

        if (!preg_match('#^/(\d+)(/fields)?$#', $rest, $match)) {
            return $this->reply(400, ['error' => ['message' => 'Unsupported path ' . $rest]]);
        }
        $id = $match[1];
        $fieldsPath = isset($match[2]) && $match[2] !== '';
        if (!isset($this->items[$id])) {
            return $this->reply(404, ['error' => ['code' => 'itemNotFound', 'message' => 'Item not found']]);
        }

        if ($method === 'GET' && !$fieldsPath) {
            return $this->reply(200, $this->items[$id]);
        }

        if ($method === 'PATCH' && $fieldsPath) {
            $ifMatch = $headers['If-Match'] ?? '';
            if ($ifMatch === '' || ($ifMatch !== '*' && $ifMatch !== $this->items[$id]['eTag'])) {
                return $this->reply(412, ['error' => ['code' => 'resourceModified', 'message' => 'The resource has been modified']]);
            }
            $payload = json_decode((string) $body, true);
            $this->bump($id, is_array($payload) ? $payload : []);
            return $this->reply(200, $this->items[$id]['fields']);
        }

        return $this->reply(405, ['error' => ['message' => $method . ' is not allowed here']]);
    }

    /** Someone edits the list in SharePoint. */
    public function remoteEdit(string $id, array $fields): void
    {
        $this->bump($id, $fields);
    }

    /** @return array<int, string> */
    public function methods(): array
    {
        return array_map(static fn (array $call): string => $call['method'], $this->calls);
    }

    /** @return array<int, array<string, mixed>> */
    public function patches(): array
    {
        return array_values(array_filter($this->calls, static fn (array $call): bool => $call['method'] === 'PATCH'));
    }

    private function bump(string $id, array $fields): void
    {
        $item = $this->items[$id];
        $version = (int) substr((string) strrchr(trim($item['eTag'], '"'), ','), 1) + 1;
        $etag = $this->etag($id, $version);
        $item['fields'] = array_merge($item['fields'], $fields, ['@odata.etag' => $etag]);
        $item['eTag'] = $etag;
        $item['lastModifiedDateTime'] = '2026-11-11T15:00:00Z';
        $this->items[$id] = $item;
    }

    private function etag(string $id, int $version): string
    {
        return '"6a1f0c2e-0b6e-4c55-9d3e-' . str_pad($id, 12, '0', STR_PAD_LEFT) . ',' . $version . '"';
    }

    private function reply(int $status, array $body, array $headers = []): array
    {
        return ['status' => $status, 'headers' => array_change_key_case($headers, CASE_LOWER), 'body' => (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
    }
}
