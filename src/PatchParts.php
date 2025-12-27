<?php
declare(strict_types=1);

namespace Macwake\BvrPatch;

use RuntimeException;

class PatchParts
{
    private Bvr3Format $bvr3Format;
    private CoordinateTransformation $transformer;
    /** @var array<string,Netname> */
    private array $netnames = [];
    private array $newNets = [];

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
        $pinRef = $pinsFilename !== null ? $this->bvr3Format->read($pinsFilename) : null;

        $this->transformer = $this->buildTransformationMatrix($orig, $placeRef, $referenceOpt, false);

        $resolved = new Board($outputFle);
        $badParts = $orig->getParts();
        usort($badParts, static fn(Part $a, Part $b) => count($b->pins) <=> count($a->pins));

        /** @var Part[] $unmatched */
        $unmatched = [];
        /** @var string[] $matched */
        $matched = [];
        foreach ($badParts as $badPart) {
            $refLocation = $this->transformer->transform($badPart->center);
            echo "Finding match for {$badPart->name} at {$badPart->side}  {$badPart->center} => {$refLocation}  (" .
                count($badPart->pins) . " pins)\n";
            $refPart = $placeRef->findPartByCenter(
                $refLocation,
                $this->transformer->isSwapSides() ? $this->swapSide($badPart->side) : $badPart->side,
                $badPart->pins
            );
            if ($refPart !== null && count($refPart->pins) === count($badPart->pins) && $badPart->getOrientation(
                ) === $refPart->getOrientation()) {
                $placeRef->addMatch($refPart);
                echo "(1)Found matching part {$badPart->name} => {$refPart->name}  orig side=$badPart->side  ref side=$refPart->side\n";
                if (
                    $pinRef !== null
                    && ($pinPart = $pinRef->findPart($refPart->name)) !== null
                    && count($pinPart->pins) === count($refPart->pins)
                    && $badPart->getOrientation() === $pinPart->getOrientation()
                ) {
                    echo "Found matching pin-ref part {$refPart->name} => {$pinPart->name}\n";
                    $this->matchPins($badPart, $refPart, $pinPart, $orig);
                } else {
                    echo "No matching pin-ref part {$refPart->name}\n";
                    $this->matchPins($badPart, $refPart, null, $orig);
                }
                $resolved->addPart($this->patchPart($badPart, $refPart));
            } elseif (($ref2Part = $pinRef?->findPart($badPart->name)) !== null
                && $badPart->getOrientation() === $ref2Part->getOrientation()) {
                $placeRef->addMatch($ref2Part);
                echo "(2)Found matching part {$badPart->name} => {$ref2Part->name}  orig side=$badPart->side  ref side=$ref2Part->side\n";
                $this->matchPins($badPart, $ref2Part, null, $orig);
                $resolved->addPart($this->patchPart($badPart, $ref2Part));
            } else {
                $unmatched[] = $badPart;
            }
        }
        echo "Start matching by pins and know nets...\n";
        // Most parts are matched and have proper nets assigned.
        foreach ($unmatched as $index => $part) {
            if (($pinPart = $this->findPartByPins($part, $pinRef, $resolved)) !== null) {
                echo "P2: Found matching pin-ref part {$part->name} => {$pinPart->name}\n";
                $resolved->addPart($this->patchPart($part, $pinPart));
                unset($unmatched[$index]);
            } elseif (($pinPart = $this->findPartByPins($part, $placeRef, $resolved)) !== null) {
                echo "P2: Found matching place-ref part {$part->name} => {$pinPart->name}\n";
                $resolved->addPart($this->patchPart($part, $pinPart));
                unset($unmatched[$index]);
            }

            echo "Still unmatched parts: " . count($unmatched) . " :-/\n";
        }
        foreach ($unmatched as $part) {
            $resolved->addPart($this->patchPart($part, null));
        }
        $resolved->outlineType = $orig->outlineType;
        $resolved->outline = $orig->outline;
        $this->bvr3Format->write($outputFle, $resolved);
        return 0;
    }

    private function swapSide(string $side): string
    {
        return $side === 'T' ? 'B' : 'T';
    }

    private function matchPins(Part $origPart, Part $refPart, ?Part $pinPart, Board $origBoard): bool
    {
        $lastX = null;
        $lastY = null;
        $pitchX = null;
        $pitchY = null;
        foreach ($origPart->pins as $pin) {
            if ($pin->netname === 'GND' || $pin->netname === 'NC') {
                continue;
            }
            if (in_array($pin->netname, array_map(fn(Netname $net) => $net->newName, $this->netnames))) {
                continue;
            }
            $pinOffset = $this->pinPartRelativeDistance($origPart, $pin);
            $refPincoords = $this->pinLocation($refPart->center, $pinOffset);
            if ($pinPart !== null) {
                $pinPinCoords = $this->pinLocation($pinPart->center, $pinOffset);
                if (($pinPin = $pinPart->findPinAt($pin, $pinPinCoords, $pitchX ?? 10)) !== null) {
                    echo "Found on PIN board: {$origPart->name}:{$pin->id} D={$pinOffset} C={$pin->origin} => {$pinPinCoords} {$pinPin->origin} = {$pinPin->name}:{$pinPin->id}\n";
                    $refPin = $pinPart?->pins[$pinPin->id];

                    if (!isset($this->netnames[$pin->netname])) {
                        $this->assignNetwork($pin, $refPin, $origBoard);
                    } elseif ($this->netnames[$pin->netname]->newName !== $refPin->netname) {
                        if ($this->isToleratedNetChange($this->netnames[$pin->netname]->newName, $refPin->netname)) {
                            continue;
                        }
                        if (!str_ends_with($this->netnames[$pin->netname]->newName, '_XW')) {
                            die (
                                __LINE__ . "! net name differs {$origPart->name}:{$pin->id} = {$pin->netname}   Reference: {$refPart->name}:{$refPin->id} = {$refPin->netname}   current assigned net = " . json_encode(
                                    $this->netnames[$pin->netname]
                                ) . "\n"
                            );
                            return false;
                        }

                        $this->assignNetworkName(
                            $this->netnames[$pin->netname]->newName,
                            $refPin->netname,
                            $this->netnames[$pin->netname]->origPinId,
                            $this->netnames[$pin->netname]->refPinId,
                            $origBoard
                        );
                    }
                    continue;
                }
            }
            $refPinOrig = $this->transformer->transform($pin->origin);
            echo "{$origPart->name}:{$pin->id} {$pinOffset} => {$refPincoords} {$refPinOrig}\n";
            //if (($refPin = $refPart->findPin($pin, $this->transformer->transform($origPinDistance))) !== null) {
            if (($refPin = $refPart->findPinAt($pin, $refPinOrig, $pitchX ?? 20)) !== null) {
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
                $this->assignNetwork($pin, $refPin, $origBoard);
                if (!isset($this->netnames[$pin->netname])) {
                    $this->netnames[$pin->netname] = new Netname(
                        $pin->netname, $refPin->netname, $pin->id, $refPin->id
                    );
                } elseif ($this->netnames[$pin->netname]->newName !== $refPin->netname) {
                    if ($this->isToleratedNetChange($this->netnames[$pin->netname]->newName, $refPin->netname)) {
                        continue;
                    }
                    die (
                        __LINE__ . "! net name differs {$origPart->name}:{$pin->id} = {$pin->netname}   Reference: {$refPart->name}:{$refPin->id} = {$refPin->netname}   current assigned net = " . json_encode(
                            $this->netnames[$pin->netname]
                        ) . "\n"
                    );
                    return false;
                }
            } else {
                die("{$origPart->name}:{$pin->id} not found\n");
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
        return abs(round($distance / $pitch) * $pitch);
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

    public function assignNetwork(Pin $pin, Pin $refPin, Board $origBoard): void
    {
        if ($pin->netname === $refPin->netname) {
            return;
        }
        if (isset($this->newNets[$refPin->netname])) {
            return;
        }
        $this->netnames[$pin->netname] = new Netname(
            $pin->netname, $refPin->netname, $pin->id, $refPin->id
        );
        $origBoard->transformNet($this->netnames[$pin->netname]);
        $this->newNets[$refPin->netname] = true;
    }

    private function assignNetworkName(
        ?string $netname,
        string $newName,
        string $origPinId,
        string $refPinId,
        Board $origBoard
    ) {
        if ($netname === $newName) {
            return;
        }
        if (isset($this->newNets[$newName])) {
            return;
        }
        $this->netnames[$netname] = new Netname($netname, $newName, $origPinId, $refPinId);
        $origBoard->transformNet($this->netnames[$netname]);
        $this->newNets[$newName] = true;
    }

    private function isToleratedNetChange(string $netname, string $newName): bool
    {
        echo "compare $netname to $newName\n";
        return (str_starts_with($netname, 'PP') && str_starts_with($newName, 'PP'))
            || (preg_match('/^SPKRAMP_[A-F]_PVDD_SNS$/', $netname) > 0 && preg_match(
                    '/^PPBUS_AON_[RL]_SPKRAMP$/',
                    $newName
                ) > 0)
            || ($netname === "PPBUS_AON" && preg_match('/^PP*BUS_.*/', $newName) > 0);
    }


    public function findPartByPins(Part $part, Board $refBoard, Board $resolved): ?Part
    {
        if (count($part->pins) === 1) {
            return null;
        }
        $candidates = array_filter(
            $refBoard->getParts(),
            static function (Part $candidate) use ($part, $resolved) {
                return count($candidate->pins) === count($part->pins)
                    && !$resolved->hasPart($candidate->name)
                    && !str_starts_with($candidate->name, 'XW');
            }
        );
        echo "Found " . count($candidates) . " candidates for {$part->name}\n";
        if (count($candidates) === 0) {
            return null;
        }
        if (count($candidates) > 1) {
            if (($refPart = $refBoard->findPartByNetnames($part, $candidates)) === null) {
                return null;
            }
            $origPins = array_values($part->pins);
            $refPins = array_values($refPart->pins);
            $pinsMatch = true;
            foreach ($origPins as $idx => $origPin) {
                $refPin = $refPins[$idx];
                if ($origPin->netname !== $refPin->netname && !str_starts_with($origPin->netname, 'Net')) {
                    $pinsMatch = false;
                    echo "{$part->name}/{$refPart->name}: Net mismatch: {$origPin->netname} != {$refPin->netname}\n";
                    break;
                }
            }
            if ($pinsMatch) {
                echo "{$part->name}/{$refPart->name}: Replacing net names\n";
                foreach ($origPins as $idx => $origPin) {
                    $refPin = $refPins[$idx];
                    if ($origPin->netname !== $refPin->netname && str_starts_with($origPin->netname, 'Net')) {
                        $resolved->transformNet(
                            new Netname($origPin->netname, $refPin->netname, $origPin->id, $refPin->id)
                        );
                    }
                }
                return $refPart;
            }
            return null;
        }

        $refPart = array_values($candidates)[0];
        if (!$this->walkPinsReplaceNets($part, $refPart, $resolved)) return null;
        // validate refPart pins
        return $refPart;
    }

    /**
     * @param Part[] $candidates
     */
    private function findPartByNetnames(Part $part, array $candidates): ?Part
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

    public function walkPinsReplaceNets(Part $orig, Part $refPart, Board $board): bool
    {
        // find pin 1
        /** @var Pin|null $origPin1 */
        $origPin1 = array_values(
            array_filter(
                $orig->pins,
                static function (Pin $pin) {
                    return (string)$pin->number === '1';
                }
            )
        )[0] ?? null;
        /** @var Pin|null $refPin1 */
        $refPin1 = array_values(
            array_filter(
                $refPart->pins,
                static function (Pin $pin) {
                    return (string)$pin->number === '1';
                }
            )
        )[0] ?? null;
        if ($origPin1 === null) {
            echo "Pin 1 not found in {$orig->name}";
            return $this->shapeMatch($orig, $refPart, $board);
        }
        if ($refPin1 === null) {
            echo "Pin 1 not found in {$refPart->name}";
            return $this->shapeMatch($orig, $refPart, $board);
        }
        $o1distance = $orig->center->sub($origPin1->origin);
        $r1distance = $refPart->center->sub($refPin1->origin);
        if ($o1distance->distance($r1distance) > 10) {
            echo "{$orig->name}/{$refPart->name}: Pin 1 is different; trying shape matching\n";
            return $this->shapeMatch($orig, $refPart, $board);
        }
        // calculate pin 2 offset
        $origPins = array_values($orig->pins);
        $refPins = array_values($refPart->pins);
        echo "{$orig->name}/{$refPart->name}: Replacing net names (3)\n";
        foreach ($origPins as $idx => $origPin) {
            $distanceFromPin1 = $origPin1->origin->sub($origPin->origin);
            echo "Orig: {$origPin->name} => {$origPin->origin}   Dist: {$distanceFromPin1}\n";
            $refPinLocation = $refPin1->origin->sub($distanceFromPin1);
            $ref1PinDistance = $refPin1->origin->sub($refPinLocation);
            echo "Ref: {$refPinLocation} is {$ref1PinDistance} from pin1 at {$refPin1}\n";
            $refPin = $refPart->findPinAt($origPin, $refPinLocation, $origPin->radius);
            if ($refPin === null) {
                print("Pin {$origPin->name} not found in {$refPart->name}\n");
                return $this->shapeMatch($orig, $refPart, $board);
            }
            if ($origPin->netname !== $refPin->netname && str_starts_with($origPin->netname, 'Net')) {
                $board->transformNet(
                    new Netname($origPin->netname, $refPin->netname, $origPin->id, $refPin->id)
                );
            }
        }
        return true;
    }

    private function shapeMatch(Part $orig, Part $refPart, Board $resolved): bool
    {
        echo "Shape matching {$orig->name} to {$refPart->name} using center-relative vectors\n";

        $matchedPairs = [];
        foreach ($orig->pins as $oPin) {
            // Calculate vector from original center to pin
            $vectorX = $oPin->origin->x - $orig->center->x;
            $vectorY = $oPin->origin->y - $orig->center->y;

            // Project this vector onto the reference part center
            $targetCoords = new Coordinate($refPart->center->x + $vectorX, $refPart->center->y + $vectorY);

            // Find the closest pin on the reference part (with 2.0 tolerance)
            $rPin = $refPart->findPinAt($oPin, $targetCoords, $oPin->radius * 1.5);

            if ($rPin === null) {
                echo "Shape mismatch: Pin {$oPin->id} of {$orig->name} has no counterpart in {$refPart->name} at relative position.";
                return false;
            }
            $matchedPairs[] = ['orig' => $oPin, 'ref' => $rPin];
        }

        // Validation: Ensure all GND pins in the mapping actually have the 'GND' netname on both sides
        foreach ($matchedPairs as $pair) {
            /** @var Pin $oPin */
            $oPin = $pair['orig'];
            /** @var Pin $rPin */
            $rPin = $pair['ref'];

            if ($oPin->netname === 'GND' && $rPin->netname !== 'GND') {
                echo "Validation failed: GND pin {$oPin->id} mapped to non-GND net {$rPin->netname} in {$refPart->name}";
                return false;
            }
        }

        echo "Shape validation passed! Mapping nets...\n";
        foreach ($matchedPairs as $pair) {
            /** @var Pin $oPin */
            $oPin = $pair['orig'];
            /** @var Pin $rPin */
            $rPin = $pair['ref'];

            if ($oPin->netname !== $rPin->netname && str_starts_with($oPin->netname, 'Net')) {
                echo "  Mapping {$oPin->netname} -> {$rPin->netname}\n";
                $resolved->transformNet(
                    new Netname($oPin->netname, $rPin->netname, $oPin->id, $rPin->id)
                );
            }
        }
        return true;
    }

}