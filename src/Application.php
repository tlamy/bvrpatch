<?php
declare(strict_types=1);

namespace Macwake\BvrPatch;

class Application
{
    private ?string $fileA = null;
    private ?string $fileB = null;
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
            "Usage: php %s -i <reference bvr> -p <bvr to patch> -o <outfile> -r <three,parts,reference></reference>\n",
            $arg0
        );
    }

    public function run(array $argv): int
    {
        for ($i = 1; isset($argv[$i]); $i++) {
            if ($argv[$i] === "-i") {
                $i++;
                $this->fileA = $argv[$i];
            } elseif ($argv[$i] === "-p") {
                $i++;
                $this->fileB = $argv[$i];
            } elseif ($argv[$i] === "-o") {
                $i++;
                $this->outfile = $argv[$i];
            } elseif ($argv[$i] === "-r") {
                $i++;
                $this->references = explode(',', $argv[$i]);
            } else {
                $this->usage($argv[0]);
                return 127;
            }
        }
        if ($this->fileA == null || $this->fileB == null || $this->outfile == null) {
            $this->usage($argv[0]);
            return 127;
        }
        if (count($this->references) !== 3) {
            echo "Must specify exactly three references\n";
            $this->usage($argv[0]);
            return 1;
        }

        $orig = $this->bvrReader->read($this->fileA);
        $bad = $this->bvrReader->read($this->fileB);

        $refParts = [];
        foreach ($this->references as $ref) {
            $ref1 = $orig->findPart($ref);
            if ($ref1 === null) {
                echo "Reference $ref not found in $this->fileA\n";
                return 2;
            }
            $ref2 = $bad->findPart($ref);
            if ($ref2 === null) {
                echo "Reference $ref not found in $this->fileB\n";
                return 2;
            }
            if ($ref1 !== null && $ref2 !== null) {
                $refParts[] = [$ref1, $ref2];
            }
        }
        $transformer = new CoordinateTransformation(
            $refParts[0][0]->center, $refParts[0][1]->center,
            $refParts[1][0]->center, $refParts[1][1]->center,
            $refParts[2][0]->center, $refParts[2][1]->center
        );
        echo "Calculated transformation matrix\n";
        //print_r($transformer);

        $resolved = new Board();
        foreach ($bad->getParts() as $badPart) {
            $origPart = $orig->findPartByCenter($transformer->transform($badPart->center));
            if ($origPart !== null) {
                if (count($origPart->pins) !== count($badPart->pins)) {
                    echo "Part {$badPart->name} has different number of pins\n";
                }
                echo "Found matching part {$badPart->name} => {$origPart->name}\n";
            }
        }
        return 0;
    }

}