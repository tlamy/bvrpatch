<?php
declare(strict_types=1);

use Macwake\BvrPatch\Application;

require __DIR__ . '/../vendor/autoload.php';

$app = new Application();
$app->run($argv);