<?php
declare(strict_types=1);

namespace Macwake\BvrPatch;

use Stringable;

class Coordinate implements Stringable
{
    public function __construct(
        public readonly float $x,
        public readonly float $y,
    ) {
    }

    public function distance(Coordinate $other): float
    {
        return hypot($this->x - $other->x, $this->y - $other->y);
    }

    public function __toString(): string
    {
        return sprintf('%0.3f,%0.3f', $this->x, $this->y);
    }
}