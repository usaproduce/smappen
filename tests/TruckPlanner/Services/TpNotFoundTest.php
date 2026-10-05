<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Services;

use App\TruckPlanner\Services\Support\TpInvalid;
use App\TruckPlanner\Services\Support\TpNotFound;
use PHPUnit\Framework\TestCase;

final class TpNotFoundTest extends TestCase
{
    public function testItCarriesTheSentenceOfThe404(): void
    {
        $e = new TpNotFound('Spot not found');
        self::assertSame('Spot not found', $e->getMessage());
        self::assertInstanceOf(\RuntimeException::class, $e);
    }

    public function testItIsNeverMistakenForAValidationFailure(): void
    {
        $e = new TpNotFound('Plan not found');
        self::assertNotInstanceOf(\InvalidArgumentException::class, $e);
        self::assertNotInstanceOf(TpInvalid::class, $e);
    }
}
