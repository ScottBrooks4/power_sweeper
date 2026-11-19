<?php
declare(strict_types=1);

/**
 * The only way Pivot talks to SharePoint lists. It can list, read, create and
 * update fields. It has no delete, and the verb guard refuses DELETE even if
 * someone builds the request by hand.
 */

class GraphError extends RuntimeException
{
    public function __construct(string $message, public int $status = 0)
    {
        parent::__construct($message);
    }
}

final class PreconditionFailed extends GraphError
{
}

interface GraphHttp
{
    /**
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: string} header names lower-cased
     */
    public function send(string $method, string $url, array $headers, ?string $body): array;
}

final class CurlGraphHttp implements GraphHttp
{
    public function send(string $method, string $url, array $headers, ?string $body): array
    {
        if (!in_array($method, GraphClient::VERBS, true)) {
            throw new LogicException($method . ' is never sent to Graph.');
        }
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $received = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_TIMEOUT => 40,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$received): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $received[strtolower(trim($name))] = trim($value);
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new GraphError('Graph could not be reached: ' . $error, 0);
        }
        return ['status' => $status, 'headers' => $received, 'body' => (string) $raw];
    }
}

final class GraphClient
{
    public const VERBS = ['GET', 'POST', 'PATCH'];
    private const RETRY_CAP_SECONDS = 60;

    private Closure $token;
    private Closure $sleep;

    public function __construct(
        private GraphHttp $http,
        callable $token,
        private string $base,
        ?callable $sleep = null,
        private int $maxRetries = 3
    ) {
        $this->token = Closure::fromCallable($token);
        $this->sleep = $sleep !== null ? Closure::fromCallable($sleep) : static function (int $seconds): void {
            sleep($seconds);
        };
        $this->base = rtrim($base, '/');
    }

    /**
     * Every item in the list, following @odata.nextLink.
     *
     * @param array<int, string> $columns
     * @return array<int, array{id: string, eTag: string, lastModifiedDateTime: string, fields: array<string, mixed>}>
     */
    public function listItems(string $site, string $list, array $columns): array
    {
        $select = implode(',', array_map(static fn (string $column): string => rawurlencode($column), $columns));
        $url = $this->itemsUrl($site, $list) . '?$expand=fields($select=' . $select . ')&$top=200';
        $items = [];
        $pages = 0;
        while ($url !== '') {
            if (++$pages > 500) {
                throw new GraphError('The list has more pages than Pivot will read.', 0);
            }
            $data = $this->send('GET', $url, null);
            foreach ($data['value'] ?? [] as $item) {
                if (is_array($item)) {
                    $items[] = $this->shape($item);
                }
            }
            $next = (string) ($data['@odata.nextLink'] ?? '');
            if ($next !== '' && !str_starts_with($next, $this->base . '/')) {
                throw new GraphError('Graph sent a next page on another host; it was not followed.', 0);
            }
            $url = $next;
        }
        return $items;
    }

    public function getItem(string $site, string $list, string $id): ?array
    {
        try {
            return $this->shape($this->send('GET', $this->itemsUrl($site, $list) . '/' . $this->itemId($id) . '?$expand=fields', null));
        } catch (GraphError $error) {
            if ($error->status === 404) {
                return null;
            }
            throw $error;
        }
    }

    public function createItem(string $site, string $list, array $fields): array
    {
        return $this->shape($this->send('POST', $this->itemsUrl($site, $list), ['fields' => $fields]));
    }

    /**
     * PATCH only the given columns, guarded by the item's eTag.
     *
     * @return array{eTag: string, fields: array<string, mixed>}
     * @throws PreconditionFailed when the item changed since that eTag was read
     */
    public function updateItemFields(string $site, string $list, string $id, array $fields, string $ifMatch): array
    {
        if ($ifMatch === '' || $ifMatch === '*') {
            throw new LogicException('Updates always carry the eTag that was read.');
        }
        $data = $this->send('PATCH', $this->itemsUrl($site, $list) . '/' . $this->itemId($id) . '/fields', $fields, ['If-Match' => $ifMatch]);
        return ['eTag' => (string) ($data['@odata.etag'] ?? ''), 'fields' => $data];
    }

    private function send(string $method, string $url, ?array $body, array $extra = []): array
    {
        if (!in_array($method, self::VERBS, true)) {
            throw new LogicException($method . ' is never sent to Graph.');
        }
        if (!str_starts_with($url, $this->base . '/')) {
            throw new LogicException('Only ' . $this->base . ' is called.');
        }
        $payload = $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if ($payload === false) {
            throw new GraphError('The request could not be encoded.', 0);
        }
        $attempt = 0;
        while (true) {
            $headers = array_merge([
                'Authorization' => 'Bearer ' . ($this->token)(),
                'Accept' => 'application/json',
            ], $payload === null ? [] : ['Content-Type' => 'application/json'], $extra);
            $res = $this->http->send($method, $url, $headers, $payload);
            $status = (int) $res['status'];
            if (($status === 429 || $status === 503) && $attempt < $this->maxRetries) {
                $attempt++;
                $wait = (int) ($res['headers']['retry-after'] ?? 0);
                ($this->sleep)(max(1, min(self::RETRY_CAP_SECONDS, $wait > 0 ? $wait : 2 ** $attempt)));
                continue;
            }
            $data = json_decode($res['body'], true);
            $data = is_array($data) ? $data : [];
            if ($status >= 200 && $status < 300) {
                return $data;
            }
            $message = (string) ($data['error']['message'] ?? ('Graph answered ' . $status . '.'));
            if ($status === 412) {
                throw new PreconditionFailed($message, 412);
            }
            throw new GraphError($message, $status);
        }
    }

    private function itemsUrl(string $site, string $list): string
    {
        if (!preg_match('/^[A-Za-z0-9.,:_-]+$/', $site) || !preg_match('/^[A-Za-z0-9_-]+$/', $list)) {
            throw new InvalidArgumentException('Site and list ids must be Graph ids.');
        }
        return $this->base . '/sites/' . $site . '/lists/' . $list . '/items';
    }

    private function itemId(string $id): string
    {
        if (!preg_match('/^\d{1,10}$/', $id)) {
            throw new InvalidArgumentException('List item ids are numbers.');
        }
        return $id;
    }

    private function shape(array $item): array
    {
        return [
            'id' => (string) ($item['id'] ?? ''),
            'eTag' => (string) ($item['eTag'] ?? ($item['fields']['@odata.etag'] ?? '')),
            'lastModifiedDateTime' => (string) ($item['lastModifiedDateTime'] ?? ''),
            'fields' => is_array($item['fields'] ?? null) ? $item['fields'] : [],
        ];
    }
}
