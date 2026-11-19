<?php
declare(strict_types=1);

/**
 * Microsoft sign-in and the scorecard ↔ SharePoint mapping, in one place.
 *
 * Anything starting with PLACEHOLDER is not confirmed by Scott yet. The sync
 * leaves a field out while its column is a placeholder, and refuses to run at
 * all while the site, list or key column is one. Nothing here is a secret: the
 * sign-in is a public client (device code), like api/teams.php.
 */

return [
    'microsoft' => [
        // Same tenant as api/devops.php and api/teams.php.
        'tenant' => '325b4494-1587-40d5-bb31-8b660b7f1038',
        // Same public client api/teams.php signs in with (Microsoft Graph Command Line Tools).
        'client' => '14d82eec-204b-4c2f-b7e8-296a70dab67e',
        // PLACEHOLDER (unconfirmed): commercial | usgov-gcc | usgov-high | usgov-dod.
        // Pivot already signs in at login.microsoftonline.com and links to 018gc.sharepoint.com,
        // both commercial hosts, so commercial is the working assumption until Scott confirms.
        'cloud' => 'commercial',
        // Delegated Graph scopes per feature. Sign-in asks for all of them at once,
        // so a feature added here (for example the org chart calendar) reuses the same sign-in.
        'scopes' => [
            'scorecard' => ['Sites.ReadWrite.All'],
            'calendar' => ['Calendars.ReadBasic'],
        ],
    ],

    'scorecard_sync' => [
        // Master switch. While false the endpoint answers "turned off" and makes no Graph call.
        'enabled' => false,
        // dry-run | write. In dry-run, apply answers 423 and nothing is written anywhere.
        'mode' => 'dry-run',

        // PLACEHOLDER: GET /sites/018gc.sharepoint.com:/sites/<path> returns "host,siteCollectionId,webId".
        'site_id' => 'PLACEHOLDER_SITE_ID',
        // PLACEHOLDER: the Project Scores list GUID (GET /sites/{site}/lists?$select=id,displayName).
        // Point this at a test copy of the list first, never the production list.
        'list_id' => 'PLACEHOLDER_LIST_ID',
        'list_name' => 'Project Scores',
        // PLACEHOLDER: the column on each list item that holds the Pivot use case id.
        // data/do-scores.csv shows a "UseCaseID" lookup; Graph exposes a lookup as <InternalName>LookupId.
        'key_column' => 'PLACEHOLDER:UseCaseIDLookupId',

        // Local field in data/scores.json => SharePoint internal column name.
        //   type:  score (whole number 1 to 5) | level (L0, L0.5 … L4) | text (up to 2000 characters)
        //   owner: sharepoint (only pulled) | pivot (only pushed) | shared (three-way merge)
        // Every column and owner below is a PLACEHOLDER until Scott confirms the list's columns.
        'fields' => [
            'level' => ['column' => 'PLACEHOLDER:Level', 'type' => 'level', 'owner' => 'sharepoint'],
            'strategic' => ['column' => 'PLACEHOLDER:StrategicValue', 'type' => 'score', 'owner' => 'shared'],
            'reach' => ['column' => 'PLACEHOLDER:ReachValue', 'type' => 'score', 'owner' => 'shared'],
            'business' => ['column' => 'PLACEHOLDER:BusinessValue', 'type' => 'score', 'owner' => 'shared'],
            'strategicWhy' => ['column' => 'PLACEHOLDER:StrategicJustification', 'type' => 'text', 'owner' => 'pivot'],
            'reachWhy' => ['column' => 'PLACEHOLDER:ReachJustification', 'type' => 'text', 'owner' => 'pivot'],
        ],

        // A plan with more writes than this (pulls, pushes and creates together) is blocked
        // unless an admin raises the cap for that one plan.
        'max_writes_per_run' => 50,
        // How long a previewed plan stays applicable.
        'plan_ttl_seconds' => 1800,
        // Create list items for scorecard rows that have none. Off: Pivot only updates.
        'allow_create' => false,
        // State, plans, backups and the audit log. Created by the web server user on first use, with a
        // deny-all .htaccess because it is inside the web root. Keep it off /home: httpd runs with ProtectHome.
        'storage_dir' => dirname(__DIR__, 2) . '/data/scorecard-sync',
    ],
];
