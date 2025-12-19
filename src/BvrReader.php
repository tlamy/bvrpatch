<?php
declare(strict_types=1);

namespace Macwake\BvrPatch;

use Exception;

class BvrReader
{

    public function read(string $fileA): Board
    {
        $board = new Board();
        $part = null;
        $pin = null;
        $state = 'top';
        foreach (file($fileA) as $lineNo => $line) {
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
                        $pin->originX = (float)$parts[1];
                        $pin->originY = (float)$parts[2];
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
}