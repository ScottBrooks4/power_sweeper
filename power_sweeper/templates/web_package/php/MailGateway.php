<?php

declare(strict_types=1);

if (class_exists('MailGateway', false)) {
    return;
}

/**
 * Deliver Office365Outlook.SendEmailV2 through an allowlist and an outbox.
 *
 * Default delivery is "outbox" (JSON files under data/outbox). SMTP is never
 * used unless config mail_delivery=smtp AND a sender is injected or smtp.host
 * is set. The allowlist is re-checked immediately before any sender runs.
 */
final class MailGateway
{
    /** @var callable|null fn(array $delivered): void */
    private $sender;

    /**
     * @param array<string, mixed> $config
     * @param callable|null $sender
     */
    public function __construct(
        private readonly string $root,
        private readonly array $config,
        private readonly MailAllowlist $allowlist,
        ?callable $sender = null,
    ) {
        $this->sender = $sender;
    }

    /**
     * @param array<string, mixed> $message
     * @return array<string, mixed>
     */
    public function send(array $message): array
    {
        $policy = strtolower((string) ($this->config['mail_unlisted'] ?? 'rewrite'));
        if ($policy !== 'drop' && $policy !== 'rewrite') {
            $policy = 'rewrite';
        }
        $rewritten = $this->allowlist->rewrite($message, $policy);
        $delivery = $this->resolveDelivery();

        foreach (['to', 'cc', 'bcc'] as $field) {
            foreach (MailAllowlist::parseAddresses($rewritten['delivered'][$field]) as $addr) {
                if (!$this->allowlist->isAllowed($addr)) {
                    throw new RuntimeException('allowlist leaked unlisted recipient: ' . $addr);
                }
            }
        }

        $id = gmdate('Ymd\THis\Z') . '_' . bin2hex(random_bytes(4));
        $record = [
            'id' => $id,
            'at' => gmdate('c'),
            'connector' => (string) ($message['connector'] ?? 'Office365Outlook'),
            'operation' => (string) ($message['operation'] ?? 'SendEmailV2'),
            'delivery' => $delivery,
            'original' => $rewritten['original'],
            'delivered' => $rewritten['delivered'],
            'blocked' => $rewritten['blocked'],
            'rewritten' => $rewritten['rewritten'],
            'allowed' => $rewritten['allowed'],
            'sent' => false,
        ];

        $this->writeOutbox($record);

        if (!$rewritten['allowed']) {
            $record['ok'] = false;
            $record['error'] = 'blocked: no allowlisted recipients';

            return $record;
        }

        if ($delivery === 'smtp') {
            $this->dispatchSmtp($record);
        }

        $record['ok'] = true;

        return $record;
    }

    private function resolveDelivery(): string
    {
        $mode = strtolower((string) ($this->config['mail_delivery'] ?? getenv('MAIL_DELIVERY') ?: 'outbox'));
        if ($mode !== 'smtp') {
            return 'outbox';
        }
        $hasSender = $this->sender !== null;
        $hasHost = trim((string) ($this->config['smtp']['host'] ?? '')) !== '';
        if (!$hasSender && !$hasHost) {
            return 'outbox';
        }

        return 'smtp';
    }

    /** @param array<string, mixed> $record */
    private function writeOutbox(array $record): void
    {
        $dir = $this->root . '/data/outbox';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('cannot create outbox dir');
        }
        $path = $dir . '/' . $record['id'] . '.json';
        file_put_contents(
            $path,
            json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
        );
    }

    /** @param array<string, mixed> $record */
    private function dispatchSmtp(array &$record): void
    {
        $to = (string) $record['delivered']['to'];
        foreach (MailAllowlist::parseAddresses($to) as $addr) {
            if (!$this->allowlist->isAllowed($addr)) {
                throw new RuntimeException('refusing SMTP to unlisted recipient: ' . $addr);
            }
        }
        if ($to === '') {
            return;
        }
        if ($this->sender !== null) {
            ($this->sender)($record['delivered']);
            $record['sent'] = true;

            return;
        }
        // No generic mail() fallback — accidental sends are worse than a logged skip.
        $record['sent'] = false;
        $record['error'] = 'smtp configured without sender; left in outbox';
    }
}
