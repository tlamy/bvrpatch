<?php
declare(strict_types=1);

namespace Macwake\BvrPatch;

class Board
{
    /** @var array<string,Part> */
    private array $parts = [];
    /** @var array<string,Pin> */
    private array $pins = [];
    /** @var string[] */
    private array $nets = [];
    private array $matched = [];

    public function addPart(Part $part): void
    {
        $this->parts[$part->name] = $part;
    }

    public function addPin(Pin $pin): void
    {
        $this->pins[$pin->id] = $pin;
    }

    public function addNet(?string $net): void
    {
        if ($net !== null) {
            $this->nets[$net] = true;
        }
    }

    public function hasNet(string $net): bool
    {
        return isset($this->nets[$net]);
    }

    public function findPart(string $name): ?Part
    {
        return $this->parts[$name] ?? null;
    }

    public function findPin(int $id): ?Pin
    {
        return $this->pins[$id] ?? null;
    }

    public function getParts(): array
    {
        return $this->parts;
    }

    public function findPartByCenter(Coordinate $transform, string $side, array $pins, float $maxDistance = 4): ?Part
    {
        $candidates = [];
        foreach ($this->parts as $part) {
            if (
                array_key_exists($part->name, $this->matched)
                || $part->side !== $side
                || count($part->pins) !== count($pins)) {
                continue;
            }
            $candidates[$part->name] = $this->distance($part->center, $transform);
        }

        if (empty($candidates)) {
            return null;
        }

        asort($candidates, SORT_NUMERIC);
        $closestPartName = array_key_first($candidates);
//        $count = 0;
//        foreach($candidates as $partName => $distance) {
//            $count++;
//            if($count > 3) {
//                break;
//            }
//            echo "Candidate $partName: $distance  at  {$this->parts[$partName]->center}\n";
//        }

        return $candidates[$closestPartName] < $maxDistance ? $this->parts[$closestPartName] : null;
    }

    public function addMatch(Part $part): void
    {
        $this->matched[] = $part->name;
    }
    private function distance(Coordinate $center, Coordinate $transform): float
    {
        return hypot($center->x - $transform->x, $center->y - $transform->y);
    }

}