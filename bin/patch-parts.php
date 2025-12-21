<?php
declare(strict_types=1);

use Macwake\BvrPatch\PatchParts;

require __DIR__ . '/../vendor/autoload.php';

$app = new PatchParts();
$app->run($argv);