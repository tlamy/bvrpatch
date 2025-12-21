<?php
declare(strict_types=1);

namespace Macwake\BvrPatch;

use Exception;

class Bvr3Format
{

    public function read(string $filename): Board
    {
        $board = new Board($filename);
        $part = null;
        $pin = null;
        $state = 'top';
        foreach (file($filename) as $lineNo => $line) {
            $line = rtrim($line);
            if ($state === 'top' && $line !== '') {
                if ($line !== 'BVRAW_FORMAT_3') {
                    throw new Exception('Invalid file format ' . $line);
                }
                $state = 'root';
                continue;
            }
            if (trim($line) === '') {
                continue;
            }
            if ($state === 'root') {
                if (str_starts_with($line, 'PART_NAME')) {
                    $state = 'part';
                    $part = new Part(explode(' ', $line)[1]);
                    continue;
                }

                if (str_starts_with($line, 'OUTLINE_SEGMENTED')) {
                    $parts = explode(' ', trim($line));
                    $coords = [];
                    for ($j = 1, $jMax = count($parts); $j < $jMax; $j += 2) {
                        $coords[] = new Coordinate((float)$parts[$j], (float)$parts[$j + 1]);
                    }
                    $board->outline = $coords;
                    $board->outlineType = 'SEGMENTS';
                    continue;
                }
                if (str_starts_with($line, 'OUTLINE_POINTS')) {
                    $parts = explode(' ', trim($line));
                    $coords = [];
                    for ($j = 1, $jMax = count($parts); $j < $jMax; $j += 2) {
                        $coords[] = new Coordinate((float)$parts[$j], (float)$parts[$j + 1]);
                    }
                    $board->outline = $coords;
                    $board->outlineType = 'POINTS';
                    continue;
                }

                throw new Exception('Unknown (root) line ' . $lineNo . ': ' . $line);
            }
            if ($state === 'part') {
                if ($part === null) {
                    throw new Exception('No current part on line ' . $lineNo . ': ' . $line);
                }
                $parts = explode(' ', trim($line));
                switch ($parts[0]) {
                    case 'PART_SIDE':
                        $part->side = $parts[1];
                        break;
                    case 'PART_ORIGIN':
                        $part->originX = (float)$parts[1];
                        $part->originY = (float)$parts[2];
                        break;
                    case 'PART_MOUNT':
                        $part->mount = $parts[1];
                        break;
                    case 'PART_PACKAGE':
                        $part->package = $parts[1];
                        break;
                    case 'PART_OUTLINE_DISABLE':
                        $part->outlineType = 'DISABLE';
                        break;
                    case 'PART_OUTLINE_TYPE_SEGMENTS':
                        $part->outlineType = 'SEGMENTS';
                        break;
                    case 'PART_OUTLINE_RELATIVE':
                        $coords = [];
                        for ($j = 1, $jMax = count($parts); $j < $jMax; $j += 2) {
                            $coords[] = [(float)$parts[$j], (float)$parts[$j + 1]];
                        }
                        $part->outlineType = 'RELATIVE';
                        $part->outlineRelative = $coords;
                        break;
                    case 'PART_END':
                        $part->normalizeCoords();
                        $board->addPart($part);
                        $part = null;
                        $state = 'root';
                        break;
                    case 'PIN_ID':
                        $pin = new Pin($parts[1], $part->name);
                        $state = 'pin';
                        continue 2;
                    default:
                        throw new Exception('Unknown (part) line ' . $lineNo . ': ' . $line);
                }
            }
            if ($state === 'pin') {
                if ($pin === null) {
                    throw new Exception('No current pin on line ' . $lineNo . ': ' . $line);
                }
                $parts = explode(' ', trim($line));
                switch ($parts[0]) {
                    case 'PIN_NUMBER':
                        $pin->number = $parts[1];
                        break;
                    case 'PIN_NAME':
                        $pin->name = $parts[1];
                        break;
                    case 'PIN_SIDE':
                        $pin->side = $parts[1];
                        break;
                    case 'PIN_ORIGIN':
                        $pin->origin = new Coordinate((float)$parts[1], (float)$parts[2]);
                        break;
                    case 'PIN_RADIUS':
                        $pin->radius = (float)$parts[1];
                        break;
                    case 'PIN_NET':
                        $pin->netname = $parts[1];
                        $board->addNet($pin->netname);
                        break;
                    case 'PIN_TYPE':
                        if ($pin->type1 === null) {
                            $pin->type1 = $parts[1];
                        } else {
                            $pin->type2 = $parts[1];
                        }
                        break;
                    case 'PIN_COMMENT':
                        $pin->comment = $parts[1] ?? null;
                        break;
                    case 'PIN_OUTLINE_DISABLE':
                        $pin->outlineType = 'DISABLE';
                        break;
                    case 'PIN_OUTLINE_RELATIVE':
                        $pin->outlineType = 'RELATIVE';
                        $pin->outline = $parts;
                        break;
                    case 'PIN_END':
                        $board->addPin($pin);
                        $part->addPin($pin);
                        $pin = null;
                        $state = 'part';
                        break;
                    default:
                        throw new Exception('Unknown (pin) line ' . $lineNo . ': ' . $line);
                }
            }
        }
        return $board;
    }

