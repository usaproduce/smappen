<?php
declare(strict_types=1);

namespace App\TruckPlanner\Data;

/**
 * The binary form of location vectors (04_BACKEND.md 1.4).
 *
 * One block is 50 little-endian IEEE-754 doubles (400 bytes) in the order
 *
 *     capture.day[0..15], capture.eve[0..15], nearby[0..15], rivals.day, rivals.eve
 *
 * `tp_places.host_vec` holds one block (visibility normal). `tp_spots.vectors_bin` holds three, in the
 * order hidden, normal, prominent (1,200 bytes). The round trip is exact to the bit.
 *
 * Writing `vectors_bin`: bind toHex(...) and write `UNHEX(?)` in the statement, so only ASCII travels
 * through the string bind. Reading either column: select it as it is and pass the bytes to fromBytes().
 */
final class VectorCodec
{
    public const BLOCK_DOUBLES = 50;
    public const BLOCK_BYTES = 400;
    private const SEGMENTS = 16;

    /**
     * The 50 doubles of one LocationVectors, in block order.
     *
     * @param array<string, mixed> $vectors needs `capture.day`, `capture.eve`, `nearby` (16 numbers each)
     *                                      and `rivals.day`, `rivals.eve`
     * @return list<float>
     */
    public static function flat(array $vectors): array
    {
        $out = [];
        foreach ([$vectors['capture']['day'], $vectors['capture']['eve'], $vectors['nearby']] as $sixteen) {
            if (!is_array($sixteen) || count($sixteen) !== self::SEGMENTS) {
                throw new \LengthException('a location vector has 16 numbers per segment list');
            }
            foreach (array_values($sixteen) as $x) {
                $out[] = (float) $x;
            }
        }
        $out[] = (float) $vectors['rivals']['day'];
        $out[] = (float) $vectors['rivals']['eve'];
        return $out;
    }

    /**
     * The inverse of flat(): the stored part of a LocationVectors. The caller adds the labels
     * (visibility, in_region, exclusion and so on).
     *
     * @param list<float> $fifty
     * @return array{capture: array{day: list<float>, eve: list<float>}, nearby: list<float>,
     *               rivals: array{day: float, eve: float}}
     */
    public static function fromFlat(array $fifty): array
    {
        if (count($fifty) !== self::BLOCK_DOUBLES) {
            throw new \LengthException('a vector block has 50 numbers');
        }
        $fifty = array_values($fifty);
        return [
            'capture' => [
                'day' => array_slice($fifty, 0, self::SEGMENTS),
                'eve' => array_slice($fifty, self::SEGMENTS, self::SEGMENTS),
            ],
            'nearby' => array_slice($fifty, 2 * self::SEGMENTS, self::SEGMENTS),
            'rivals' => ['day' => $fifty[48], 'eve' => $fifty[49]],
        ];
    }

    /**
     * Doubles as the hexadecimal text of their little-endian bytes (16 characters each), for `UNHEX(?)`.
     *
     * @param list<float> $doubles
     */
    public static function toHex(array $doubles): string
    {
        $values = [];
        foreach ($doubles as $x) {
            $x = (float) $x;
            if (!is_finite($x)) {
                throw new \DomainException('a non-finite number cannot be stored in a vector');
            }
            $values[] = $x;
        }
        if ($values === []) {
            return '';
        }
        return bin2hex(pack('e*', ...$values));
    }

    /**
     * The doubles of a binary column value, in order. The number of doubles is the byte length over 8.
     *
     * @return list<float>
     */
    public static function fromBytes(string $bytes): array
    {
        $length = strlen($bytes);
        if ($length % 8 !== 0) {
            throw new \LengthException('vector bytes come in groups of 8');
        }
        if ($length === 0) {
            return [];
        }
        $unpacked = unpack('e' . ($length >> 3), $bytes);
        if ($unpacked === false) {
            throw new \UnexpectedValueException('vector bytes could not be read');
        }
        /** @var list<float> $values */
        $values = array_values($unpacked);
        return $values;
    }
}
