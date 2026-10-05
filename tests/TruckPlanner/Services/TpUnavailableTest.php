<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Services;

use App\TruckPlanner\Services\Support\TpConflict;
use App\TruckPlanner\Services\Support\TpUnavailable;
use PHPUnit\Framework\TestCase;

final class TpUnavailableTest extends TestCase
{
    public function testItCarriesTheSentenceOfThe503(): void
    {
        $e = new TpUnavailable('Contact lookup is not available on this server');
        self::assertSame('Contact lookup is not available on this server', $e->getMessage());
        self::assertInstanceOf(\RuntimeException::class, $e);
    }

    public function testItIsItsOwnKind(): void
    {
        $e = new TpUnavailable('x');
        self::assertNotInstanceOf(\InvalidArgumentException::class, $e);
        self::assertNotInstanceOf(TpConflict::class, $e);
    }
}
