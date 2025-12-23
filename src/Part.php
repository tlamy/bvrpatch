<?php
declare(strict_types=1);

namespace Macwake\BvrPatch;

class Part
{
    public string $side;
    public float $originX;
    public float $originY;
    public Coordinate $center;
    public Coordinate $boundingBoxMin;
    public Coordinate $boundingBoxMax;
    public string $mount;
    public string $package;
    public string $outlineType;
    /** @var array<array{float,float}>|null */
    public ?array $outlineRelative = null;
    /** @var array<string,Pin> */
    public array $pins = [];
    // already matched pins
    private array $matched = [];
    private string $orientation = '';

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
            } elseif (count($this->pins) > 1) {
                $x1 = null;
                $y1 = null;
                foreach ($this->pins as $pin) {
                    if ($x1 === null || $pin->origin->x - $pin->radius < $x1) {
                        $x1 = $pin->origin->x - $pin->radius;
                    }
                    if ($y1 === null || $pin->origin->y - $pin->radius < $y1) {
                        $y1 = $pin->origin->y - $pin->radius;
                    }
                    if ($x2 === null || $pin->origin->x + $pin->radius > $x2) {
                        $x2 = $pin->origin->x + $pin->radius;
                    }
                    if ($y2 === null || $pin->origin->y + $pin->radius > $y2) {
                        $y2 = $pin->origin->y + $pin->radius;
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
            } elseif (count($this->pins) === 1) {
                $this->center = $this->pins[array_key_first($this->pins)]->origin;
            }
            if ($x1 !== null && $y1 !== null && $x2 !== null && $y2 !== null) {
                $this->center = new Coordinate(($x1 + $x2) / 2, ($y1 + $y2) / 2);
                $this->boundingBoxMin = new Coordinate($x1, $y1);
                $this->boundingBoxMax = new Coordinate($x2, $y2);
                $this->orientation = $x2 - $x1 > $y2 - $y1 ? 'H' : 'V';
            }
        }
        return $this;
    }

    public function findPin(Pin $pin, Coordinate $distance): ?Pin
    {
        $searchCoords = new Coordinate($this->center->x + $distance->x, $this->center->y + $distance->y);
        echo "Find pin " . $pin->id . " around {$searchCoords}\n";
        $candidates = [];
        foreach ($this->pins as $candidate) {
            if (!isset($this->matched[$candidate->id])) {
                $candidates[$candidate->id] = $searchCoords->distance($candidate->origin);
            }
        }
        uasort($candidates, static fn(float $a, float $b) => $a <=> $b);
        $firstIndex = array_key_first($candidates);
        if ($candidates[$firstIndex] > 5) {
            $matchPin = $this->findPinById($firstIndex);
            echo "No candidate found within distance (closest is {$candidates[$firstIndex]} orig=" . $pin . "  match=" . $matchPin . ").\n";
            return null;
        }
        echo "found $firstIndex\n";
        $this->matched[$firstIndex] = true;
        return $this->findPinById($firstIndex);
    }

    public function findPinAt(Pin $pin, Coordinate $searchCoords, int|float $maxDistance): ?Pin
    {
        //$searchCoords = new Coordinate($this->center->x + $distance->x, $this->center->y + $distance->y);
        echo "Search for pin " . $pin->id . " around {$searchCoords}\n";
        $candidates = [];
        foreach ($this->pins as $candidate) {
            if (!isset($this->matched[$candidate->id])) {
                $candidates[$candidate->id] = $searchCoords->distance($candidate->origin);
            }
        }
        uasort($candidates, static fn(float $a, float $b) => $a <=> $b);
        $firstIndex = array_key_first($candidates);
        $matchPin = $this->findPinById($firstIndex);
        if ($candidates[$firstIndex] > $maxDistance) {
            echo "No candidate found within distance (closest is {$candidates[$firstIndex]} orig=" . $pin . "  match=" . $matchPin . "  maxD=$maxDistance).\n";
            return null;
        }
        echo "found $firstIndex at {$matchPin?->origin} with distance {$candidates[$firstIndex]}\n";
        $this->matched[$firstIndex] = true;
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

    /**
     * @return 'H'|'V'
     */
    public function getOrientation(): string
    {
        return $this->orientation;
    }
}