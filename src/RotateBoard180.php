<?php
declare(strict_types=1);

namespace Macwake\BvrPatch;

class RotateBoard180
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
            "Usage: php %s -i <original bvr> -o <outfile>\n",
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
            } elseif ($argv[$i] === "-o") {
                $i++;
                $outputFle = $argv[$i];
            } else {
                echo "Unknown option {$argv[$i]}\n";
                $this->usage($argv[0]);
                return 127;
            }
        }
        if ($sourceFilename == null || $outputFle == null) {
            $this->usage($argv[0]);
            return 127;
        }

        $orig = $this->bvr3Format->read($sourceFilename);

        $minX = null;
        $minY = null;
        $maxX = null;
        $maxY = null;
        foreach ($orig->outline as $coordinate) {
            if ($minX === null || $coordinate->x < $minX) {
                $minX = $coordinate->x;
            }
            if ($minY === null || $coordinate->y < $minY) {
                $minY = $coordinate->y;
            }
            if ($maxX === null || $coordinate->x > $maxX) {
                $maxX = $coordinate->x;
            }
            if ($maxY === null || $coordinate->y > $maxY) {
                $maxY = $coordinate->y;
            }
        }

        $resolved = new Board($outputFle);

        foreach ($orig->getParts() as $part) {
            $newPart = clone($part);
//            $newPart->originX = $maxX - $newPart->originX;
//            $newPart->originY = $maxY - $newPart->originY;
            if ($newPart->outlineRelative !== null) {
                $newOutline = [];
                foreach ($newPart->outlineRelative as $coords) {
                    $newOutline[] = [$maxX - $coords[0], $maxY - $coords[1]];
                }
                $newPart->outlineRelative = $newOutline;
            }
            foreach ($newPart->pins as $pin) {
                $pin->origin = new Coordinate($maxX - $pin->origin->x, $maxY - $pin->origin->y);
            }
            $resolved->addPart($newPart);
        }
        $resolved->outline = [];
        foreach ($orig->outline as $coord) {
            $resolved->outline[] = new Coordinate($maxX - $coord->x, $maxY - $coord->y);
        }
        $this->bvr3Format->write($outputFle, $resolved);
        return 0;
    }
}