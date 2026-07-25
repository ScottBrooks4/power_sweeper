<?php

declare(strict_types=1);

return [
    'description' => 'Rare: force .msapp zip entry paths to POSIX forward slashes. Default is to preserve Windows backslashes from the source app (recommended for Studio).',
    'hops' => [
        ['id' => 'set_zip_path_style', 'options' => ['style' => 'posix']],
    ],
];
