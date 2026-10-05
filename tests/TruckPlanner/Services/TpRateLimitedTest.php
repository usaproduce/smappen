<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Services;

use App\TruckPlanner\Services\Support\TpRateLimited;
use App\TruckPlanner\Services\Support\TpUnavailable;
use PHPUnit\Framework\TestCase;

final class TpRateLimitedTest extends TestCase
{
    public function testItCarriesTheSentenceOfThe429(): void
    {
        $e = new TpRateLimited('Too many lookups right now. Try again in a minute');
        self::assertSame('Too many lookups right now. Try again in a minute', $e->getMessage());
        self::assertInstanceOf(\RuntimeException::class, $e);
    }

    public function testItIsItsOwnKind(): void
    {
        $e = new TpRateLimited('x');
        self::assertNotInstanceOf(\InvalidArgumentException::class, $e);
        self::assertNotInstanceOf(TpUnavailable::class, $e);
    }
}
