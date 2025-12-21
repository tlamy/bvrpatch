<?php
declare(strict_types=1);

namespace Macwake\BvrPatch;

class Netname
{

    public function __construct(
        public string $origName,
        public string $newName,
        public string $origPinId,
        public string $refPinId
    ) {
    }
}