<?php
declare(strict_types=1);

namespace Macwake\BvrPatch;

use Stringable;

class Pin implements Stringable
{
    public string $number;
    public string $name;
    public string $side;
    public Coordinate $origin;
    public float $radius;
    public ?string $netname = null;
    public ?string $type1 = null;
    public ?string $type2 = null;
    public ?string $comment = null;
    public ?string $outlineType = null;
    public ?array $outline = null;

    public function __construct(
        public string $id,
        public string $partId,
    )
    {
    }

    public function __toString(): string
    {
        return "#" . $this->number . " id" . $this->id . " @" . $this->origin . " radius=" . $this->radius;
    }
}