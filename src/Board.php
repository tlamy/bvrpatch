<?php
declare(strict_types=1);

namespace Macwake\BvrPatch;

class Board
{
    /** @var Part[] */
    private array $parts = [];
    /** @var Pin[] */
    private array $pins = [];
    /** @var string[] */
    private array $nets = [];

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

    public function findPartByCenter(Coordinate $transform): ?Part
    {
        foreach ($this->parts as $part) {
            if ($part->center->equals($transform)) {
                return $part;
            }
        }
        return null;
    }

}