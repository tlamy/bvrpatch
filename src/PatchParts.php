<?php
declare(strict_types=1);

namespace Macwake\BvrPatch;

use RuntimeException;

class PatchParts
{
    private Bvr3Format $bvr3Format;
    private CoordinateTransformation $transformer;
    private ?CoordinateTransformation $pinTransformer = null;
    /** @var array<string,Netname> */
    private array $netnames = [];

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
        $sourceFilename = null;
        $placeFilename = null;
        $pinsFilename = null;
        $outputFle = null;
        for ($i = 1; isset($argv[$i]); $i++) {
            if ($argv[$i] === "-i") {
                $i++;
                $sourceFilename = $argv[$i];
            } elseif ($argv[$i] === "-part") {
                $i++;
                $placeFilename = $argv[$i];
            } elseif ($argv[$i] === "-pin") {
                $i++;
                $pinsFilename = $argv[$i];
            } elseif ($argv[$i] === "-o") {
                $i++;
                $outputFle = $argv[$i];
            } elseif ($argv[$i] === "-R") {
                $i++;
                $referenceOpt = explode(',', $argv[$i]);
            } else {
                echo "Unknown option {$argv[$i]}\n";
                $this->usage($argv[0]);
                return 127;
            }
        }
        if ($sourceFilename == null || $placeFilename == null || $outputFle == null) {
            $this->usage($argv[0]);
            return 127;
        }
        if (count($referenceOpt) !== 3) {
            echo "Must specify exactly three references\n";
            $this->usage($argv[0]);
            return 1;
        }

        $orig = $this->bvr3Format->read($sourceFilename);
        $placeRef = $this->bvr3Format->read($placeFilename);
        $pinRef = $pinsFilename !== null
            ? $this->bvr3Format->read($pinsFilename) : null;

        $this->transformer = $this->buildTransformationMatrix($orig, $placeRef, $referenceOpt, false);
        if ($pinRef !== null) {
            $this->pinTransformer = $this->buildTransformationMatrix($placeRef, $pinRef, $referenceOpt, true);
        }
        // print_r($this->transformer);

        $resolved = new Board($outputFle);
        $badParts = $orig->getParts();
        usort($badParts, static fn(Part $a, Part $b) => count($b->pins) <=> count($a->pins));