    public function write(string $filename, Board $board): void
    {
        $f = fopen($filename, 'wb');
        fprintf($f, "BVRAW_FORMAT_3\n");
        foreach ($board->getParts() as $part) {
            fprintf($f, "\nPART_NAME %s\n", $part->name);
            fprintf($f, "   PART_SIDE %s\n", $part->side);
            fprintf($f, "   PART_ORIGIN %0.3f %0.3f\n", $part->originX, $part->originY);
            fprintf($f, "   PART_MOUNT %s\n", $part->mount);
            if ($part->outlineType === 'DISABLE') {
                fprintf($f, "   PART_OUTLINE_DISABLE\n");
            } elseif ($part->outlineType === 'SEGMENTS') {
                fprintf($f, "   PART_OUTLINE_SEGMENTS\n");
            } elseif ($part->outlineType === 'RELATIVE') {
                fprintf($f, "   PART_OUTLINE_RELATIVE");
                foreach ($part->outlineRelative as $coord) {
                    fprintf($f, " %0.3f %0.3f", $coord[0], $coord[1]);
                }
                fprintf($f, "\n");
            }
            foreach ($part->pins as $pin) {
                fprintf($f, "\n   PIN_ID %s\n", $pin->id);
                fprintf($f, "      PIN_NUMBER %s\n", $pin->number);
                fprintf($f, "      PIN_NAME %s\n", $pin->name);
                fprintf($f, "      PIN_SIDE %s\n", $pin->side);
                fprintf($f, "      PIN_ORIGIN %0.3f %0.3f\n", $pin->origin->x, $pin->origin->y);
                fprintf($f, "      PIN_RADIUS %0.3f\n", $pin->radius);
                fprintf($f, "      PIN_NET %s\n", $pin->netname);
                fprintf($f, "      PIN_TYPE %s\n", $pin->type1);
                if ($pin->type2 !== null) {
                    fprintf($f, "      PIN_TYPE %s\n", $pin->type2);
                }
                fprintf($f, "      PIN_COMMENT %s\n", $pin->comment);
                if ($pin->outlineType === 'DISABLE') {
                    fprintf($f, "      PIN_OUTLINE_DISABLE\n");
                } elseif ($pin->outlineType === 'RELATIVE') {
                    fprintf($f, "      PIN_OUTLINE_RELATIVE");
                    foreach ($pin->outline as $item) {
                        fprintf($f, " %s", $item);
                    }
                    fprintf($f, "\n");
                }
                fprintf($f, "   PIN_END\n");
            }
            fprintf($f, "PART_END\n");
        }
        if ($board->outline !== null) {
            fprintf($f, "\nOUTLINE_SEGMENTED");
            foreach ($board->outline as $coord) {
                fprintf($f, " %0.3f %0.3f", $coord->x, $coord->y);
            }
            fprintf($f, "\n\n");
        }
        fclose($f);
    }
}