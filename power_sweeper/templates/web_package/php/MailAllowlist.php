<?php

declare(strict_types=1);

if (class_exists('MailAllowlist', false)) {
    return;
}

/**
 * Hard recipient gate for Office365Outlook.SendEmailV2 (and any invoke mail).
 * Unlisted addresses never appear on the delivered To/Cc/Bcc lists.
 */
final class MailAllowlist
{
    /** @var list<string> */
    private array $allowed;

    /** @param list<string> $allowed */
    public function __construct(array $allowed)
    {
        $norm = [];
        foreach ($allowed as $addr) {
            $e = self::normalize((string) $addr);
            if ($e !== '' && !in_array($e, $norm, true)) {
                $norm[] = $e;
            }
        }
        $this->allowed = $norm;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromConfig(array $config): self
    {
        $raw = $config['mail_allowlist'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $list = preg_split('/[;,]+/', $raw) ?: [];
        } elseif (is_array($raw)) {
            $list = $raw;
        } else {
            $env = getenv('MAIL_ALLOWLIST');
            $list = is_string($env) && $env !== ''
                ? (preg_split('/[;,]+/', $env) ?: [])
                : self::defaultAddresses();
        }

        return new self(array_map('strval', $list));
    }

    /** @return list<string> */
    public static function defaultAddresses(): array
    {
        return [
            'morath99@gmail.com',
            'Scott.Brooks4@ecn.forces.gc.ca',
            'Scott.Brooks4@forces.gc.ca',
        ];
    }

    public static function normalize(string $addr): string
    {
        $addr = trim($addr);
        if (preg_match('/<([^>]+)>/', $addr, $m) === 1) {
            $addr = $m[1];
        }

        return strtolower(trim($addr));
    }

    /** @return list<string> */
    public static function parseAddresses(string $raw): array
    {
        $parts = preg_split('/[;,]+/', $raw) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $e = self::normalize((string) $part);
            if ($e !== '' && str_contains($e, '@') && !in_array($e, $out, true)) {
                $out[] = $e;
            }
        }

        return $out;
    }

    public function isAllowed(string $addr): bool
    {
        return in_array(self::normalize($addr), $this->allowed, true);
    }

    /** @return list<string> */
    public function allowed(): array
    {
        return $this->allowed;
    }

    public function firstAllowed(): string
    {
        return $this->allowed[0] ?? '';
    }

    /**
     * Keep only allowlisted addresses, preserving first-seen order.
     *
     * @param list<string>|string $raw
     * @return array{kept: list<string>, blocked: list<string>}
     */
    public function partition(array|string $raw): array
    {
        $addrs = is_array($raw) ? [] : self::parseAddresses($raw);
        if (is_array($raw)) {
            foreach ($raw as $item) {
                foreach (self::parseAddresses((string) $item) as $e) {
                    $addrs[] = $e;
                }
            }
            $addrs = array_values(array_unique($addrs));
        }
        $kept = [];
        $blocked = [];
        foreach ($addrs as $e) {
            if ($this->isAllowed($e)) {
                if (!in_array($e, $kept, true)) {
                    $kept[] = $e;
                }
            } else {
                if (!in_array($e, $blocked, true)) {
                    $blocked[] = $e;
                }
            }
        }

        return ['kept' => $kept, 'blocked' => $blocked];
    }

    /**
     * @param array<string, mixed> $message keys: to, cc, bcc, subject, body
     * @return array{
     *   original: array{to:string,cc:string,bcc:string,subject:string,body:string},
     *   delivered: array{to:string,cc:string,bcc:string,subject:string,body:string},
     *   blocked: list<string>,
     *   rewritten: bool,
     *   allowed: bool
     * }
     */
    public function rewrite(array $message, string $unlistedPolicy = 'rewrite'): array
    {
        $origTo = (string) ($message['to'] ?? $message['To'] ?? '');
        $origCc = (string) ($message['cc'] ?? $message['Cc'] ?? '');
        $origBcc = (string) ($message['bcc'] ?? $message['Bcc'] ?? '');
        $subject = (string) ($message['subject'] ?? $message['Subject'] ?? '');
        $body = (string) ($message['body'] ?? $message['Body'] ?? '');

        $to = $this->partition($origTo);
        $cc = $this->partition($origCc);
        $bcc = $this->partition($origBcc);
        $blocked = array_values(array_unique(array_merge($to['blocked'], $cc['blocked'], $bcc['blocked'])));

        $deliveredTo = $to['kept'];
        $rewritten = false;
        if ($deliveredTo === [] && $origTo !== '' && $unlistedPolicy === 'rewrite') {
            $fallback = $this->firstAllowed();
            if ($fallback !== '') {
                $deliveredTo = [$fallback];
                $rewritten = true;
                if ($subject !== '' && !str_starts_with($subject, '[REDIRECTED]')) {
                    $subject = '[REDIRECTED] ' . $subject;
                }
                $note = '<p><em>Original To (blocked by allowlist): '
                    . htmlspecialchars($origTo, ENT_QUOTES)
                    . '</em></p>';
                $body = $note . $body;
            }
        }

        $allowed = $deliveredTo !== [];

        return [
            'original' => [
                'to' => $origTo,
                'cc' => $origCc,
                'bcc' => $origBcc,
                'subject' => (string) ($message['subject'] ?? $message['Subject'] ?? ''),
                'body' => (string) ($message['body'] ?? $message['Body'] ?? ''),
            ],
            'delivered' => [
                'to' => implode('; ', $deliveredTo),
                'cc' => implode('; ', $cc['kept']),
                'bcc' => implode('; ', $bcc['kept']),
                'subject' => $subject,
                'body' => $body,
            ],
            'blocked' => $blocked,
            'rewritten' => $rewritten,
            'allowed' => $allowed,
        ];
    }
}
