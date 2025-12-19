<?php
declare(strict_types=1);

namespace Macwake\BvrPatch;

class Part
{
    public string $side;
    public float $originX;
    public float $originY;
    public Coordinate $center;
    public string $mount;
    public string $package;
    public string $outlineType;
    public array $outlineRelative;
    /** @var array<string,Pin> */
    public array $pins = [];

    public function __construct(
        public string $name,
    ) {
    }

    public function addPin(Pin $pin): void
    {
        $this->pins[$pin->id] = $pin;
    }

    public function normalizeCoords(): self
    {
        $x1 = null;
        $y1 = null;
        $x2 = null;
        $y2 = null;
        if ($this->originX === 0.0 && $this->originY === 0.0) {
            if (isset($this->outlineRelative) && count($this->outlineRelative) > 0) {
                foreach ($this->outlineRelative as $coord) {
                    if ($x1 === null || $coord[0] < $x1) {
                        $x1 = $coord[0];
                    }
                    if ($y1 === null || $coord[1] < $y1) {
                        $y1 = $coord[1];
                    }
                    if ($x2 === null || $coord[0] > $x2) {
                        $x2 = $coord[0];
                    }
                    if ($y2 === null || $coord[1] > $y2) {
                        $y2 = $coord[1];
                    }
                }
                $this->center = new Coordinate(($x1 + $x2) / 2, ($y1 + $y2) / 2);
            } elseif (count($this->pins) > 0) {
                $x1 = null;
                $y1 = null;
                foreach ($this->pins as $pin) {
                    if ($x1 === null || $pin->originX < $x1) {
                        $x1 = $pin->originX;
                    }
                    if ($y1 === null || $pin->originY < $y1) {
                        $y1 = $pin->originY;
                    }
                    if ($x2 === null || $pin->originX > $x2) {
                        $x2 = $pin->originX;
                    }
                    if ($y2 === null || $pin->originY > $y2) {
                        $y2 = $pin->originY;
                    }
                    if ($pin->outline !== null)
                        foreach ($pin->outline as $coord) {
                            if ($pin->originX + (float)$coord[0] < $x1) {
                                $x1 = $pin->originX + (float)$coord[0];
                            }
                            if ($pin->originY + (float)$coord[1] < $y1) {
                                $y1 = $pin->originY + (float)$coord[1];
                            }
                            if ($pin->originX + (float)$coord[0] > $x2) {
                                $x2 = $pin->originX + (float)$coord[0];
                            }
                            if ($pin->originY + (float)$coord[1] > $y2) {
                                $y2 = $pin->originY + (float)$coord[1];
                            }
                        }
                }
                $this->center = new Coordinate(($x1 + $x2) / 2, ($y1 + $y2) / 2);
            }
        }
        return $this;
    }

    public function findPin(Pin $pin): ?Pin
    {
        if(isset($this->pins[$pin->id])){
            return $this->pins[$pin->id];
        }
        return null;
    }
}