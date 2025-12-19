<?php
declare(strict_types=1);

namespace Macwake\BvrPatch;

class Pin
{
    public string $number;
    public string $name;
    public string $side;
    public float $originX;
    public float $originY;
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
    ) {
    }
}