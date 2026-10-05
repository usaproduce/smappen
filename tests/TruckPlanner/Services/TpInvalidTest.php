<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Services;

use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Services\Support\TpInvalid;
use PHPUnit\Framework\TestCase;

final class TpInvalidTest extends TestCase
{
    public function testItCarriesTheFieldAndTheMessageId(): void
    {
        $e = new TpInvalid('stops[1].open_minute must be a whole number between 0 and 2880', 'stops[1].open_minute', 'V3');
        self::assertSame('stops[1].open_minute must be a whole number between 0 and 2880', $e->getMessage());
        self::assertSame('stops[1].open_minute', $e->field());
        self::assertSame('V3', $e->rule());
        self::assertSame(['field' => 'stops[1].open_minute', 'code' => 'V3'], $e->details());
    }

    public function testDetailsHoldOnlyWhatIsKnown(): void
    {
        self::assertSame(['field' => 'confirm'], (new TpInvalid('confirm must be exactly: delete my truck data', 'confirm'))->details());
        self::assertSame(['code' => 'V12'], (new TpInvalid('Nothing to update', null, 'V12'))->details());
        self::assertNull((new TpInvalid('Give minutes or toll'))->details());
        self::assertNull((new TpInvalid('x'))->field());
        self::assertNull((new TpInvalid('x'))->rule());
    }

    public function testItIsAnInvalidArgumentThatTheControllerCanTellFromAModelDefect(): void
    {
        $e = new TpInvalid('x');
        self::assertInstanceOf(\InvalidArgumentException::class, $e);
        self::assertSame(0, $e->getCode());
    }

    public function testAModelErrorIsAnInvalidArgumentTooButNeverATpInvalid(): void
    {
        // The base controller catches TpInvalid first (422). What the model raises must fall through to
        // the 500 branch: it means the inputs were not validated before the call.
        try {
            Estimator::parseDate('2026-02-30');
            self::fail('the model accepted an impossible date');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('invalid_date', $e->getMessage());
            self::assertNotInstanceOf(TpInvalid::class, $e);
        }
    }
}
