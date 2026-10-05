<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Services;

use App\TruckPlanner\Services\Support\TpConflict;
use App\TruckPlanner\Services\Support\TpNotFound;
use PHPUnit\Framework\TestCase;

final class TpConflictTest extends TestCase
{
    public function testItCarriesTheSentenceOfThe409(): void
    {
        $e = new TpConflict('A plan already exists for this date');
        self::assertSame('A plan already exists for this date', $e->getMessage());
        self::assertInstanceOf(\RuntimeException::class, $e);
    }

    public function testItIsItsOwnKind(): void
    {
        $e = new TpConflict('Region data was built with different model constants');
        self::assertNotInstanceOf(\InvalidArgumentException::class, $e);
        self::assertNotInstanceOf(TpNotFound::class, $e);
    }
}
