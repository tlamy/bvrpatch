<?php
declare(strict_types=1);

namespace Macwake\BvrPatch;

class Application
{
    private ?string $fileOrig = null;
    private ?string $fileRef = null;
    private ?string $outfile = null;
    private array $references = [];
    private Bvr3Format $bvr3Format;
    private CoordinateTransformation $transformer;

    public function __construct()
    {
        $this->bvr3Format = new Bvr3Format();
    }

    private function usage(string $arg0): void
    {
        fprintf(
            STDERR,
            "Usage: php %s -i <original bvr> -r <reference bvr> -o <outfile> -r <three,parts,reference>\n",
            $arg0
        );
    }

    public function run(array $argv): int
    {
        for ($i = 1; isset($argv[$i]); $i++) {
            if ($argv[$i] === "-i") {
                $i++;
                $this->fileOrig = $argv[$i];
            } elseif ($argv[$i] === "-r") {
                $i++;
                $this->fileRef = $argv[$i];
            } elseif ($argv[$i] === "-o") {
                $i++;
                $this->outfile = $argv[$i];
            } elseif ($argv[$i] === "-R") {
                $i++;
                $this->references = explode(',', $argv[$i]);
            } else {
                $this->usage($argv[0]);
                return 127;
            }
        }
        if ($this->fileOrig == null || $this->fileRef == null || $this->outfile == null) {
            $this->usage($argv[0]);
            return 127;
        }
        if (count($this->references) !== 3) {
            echo "Must specify exactly three references\n";
            $this->usage($argv[0]);
            return 1;
        }

        $orig = $this->bvr3Format->read($this->fileOrig);
        $reference = $this->bvr3Format->read($this->fileRef);

        /** @var list<list<Part>> $refParts */
        $refParts = [];
        foreach ($this->references as $ref) {
            if(!str_contains($ref, '=')) {
                $origRef = $ref;
                $refRef = $ref;
            } else {
                [$origRef, $refRef] = explode('=', $ref);
            }
            $ref1 = $orig->findPart($origRef);
            if ($ref1 === null) {
                echo "Part $origRef not found in original $this->fileOrig\n";
                return 2;
            }
            $ref2 = $reference->findPart($refRef);
            if ($ref2 === null) {
                echo "Part $refRef not found in reference $this->fileRef\n";
                return 2;
            }
            if ($ref1 !== null && $ref2 !== null) {
                $refParts[] = [$ref1, $ref2];
                echo "{$ref1->name} is at {$ref1->center} - {$ref2->name} is at {$ref2->center}\n";
            }
        }
        $this->transformer = new CoordinateTransformation(
            $refParts[0][0]->center,
            $refParts[1][0]->center,
            $refParts[2][0]->center,
            $refParts[0][1]->center,
            $refParts[1][1]->center,
            $refParts[2][1]->center
        );
        echo "Calculated transformation matrix\n";


        $swapSides = $refParts[0][0]->side !== $refParts[0][1]->side;

        echo "Orig side = {$refParts[0][0]->side}  Reference side = {$refParts[0][1]->side}\n";
        // print_r($this->transformer);

        $netnames = [];
        $resolved = new Board();
        $badParts = $orig->getParts();
        usort($badParts, static fn(Part $a, Part $b) => count($b->pins) <=> count($a->pins));

        $unmatched = [];
        foreach ($badParts as $badPart) {
            $refLocation = $this->transformer->transform($badPart->center);
            echo "Finding match for {$badPart->name} at {$badPart->side}  {$badPart->center} => {$refLocation}  (" . count(
                    $badPart->pins
                ) . " pins)\n";
            $refPart = $reference->findPartByCenter($refLocation, $swapSides ? $this->swapSide($badPart->side) : $badPart->side, $badPart->pins);
            if ($refPart !== null && count($refPart->pins) === count($badPart->pins)) {
                $reference->addMatch($refPart);
                echo "Found matching part {$badPart->name} => {$refPart->name}  bad side=$badPart->side  ref side=$refPart->side\n";
                $this->transformNets($badPart, $refPart, $netnames);
                $resolved->addPart($this->patchPart($badPart, $refPart, $netnames));
            } else {
                $unmatched[] = $badPart;
            }
        }
        foreach ($unmatched as $part) {
            $resolved->addPart($this->patchPart($part, null, $netnames));
        }
        $this->bvr3Format->write($this->outfile, $resolved);
        return 0;
    }

    private function swapSide(string $side): string
    {
        return $side === 'T' ? 'B' : 'T';
    }

    /**
     * @param array<string,string> $netnames
     */
    private function transformNets(Part $badPart, Part $refPart, array &$netnames): bool
    {
        foreach ($badPart->pins as $pin) {
            $badPinDistance = $this->pinPartRelativeDistance($badPart, $pin);
            if (($refPin = $refPart->findPin($pin, $this->transformer->transform($badPinDistance))) !== null) {
                if(!isset($netnames[$pin->netname])) {
                    $netnames[$pin->netname] = $refPin->netname;
                } elseif($netnames[$pin->netname] !== $refPin->netname) {
                    echo "! net name differs {$badPart->name}:{$pin->id} = {$pin->netname}   Reference: {$refPart->name}:{$refPin->id} = {$refPin->netname}   current assigned net = {$netnames[$pin->netname]}\n";
                    return false;
                }
            } else {
                die("{$badPart->name}:{$pin->id} not found\n");
            }
        }
        return true;
    }

    private function pinPartRelativeDistance(Part $part, Pin $pin): Coordinate
    {
        return new Coordinate($pin->origin->x - $part->center->x, $pin->origin->y - $part->center->y);
    }

    private function patchPart(Part $badPart, ?Part $refPart, array $netnames): Part
    {
        $patched = clone($badPart);
        if ($refPart !== null) {
            $patched->name = $refPart->name;
        }
        foreach ($patched->pins as $pin) {
            if (array_key_exists($pin->netname, $netnames)) {
                $pin->netname = $netnames[$pin->netname];
            }
        }
        return $patched;
    }
}