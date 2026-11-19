<?php

declare(strict_types=1);

return [
    'description' => 'Force .msapp zip entry paths to Windows backslashes (Studio-native). Usually unnecessary — source style is preserved by default.',
    'hops' => [
        ['id' => 'set_zip_path_style', 'options' => ['style' => 'windows']],
    ],
];
