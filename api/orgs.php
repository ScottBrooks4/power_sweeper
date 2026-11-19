<?php

require __DIR__ . '/org_list.php';

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-cache');
echo 'window.VASC_ORGS=' . json_encode(orgRows(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';';
