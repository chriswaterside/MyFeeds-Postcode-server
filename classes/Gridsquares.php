<?php
declare(strict_types=1);

namespace RaPostcode;

/** Converts OS National Grid easting/northing into a 100km square letter pair and a 10 figure grid reference. */
final class Gridsquares
{
    // rows are 100km northing bands from the south; columns are 100km eastings from the west
    private const SQUARES = [
        ['SV', 'SW', 'SX', 'SY', 'SZ', 'TV', ''],
        ['', 'SR', 'SS', 'ST', 'SU', 'TQ', 'TR'],
        ['', 'SM', 'SN', 'SO', 'SP', 'TL', 'TM'],
        ['', '', 'SH', 'SJ', 'SK', 'TF', 'TG'],
        ['', '', 'SC', 'SD', 'SE', 'TA', ''],
        ['', 'NW', 'NX', 'NY', 'NZ', '', ''],
        ['', 'NR', 'NS', 'NT', 'NU', '', ''],
        ['NL', 'NM', 'NN', 'NO', '', '', ''],
        ['NF', 'NG', 'NH', 'NJ', 'NK', '', ''],
        ['NA', 'NB', 'NC', 'ND', '', '', ''],
        ['', 'HW', 'HX', 'HY', 'HZ', '', ''],
        ['', '', '', 'HT', 'HU', '', ''],
        ['', '', '', '', 'HP', '', ''],
    ];

    /** @return array{0:string,1:string} [square, gridref]; both empty if outside the table */
    public static function getSquare(int $easting, int $northing): array
    {
        if ($easting <= 0 || $northing <= 0) {
            return ['', ''];
        }
        $e = intdiv($easting, 100000);
        $n = intdiv($northing, 100000);
        $square = self::SQUARES[$n][$e] ?? '';
        if ($square === '') {
            return ['', ''];
        }
        $gridref = $square . sprintf('%05d%05d', $easting - $e * 100000, $northing - $n * 100000);
        return [$square, $gridref];
    }
}
