<?php
declare(strict_types=1);

namespace Macwake\BvrPatch;

class Application
{
    private ?string $fileOrig = null;
    private ?string $fileRef = null;
    private ?string $outfile = null;
    private array $references = [];
    private BvrReader $bvrReader;

    public function __construct()
    {
        $this->bvrReader = new BvrReader();
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

        $orig = $this->bvrReader->read($this->fileOrig);
        $reference = $this->bvrReader->read($this->fileRef);

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
        $transformer = new CoordinateTransformation(
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
        print_r($transformer);

        $netnames = [];
        $resolved = new Board();
        $badParts = $orig->getParts();
        usort($badParts, static fn(Part $a, Part $b) => count($b->pins) <=> count($a->pins));

        foreach ($badParts as $badPart) {
            $refLocation = $transformer->transform($badPart->center);
            echo "Finding match for {$badPart->name} at {$badPart->side}  {$badPart->center} => {$refLocation}\n";
            $refPart = $reference->findPartByCenter($refLocation, $swapSides ? $this->swapSide($badPart->side) : $badPart->side, $badPart->pins);
            if ($refPart !== null) {
                if (count($refPart->pins) !== count($badPart->pins)) {
                    echo "Part {$badPart->name} has different number of pins\n";
                } else {
                    $reference->addMatch($refPart);
                }
                echo "Found matching part {$badPart->name} => {$refPart->name}  bad side=$badPart->side  ref side=$refPart->side\n";
                $this->transformNets($badPart, $refPart, $netnames);
            }
        }
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
            if(($refPin = $refPart->findPin($pin)) !== null) {
                if(!isset($netnames[$pin->netname])) {
                    $netnames[$pin->netname] = $refPin->netname;
                } elseif($netnames[$pin->netname] !== $refPin->netname) {
                    echo "! net name differs {$badPart->name}:{$pin->id} = {$pin->netname}   Reference: {$refPart->name}:{$refPin->id} = {$refPin->netname}   current assigned net = {$netnames[$pin->netname]}\n";
                    return false;
                }
            }
        }
        return true;
    }

}