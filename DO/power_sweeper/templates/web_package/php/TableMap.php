<?php

declare(strict_types=1);

if (class_exists('TableMap', false)) {
    return;
}

/**
 * Map SharePoint list names, LOM connector entity sets, and SQL safe names
 * onto one canonical table. Aligns with /srv/http/LOM/powerapps/entities.conf:
 *   Tracking|tracking|VCRTrackingList
 *   Approvals|approval|ApprovalTracking
 *   Locations|location|MemberLocations
 *   Users|user|AppUsers
 *   Settings|settings|AppSettings
 */
final class TableMap
{
    /** @var array<string, list<string>> canonical => aliases */
    private const GROUPS = [
        'VCRTrackingList' => [
            'VCRTrackingList',
            'Tracking',
            'CDLS (L) VCR Tracking List',
            'CDLS_L_VCR_Tracking_List',
        ],
        'ApprovalTracking' => [
            'ApprovalTracking',
            'Approvals',
            'CDLS (L) Approval Tracking',
            'CDLS_L_Approval_Tracking',
        ],
        'MemberLocations' => [
            'MemberLocations',
            'Locations',
            'CDLS (L) Member Locations',
            'CDLS_L_Member_Locations',
        ],
        'AppUsers' => [
            'AppUsers',
            'Users',
            'CDLS (L) App Users',
            'CDLS_L_App_Users',
        ],
        'AppSettings' => [
            'AppSettings',
            'Settings',
            'CDLS (L) App Settings',
            'CDLS_L_App_Settings',
        ],
        'UserTracking' => [
            'UserTracking',
            'CDLS (L) User Tracking',
            'CDLS_L_User_Tracking',
        ],
    ];

    /** Connector row XML tag per parent (entities.conf). */
    private const ROW_TAGS = [
        'VCRTrackingList' => 'tracking',
        'ApprovalTracking' => 'approval',
        'MemberLocations' => 'location',
        'AppUsers' => 'user',
        'AppSettings' => 'settings',
    ];

    public static function normalize(string $name): string
    {
        $name = trim($name, " \t\n\r\0\x0B'\"");
        $name = preg_replace('/[^\p{L}\p{N}]+/u', '_', $name) ?? $name;
        return strtolower(trim($name, '_'));
    }

    public static function isPlausible(string $name): bool
    {
        $raw = trim($name, " \t\n\r\0\x0B'\"");
        if ($raw === '' || strlen($raw) > 80) {
            return false;
        }
        if (str_contains($raw, "\n") || str_contains($raw, '=')) {
            return false;
        }

        return (bool) preg_match('/^[\p{L}\p{N} _().#-]+$/u', $raw);
    }

    public static function canonical(string $name): string
    {
        $want = self::normalize($name);
        foreach (self::GROUPS as $canon => $aliases) {
            foreach ($aliases as $alias) {
                if (self::normalize($alias) === $want) {
                    return $canon;
                }
            }
        }
        $safe = preg_replace('/\W+/', '_', trim($name, " \t\n\r\0\x0B'\"")) ?: 'Items';

        return $safe;
    }

    /** @return list<string> */
    public static function candidates(string $name): array
    {
        $canon = self::canonical($name);
        $out = [$canon];
        foreach (self::GROUPS[$canon] ?? [] as $alias) {
            $safe = preg_replace('/\W+/', '_', $alias) ?: $alias;
            if (!in_array($alias, $out, true)) {
                $out[] = $alias;
            }
            if (!in_array($safe, $out, true)) {
                $out[] = $safe;
            }
        }
        $rawSafe = preg_replace('/\W+/', '_', trim($name, " \t\n\r\0\x0B'\"")) ?: 'Items';
        if (!in_array($rawSafe, $out, true)) {
            $out[] = $rawSafe;
        }

        return array_values(array_unique($out));
    }

    public static function rowTag(string $canonical): string
    {
        return self::ROW_TAGS[self::canonical($canonical)] ?? 'row';
    }

    public static function safeIdent(string $name): string
    {
        return preg_replace('/\W+/', '_', $name) ?: 'Items';
    }

    public static function safeColumn(string $name): string
    {
        $c = preg_replace('/\W+/', '_', $name) ?: 'f';

        return $c;
    }

    /**
     * Expose SharePoint-style aliases on a stored row so
     * LookUp(...).'Default Approver' and DefaultApprover both resolve.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function aliasRow(array $row): array
    {
        $extra = [];
        foreach ($row as $k => $v) {
            $key = (string) $k;
            $collapsed = preg_replace('/[\s_#]+/', '', $key) ?: $key;
            $underscored = preg_replace('/[\s#]+/', '_', $key) ?: $key;
            if (!array_key_exists($collapsed, $row)) {
                $extra[$collapsed] = $v;
            }
            if (!array_key_exists($underscored, $row)) {
                $extra[$underscored] = $v;
            }
            $spaced = preg_replace('/_+/', ' ', $key);
            if (is_string($spaced) && $spaced !== $key && !array_key_exists($spaced, $row)) {
                $extra[$spaced] = $v;
            }
        }
        if (isset($row['DefaultApprover']) && !isset($row['Default Approver'])) {
            $extra['Default Approver'] = $row['DefaultApprover'];
        }
        if (isset($row['Default_Approver']) && !isset($row['Default Approver'])) {
            $extra['Default Approver'] = $row['Default_Approver'];
        }
        if (isset($row['L1_Data']) && !isset($row['L1 Data'])) {
            $extra['L1 Data'] = $row['L1_Data'];
        }
        if (isset($row['L1Data']) && !isset($row['L1 Data'])) {
            $extra['L1 Data'] = $row['L1Data'];
        }
        if (isset($row['Id']) && !isset($row['ID'])) {
            $extra['ID'] = $row['Id'];
        }
        if (isset($row['ID']) && !isset($row['Id'])) {
            $extra['Id'] = $row['ID'];
        }

        return $extra === [] ? $row : $row + $extra;
    }
}
