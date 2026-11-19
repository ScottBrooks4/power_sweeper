<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/server/GraphGateway.php';

/**
 * MVC model for Microsoft Graph / M365: mail, calendar, files, service account.
 */
final class GraphModel
{
    private GraphGateway $graph;

    public function __construct(string $root)
    {
        $configPath = $root . '/server/config.json';
        $config = is_file($configPath)
            ? (json_decode((string) file_get_contents($configPath), true) ?: [])
            : [];
        $this->graph = new GraphGateway($root, is_array($config) ? $config : []);
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        return $this->graph->status();
    }

    /** @return array<string, mixed>|null */
    public function currentUser(): ?array
    {
        return $this->graph->currentUser();
    }

    /**
     * @param array<string, mixed> $message
     * @return array<string, mixed>
     */
    public function sendMail(array $message): array
    {
        return $this->graph->sendMail($message);
    }

    /** @return array<string, mixed> */
    public function calendar(array $query = []): array
    {
        return $this->graph->listCalendar($query);
    }

    /** @return array<string, mixed> */
    public function files(array $query = []): array
    {
        return $this->graph->listFiles($query);
    }

    /** @return array<string, mixed> */
    public function serviceAccount(array $body = []): array
    {
        return $this->graph->serviceAccount($body);
    }
}
