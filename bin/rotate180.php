<?php
declare(strict_types=1);

use Macwake\BvrPatch\RotateBoard180;

require __DIR__ . '/../vendor/autoload.php';

$app = new RotateBoard180();
$app->run($argv);