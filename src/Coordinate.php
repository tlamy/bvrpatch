<?php
declare(strict_types=1);

namespace Macwake\BvrPatch;

class Coordinate
{
    public function __construct(
        public readonly float $x,
        public readonly float $y,
    ) {
    }

    public function equals(Coordinate $other): bool
    {
        return abs($this->x - $other->x) < 5
            && abs($this->y - $other->y) < 5;
    }
}