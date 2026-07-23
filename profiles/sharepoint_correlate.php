<?php

declare(strict_types=1);

return [
    'description' => 'Correlate SharePoint list connections with a schema (or patterns in the app), flag bad connections, and repair list/column typos.',
    'hops' => [
        [
            'id' => 'correlate_sharepoint',
            'options' => [
                'repair' => true,
                'max_distance' => 2,
                'repair_site_url' => false,
            ],
        ],
    ],
];
