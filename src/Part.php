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
                    if ($x1 === null || $pin->origin->x < $x1) {
                        $x1 = $pin->origin->x;
                    }
                    if ($y1 === null || $pin->origin->y < $y1) {
                        $y1 = $pin->origin->y;
                    }
                    if ($x2 === null || $pin->origin->x > $x2) {
                        $x2 = $pin->origin->x;
                    }
                    if ($y2 === null || $pin->origin->y > $y2) {
                        $y2 = $pin->origin->y;
                    }
                    if ($pin->outline !== null)
                        foreach ($pin->outline as $coord) {
                            if ($pin->origin->x + (float)$coord[0] < $x1) {
                                $x1 = $pin->origin->x + (float)$coord[0];
                            }
                            if ($pin->origin->y + (float)$coord[1] < $y1) {
                                $y1 = $pin->origin->y + (float)$coord[1];
                            }
                            if ($pin->origin->x + (float)$coord[0] > $x2) {
                                $x2 = $pin->origin->x + (float)$coord[0];
                            }
                            if ($pin->origin->y + (float)$coord[1] > $y2) {
                                $y2 = $pin->origin->y + (float)$coord[1];
                            }
                        }
                }
                $this->center = new Coordinate(($x1 + $x2) / 2, ($y1 + $y2) / 2);
            }
        }
        return $this;
    }

    public function findPin(Pin $pin, Coordinate $distance): ?Pin
    {
        echo "Find pin " . $pin->id . " around {$distance}\n";
        $searchCoords = new Coordinate($this->center->x + $distance->x, $this->center->y + $distance->y);
        $candidates = [];
        foreach ($this->pins as $candidate) {
            $candidates[$candidate->id] = $searchCoords->distance($candidate->origin);
        }
        uasort($candidates, static fn(float $a, float $b) => $a <=> $b);
        $firstIndex = array_key_first($candidates);
        echo "found $firstIndex\n";
        return $this->findPinById($firstIndex);
    }

    private function findPinById(int|string|null $pinId): ?Pin
    {
        if ($pinId === null) {
            echo "No pin id specified.\n";
            return null;
        }
        foreach ($this->pins as $pin) {
            if ($pin->id === $pinId) {
                return $pin;
            }
        }
        return null;
    }
}