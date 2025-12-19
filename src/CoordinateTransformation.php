<?php
declare(strict_types=1);

namespace Macwake\BvrPatch;

use Exception;

class CoordinateTransformation
{
    private array $params = ['a' => 0, 'b' => 0, 'c' => 0, 'd' => 0, 'e' => 0, 'f' => 0];

    public function __construct(
        Coordinate $a_ref1,
        Coordinate $a_ref2,
        Coordinate $a_ref3,
        Coordinate $b_ref1,
        Coordinate $b_ref2,
        Coordinate $b_ref3,
    ) {
        $this->calculateMatrix([$a_ref1, $a_ref2, $a_ref3], [$b_ref1, $b_ref2, $b_ref3]);
    }

    /**
     * Solves the linear system for affine transformation
     * @param array<Coordinate> $src
     * @param array<Coordinate> $dst
     * @throws Exception
     */
    private function calculateMatrix(array $src, array $dst): void
    {
        // System for x': a*x + b*y + c = x'
        $x_params = $this->solve3x3(
            $src[0]->x,
            $src[0]->y,
            1,
            $dst[0]->x,
            $src[1]->x,
            $src[1]->y,
            1,
            $dst[1]->x,
            $src[2]->x,
            $src[2]->y,
            1,
            $dst[2]->x
        );

        // System for y': d*x + e*y + f = y'
        /** @noinspection PhpSuspiciousNameCombinationInspection */
        $y_params = $this->solve3x3(
            $src[0]->x,
            $src[0]->y,
            1,
            $dst[0]->y,
            $src[1]->x,
            $src[1]->y,
            1,
            $dst[1]->y,
            $src[2]->x,
            $src[2]->y,
            1,
            $dst[2]->y
        );

        $this->params = [
            'a' => $x_params[0], 'b' => $x_params[1], 'c' => $x_params[2],
            'd' => $y_params[0], 'e' => $y_params[1], 'f' => $y_params[2]
        ];
    }

    private function solve3x3($a1, $b1, $c1, $d1, $a2, $b2, $c2, $d2, $a3, $b3, $c3, $d3): array
    {
        $det = $a1 * ($b2 * $c3 - $c2 * $b3) - $b1 * ($a2 * $c3 - $c2 * $a3) + $c1 * ($a2 * $b3 - $b2 * $a3);
        if (abs($det) < 1e-9) throw new Exception("Points are collinear; cannot compute transformation.");

        $res1 = ($d1 * ($b2 * $c3 - $c2 * $b3) - $b1 * ($d2 * $c3 - $c2 * $d3) + $c1 * ($d2 * $b3 - $b2 * $d3)) / $det;
        $res2 = ($a1 * ($d2 * $c3 - $c2 * $d3) - $d1 * ($a2 * $c3 - $c2 * $a3) + $c1 * ($a2 * $d3 - $d2 * $a3)) / $det;
        $res3 = ($a1 * ($b2 * $d3 - $d2 * $b3) - $b1 * ($a2 * $d3 - $d2 * $a3) + $d1 * ($a2 * $b3 - $b2 * $a3)) / $det;

        return [$res1, $res2, $res3];
    }

    public function transform(Coordinate $orig): Coordinate
    {
        return new Coordinate(
            $this->params['a'] * $orig->x + $this->params['b'] * $orig->y + $this->params['c'],
            $this->params['d'] * $orig->x + $this->params['e'] * $orig->y + $this->params['f']
        );
    }
}
