<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Data;

use App\TruckPlanner\Data\VectorCodec;
use PHPUnit\Framework\TestCase;

final class VectorCodecTest extends TestCase
{
    /**
     * @return array{capture: array{day: list<float>, eve: list<float>}, nearby: list<float>, rivals: array{day: float, eve: float}}
     */
    private static function vectors(float $shift = 0.0): array
    {
        $day = [];
        $eve = [];
        $nearby = [];
        for ($s = 0; $s < 16; $s++) {
            $day[] = 100.0 + $s + $shift + 1 / 3;
            $eve[] = 200.0 + $s + $shift + 0.1;
            $nearby[] = 300.0 + $s + $shift + 1.0e-7;
        }
        return [
            'capture' => ['day' => $day, 'eve' => $eve],
            'nearby' => $nearby,
            'rivals' => ['day' => 0.834 + $shift, 'eve' => 1.25 + $shift],
        ];
    }

    public function testBlockOrder(): void
    {
        $flat = VectorCodec::flat(self::vectors());
        self::assertCount(50, $flat);
        self::assertSame(100.0 + 1 / 3, $flat[0]);
        self::assertSame(115.0 + 1 / 3, $flat[15]);
        self::assertSame(200.1, $flat[16]);
        self::assertSame(300.0 + 1.0e-7, $flat[32]);
        self::assertSame(0.834, $flat[48]);
        self::assertSame(1.25, $flat[49]);
    }

    public function testFlatAndFromFlatAreInverse(): void
    {
        $vectors = self::vectors();
        self::assertSame($vectors, VectorCodec::fromFlat(VectorCodec::flat($vectors)));
    }

    public function testFlatReadsOnlyTheStoredPartAndCastsIntegers(): void
    {
        $vectors = self::vectors() + ['visibility' => 'normal', 'in_region' => true, 'within' => array_fill(0, 16, 9.0)];
        $vectors['rivals']['day'] = 2;
        $flat = VectorCodec::flat($vectors);
        self::assertCount(50, $flat);
        self::assertSame(2.0, $flat[48]);
    }

    public function testOneBlockIsFourHundredBytesAndRoundTripsBitForBit(): void
    {
        $flat = VectorCodec::flat(self::vectors());
        $hex = VectorCodec::toHex($flat);
        self::assertSame(800, strlen($hex));
        self::assertMatchesRegularExpression('/^[0-9a-f]+$/', $hex);
        $bytes = (string) hex2bin($hex);
        self::assertSame(VectorCodec::BLOCK_BYTES, strlen($bytes));
        self::assertSame($flat, VectorCodec::fromBytes($bytes));
    }

    public function testLittleEndianDoubles(): void
    {
        self::assertSame('000000000000f03f', VectorCodec::toHex([1.0]));
        self::assertSame('000000000000f03f0000000000000040', VectorCodec::toHex([1, 2]));
        self::assertSame([1.0, 2.0], VectorCodec::fromBytes((string) hex2bin('000000000000f03f0000000000000040')));
    }

    public function testThreeBlocksForASpot(): void
    {
        $all = array_merge(
            VectorCodec::flat(self::vectors(0.0)),
            VectorCodec::flat(self::vectors(1000.0)),
            VectorCodec::flat(self::vectors(2000.0))
        );
        $bytes = (string) hex2bin(VectorCodec::toHex($all));
        self::assertSame(1200, strlen($bytes));
        $back = VectorCodec::fromBytes($bytes);
        self::assertCount(150, $back);
        self::assertSame(self::vectors(1000.0), VectorCodec::fromFlat(array_slice($back, 50, 50)));
        self::assertSame(self::vectors(2000.0), VectorCodec::fromFlat(array_slice($back, 100, 50)));
    }

    public function testExtremeValuesSurvive(): void
    {
        $values = [0.0, -0.0, 4.9e-324, 1.7976931348623157e308, 0.1 + 0.2, -1 / 3];
        $back = VectorCodec::fromBytes((string) hex2bin(VectorCodec::toHex($values)));
        foreach ($values as $i => $x) {
            self::assertSame(bin2hex(pack('e', $x)), bin2hex(pack('e', $back[$i])));
        }
    }

    public function testEmptyInput(): void
    {
        self::assertSame('', VectorCodec::toHex([]));
        self::assertSame([], VectorCodec::fromBytes(''));
    }

    public function testNonFiniteValuesAreRefused(): void
    {
        $this->expectException(\DomainException::class);
        VectorCodec::toHex([1.0, NAN]);
    }

    public function testWrongLengthsAreRefused(): void
    {
        foreach ([
            static fn () => VectorCodec::fromFlat(array_fill(0, 49, 0.0)),
            static fn () => VectorCodec::fromBytes('1234567'),
            static function (): void {
                $vectors = self::vectors();
                array_pop($vectors['nearby']);
                VectorCodec::flat($vectors);
            },
        ] as $call) {
            try {
                $call();
                self::fail('a wrong length was accepted');
            } catch (\LengthException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
    }
}
