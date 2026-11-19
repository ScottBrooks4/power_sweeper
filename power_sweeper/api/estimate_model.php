<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use PowerSweeper\EstimateModel;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$model = EstimateModel::load();
echo json_encode($model, JSON_UNESCAPED_SLASHES);