        $unmatched = [];
        foreach ($badParts as $badPart) {
            $refLocation = $this->transformer->transform($badPart->center);
            echo "Finding match for {$badPart->name} at {$badPart->side}  {$badPart->center} => {$refLocation}  (" . count(
                    $badPart->pins
                ) . " pins)\n";
            $refPart = $placeRef->findPartByCenter(
                $refLocation,
                $this->transformer->isSwapSides() ? $this->swapSide($badPart->side) : $badPart->side,
                $badPart->pins
            );
            if ($refPart !== null && count($refPart->pins) === count($badPart->pins)) {
                $placeRef->addMatch($refPart);
                echo "Found matching part {$badPart->name} => {$refPart->name}  orig side=$badPart->side  ref side=$refPart->side\n";
                if ($pinRef !== null && ($pinPart = $pinRef->findPart($refPart->name)) !== null) {
                    $this->transformNets($badPart, $refPart, $pinPart);
                } else {
                    $this->transformNets($badPart, $refPart, null);
                }
                $resolved->addPart($this->patchPart($badPart, $refPart));
            } elseif (($ref2Part = $pinRef?->findPart($badPart->name)) !== null) {
                $placeRef->addMatch($ref2Part);
                echo "Found matching part {$badPart->name} => {$ref2Part->name}  orig side=$badPart->side  ref side=$ref2Part->side\n";
                $this->transformNets($badPart, $ref2Part, null);
                $resolved->addPart($this->patchPart($badPart, $ref2Part));
            } else {
                $unmatched[] = $badPart;
            }
        }
        foreach ($unmatched as $part) {
            $resolved->addPart($this->patchPart($part, null));
        }
        $this->bvr3Format->write($outputFle, $resolved);
        return 0;
    }

    private function swapSide(string $side): string
    {
        return $side === 'T' ? 'B' : 'T';
    }

    private function transformNets(Part $badPart, Part $refPart, ?Part $pinPart): bool
    {
        $minX = null;
        $maxY = null;
        $lastX = null;
        $lastY = null;
        $pitchX = null;
        $pitchY = null;
        foreach ($badPart->pins as $pin) {
            $origPinDistance = $this->pinPartRelativeDistance($badPart, $pin);
            $refPincoords = $this->pinLocation($refPart->center, $this->transformer->transform($origPinDistance));
            $refPinOrig = $this->transformer->transform($pin->origin);
            echo "{$badPart->name}:{$pin->id} {$origPinDistance} => {$refPincoords} {$refPinOrig}\n";
            //if (($refPin = $refPart->findPin($pin, $this->transformer->transform($origPinDistance))) !== null) {
            if (($refPin = $refPart->findPinAt($pin, $refPincoords, $pitchX ?? 10)) !== null) {
                if ($lastX === null) {
                    $lastX = $refPin->origin->x;
                    $lastY = $refPin->origin->y;
                } else {
                    if ($pitchX === null && $lastX !== $refPin->origin->x) {
                        $pitchX = $this->roundPitch($refPin->origin->x - $lastX, 10);
                        echo "PitchX={$pitchX}\n";
                    }
                    if ($pitchY === null && $lastY !== $refPin->origin->y) {
                        $pitchY = $this->roundPitch($refPin->origin->y - $lastY, 10);
                        echo "PitchY={$pitchY}\n";
                    }
                }
                if ($pinPart?->pins[$refPin->id] !== null) {
                    //echo "Found pin on pin-ref board\n";
                    $refPin = $pinPart?->pins[$refPin->id];
                } else {
                    echo "No pin on pin-ref board\n";
                }
                if (!isset($this->netnames[$pin->netname])) {
                    $this->netnames[$pin->netname] = new Netname(
                        $pin->netname, $refPin->netname, $pin->id, $refPin->id
                    );
                } elseif ($this->netnames[$pin->netname]->newName !== $refPin->netname) {
                    die (
                        "! net name differs {$badPart->name}:{$pin->id} = {$pin->netname}   Reference: {$refPart->name}:{$refPin->id} = {$refPin->netname}   current assigned net = " . json_encode(
                            $this->netnames[$pin->netname]
                        ) . "\n"
                    );
                    return false;
                }
            } else {
                die("{$badPart->name}:{$pin->id} not found\n");
                return false;
            }
        }
        return true;
    }

    private function patchPart(Part $badPart, ?Part $refPart): Part
    {
        $patched = clone($badPart);
        if ($refPart !== null) {
            $patched->name = $refPart->name;
        }
        foreach ($patched->pins as $pin) {
            if (array_key_exists($pin->netname, $this->netnames)) {
                $pin->netname = $this->netnames[$pin->netname]->newName;
            }
        }
        return $patched;
    }

    private function buildTransformationMatrix(
        Board $orig,
        Board $placeRef,
        array $referenceOpt,
        bool $byRefName
    ): ?CoordinateTransformation {
        /** @var list<list<Part>> $refParts */
        $refParts = [];
        foreach ($referenceOpt as $ref) {
            if (!str_contains($ref, '=')) {
                $origRef = $ref;
                $refRef = $ref;
            } else {
                [$origRef, $refRef] = explode('=', $ref);
            }
            $firstRef = $byRefName ? $refRef : $origRef;
            $ref1 = $orig->findPart($firstRef);
            if ($ref1 === null) {
//                echo "Part $firstRef not found in original ".$orig->getFilename()."\n";
                throw new RuntimeException("Part $firstRef not found in original " . $orig->getFilename());
            }
            $ref2 = $placeRef->findPart($refRef);
            if ($ref2 === null) {
                throw new RuntimeException("Part $refRef not found in reference " . $placeRef->getFilename());
            }
            if ($ref1 !== null && $ref2 !== null) {
                $refParts[] = [$ref1, $ref2];
                echo "{$ref1->name} is at {$ref1->center} - {$ref2->name} is at {$ref2->center}\n";
            }
        }
        return new CoordinateTransformation(
            $refParts[0][0]->center,
            $refParts[1][0]->center,
            $refParts[2][0]->center,
            $refParts[0][1]->center,
            $refParts[1][1]->center,
            $refParts[2][1]->center,
            $refParts[0][0]->side !== $refParts[0][1]->side
        );
    }

    private function roundPitch(float $distance, int $pitch): float
    {
        return round($distance / $pitch) * $pitch;
    }

    private function pinPartRelativeDistance(Part $part, Pin $pin): Coordinate
    {
        echo "Distance: part center={$part->center} pin origin={$pin->origin}\n";
        return new Coordinate($pin->origin->x - $part->center->x, $pin->origin->y - $part->center->y);
        //return new Coordinate($part->center->x - $pin->origin->x, $part->center->y - $pin->origin->y);
    }

    private function pinLocation(Coordinate $partCenter, Coordinate $delta): Coordinate
    {
        return new Coordinate($partCenter->x + $delta->x, $partCenter->y + $delta->y);
    }
}