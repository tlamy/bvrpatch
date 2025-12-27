<?php
declare(strict_types=1);

namespace Macwake\BvrPatch;

class Board
{
    /** @var Coordinate[] */
    public array $outline = [];
    public string $outlineType;
    /** @var array<string,Part> indexed by part name */
    private array $parts = [];
    /** @var array<string,Pin> */
    private array $pins = [];
    /** @var string[] */
    private array $nets = [];
    private array $matched = [];
    private string $filename;

    public function __construct(string $filename)
    {
        $this->filename = basename($filename);
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

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
            $candidates[$part->name] = $part->center->distance($transform);
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

    public function transformNet(Netname $netname): void
    {
        foreach ($this->parts as $part) {
            foreach ($part->pins as $pin) {
                if ($pin->netname === $netname->origName) {
                    $pin->netname = $netname->newName;
                }
            }
        }
    }

    /**
     * @param Part[] $candidates
     */
    public function findPartByNetnames(Part $part, array $candidates): ?Part
    {
        // Filter target pins to only those with "known" netnames
        /** @var Pin[] $targetPinsToMatch */
        $targetPinsToMatch = array_filter(
            $part->pins,
            static function (Pin $pin) {
                return $pin->netname !== null && !str_starts_with($pin->netname, 'Net');
            }
        );

        if (empty($targetPinsToMatch)) {
            return null;
        }

        foreach ($candidates as $candidate) {
            $isMatch = true;
            foreach ($targetPinsToMatch as $targetPin) {
                // Find the corresponding pin on the candidate by pin number
                $candidatePin = null;
                foreach ($candidate->pins as $cp) {
                    if ($cp->number === $targetPin->number) {
                        $candidatePin = $cp;
                        break;
                    }
                }

                if ($candidatePin === null || $candidatePin->netname !== $targetPin->netname) {
                    $isMatch = false;
                    break;
                }
            }

            if ($isMatch) {
                return $candidate;
            }
        }
        return null;
    }

    public function hasPart(string $name): bool
    {
        return array_key_exists($name, $this->parts);
    }
}