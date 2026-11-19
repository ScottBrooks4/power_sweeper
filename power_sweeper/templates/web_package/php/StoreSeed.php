<?php

declare(strict_types=1);

if (class_exists('StoreSeed', false)) {
    return;
}

/**
 * Idempotent default rows for the LOM connector entities so Admin/load
 * formulas have a Title = "1" settings row and at least one Admin user.
 */
final class StoreSeed
{
    /** @param object{list:callable,insert:callable} $store */
    public static function ensure(object $store): void
    {
        self::ensureSettings($store);
        self::ensureUsers($store);
    }

    /** @param object $store */
    private static function ensureSettings(object $store): void
    {
        $rows = self::safeList($store, 'AppSettings');
        foreach ($rows as $row) {
            if ((string) ($row['Title'] ?? '') === '1') {
                return;
            }
        }
        $ackDeny = '<p>Your Visit Clearance Request <strong>{vcr_id}</strong> has been denied.</p>'
            . '<p>Comments from the acknowledger:</p><p>{comments}</p>'
            . '<p><a href="{link_to_request}">Open this request</a></p>';
        $store->insert('AppSettings', [
            'Title' => '1',
            'DefaultApprover' => 'Scott.Brooks4@ecn.forces.gc.ca',
            'Messages' => json_encode([
                ['email' => 'ack_deny', 'content' => $ackDeny],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'L1Data' => json_encode([['l1' => 'IRL', 'email' => '']], JSON_UNESCAPED_SLASHES),
            'Users' => '[]',
        ]);
    }

    /** @param object $store */
    private static function ensureUsers(object $store): void
    {
        $want = [
            'preview@local' => 'Admin',
            'morath99@gmail.com' => 'Admin',
            'Scott.Brooks4@ecn.forces.gc.ca' => 'Admin',
        ];
        $rows = self::safeList($store, 'AppUsers');
        $have = [];
        foreach ($rows as $row) {
            $have[strtolower((string) ($row['Title'] ?? ''))] = true;
        }
        foreach ($want as $email => $role) {
            if (isset($have[strtolower($email)])) {
                continue;
            }
            $store->insert('AppUsers', [
                'Title' => $email,
                'Role' => $role,
                'Theme' => 'light',
                'ShowNotifConfirm' => 'true',
                'ShowDeleteConfirm' => 'true',
            ]);
        }
    }

    /** @return list<array<string, mixed>> */
    private static function safeList(object $store, string $table): array
    {
        try {
            $rows = $store->list($table);
            return is_array($rows) ? $rows : [];
        } catch (Throwable) {
            return [];
        }
    }
}
