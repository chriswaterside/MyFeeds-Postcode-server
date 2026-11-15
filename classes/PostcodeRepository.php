<?php
declare(strict_types=1);

namespace RaPostcode;

use mysqli;

/** Read-only queries used by the feed. All values are bound parameters. */
final class PostcodeRepository
{
    public function __construct(private mysqli $db)
    {
    }

    /** Remove spaces and upper-case. Callers must validate the result with isValidPostcode(). */
    public static function normalise(string $postcode): string
    {
        return strtoupper(preg_replace('/\s+/', '', $postcode));
    }

    public static function isValidPostcode(string $normalised): bool
    {
        return (bool) preg_match('/^[A-Z]{1,2}[0-9][A-Z0-9]?[0-9][A-Z]{2}$/', $normalised);
    }

    /** @return array[] rows shaped for the JSON response */
    public function byPostcode(string $normalised): array
    {
        $stmt = $this->db->prepare(
            'SELECT postcode, quality, easting, northing FROM postcodes WHERE postcode_key = ?'
        );
        $stmt->bind_param('s', $normalised);
        $stmt->execute();
        $out = [];
        foreach ($stmt->get_result() as $row) {
            $out[] = self::shape($row, 0.0);
        }
        $stmt->close();
        return $out;
    }

    /**
     * Nearest postcodes within $distance metres, closest first.
     * The bounding box lets MySQL use the (easting, northing) index; the exact circle and ordering are done in SQL.
     *
     * @return array[]
     */
    public function near(int $easting, int $northing, int $distance, int $limit): array
    {
        $e1 = $easting - $distance;
        $e2 = $easting + $distance;
        $n1 = $northing - $distance;
        $n2 = $northing + $distance;
        $d2 = $distance * $distance;
        $stmt = $this->db->prepare(
            'SELECT postcode, quality, easting, northing,
                    (POW(CAST(easting AS SIGNED) - ?, 2) + POW(CAST(northing AS SIGNED) - ?, 2)) AS d2
               FROM postcodes
              WHERE easting BETWEEN ? AND ? AND northing BETWEEN ? AND ?
             HAVING d2 <= ?
              ORDER BY d2
              LIMIT ?'
        );
        $stmt->bind_param('iiiiiiii', $easting, $northing, $e1, $e2, $n1, $n2, $d2, $limit);
        $stmt->execute();
        $out = [];
        foreach ($stmt->get_result() as $row) {
            $out[] = self::shape($row, sqrt((float) $row['d2']));
        }
        $stmt->close();
        return $out;
    }

    private static function shape(array $row, float $distance): array
    {
        $e = (int) $row['easting'];
        $n = (int) $row['northing'];
        [$square, $gridref] = Gridsquares::getSquare($e, $n);
        // Same keys and order as the original feed
        return [
            'Postcode' => $row['postcode'],
            'Quality' => (int) $row['quality'],
            'Distance' => $distance,
            'Easting' => $e,
            'Northing' => $n,
            'Square' => $square,
            'Gridref' => $gridref,
        ];
    }
}
