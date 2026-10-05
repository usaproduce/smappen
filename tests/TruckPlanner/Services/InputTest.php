<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Services;

use App\TruckPlanner\Services\Support\Input;
use App\TruckPlanner\Services\Support\TpInvalid;
use PHPUnit\Framework\TestCase;

/**
 * The fixed validation messages V1 to V12 of 04_BACKEND.md 4.2, verbatim, with the field path and the
 * message id in the details.
 */
final class InputTest extends TestCase
{
    /**
     * @return array<int|string, mixed>
     */
    private static function body(string $json): array
    {
        return json_decode($json, true, 64, JSON_THROW_ON_ERROR);
    }

    private static function failure(callable $fn): TpInvalid
    {
        try {
            $fn();
        } catch (TpInvalid $e) {
            return $e;
        }
        self::fail('no validation error was raised');
    }

    private static function assertInvalid(string $message, ?string $field, ?string $rule, callable $fn): void
    {
        $e = self::failure($fn);
        self::assertSame($message, $e->getMessage());
        self::assertSame($field, $e->field());
        self::assertSame($rule, $e->rule());
    }

    // ---------------------------------------------------------------------------------------- V1

    public function testV1Required(): void
    {
        $in = new Input(self::body('{"name": null, "base": {"address": null}}'));
        self::assertInvalid('avg_ticket is required', 'avg_ticket', 'V1', static fn () => $in->num('avg_ticket', 1, 200, true));
        self::assertInvalid('name is required', 'name', 'V1', static fn () => $in->str('name', 120, true));
        self::assertInvalid('base.address is required', 'base.address', 'V1', static fn () => $in->obj('base')->str('address', 255, true));
        self::assertInvalid('date is required', 'date', 'V1', static fn () => $in->date('date', true));
        self::assertInvalid('point is required', 'point', 'V1', static fn () => $in->point('point', true));
        self::assertInvalid('stops is required', 'stops', 'V1', static fn () => $in->items('stops', 0, 8, true));
        self::assertInvalid('terms is required', 'terms', 'V1', static fn () => $in->obj('terms', true));
        self::assertInvalid('kind is required', 'kind', 'V1', static fn () => $in->enum('kind', ['spot', 'event'], true));
        self::assertInvalid('sold_out is required', 'sold_out', 'V1', static fn () => $in->bool('sold_out', true));
        self::assertInvalid('actual is required', 'actual', 'V1', static fn () => $in->int('actual', 0, 5000, true));
    }

    public function testOptionalFieldsAreNullWhenAbsentOrNull(): void
    {
        $in = new Input(self::body('{"notes": null}'));
        self::assertNull($in->str('notes', 4000));
        self::assertNull($in->str('missing', 10));
        self::assertNull($in->num('missing', 0, 1));
        self::assertNull($in->int('missing', 0, 1));
        self::assertNull($in->bool('missing'));
        self::assertNull($in->enum('missing', ['a']));
        self::assertNull($in->date('missing'));
        self::assertNull($in->point('missing'));
        self::assertNull($in->obj('missing'));
        self::assertNull($in->items('missing', 0, 3));
        // has() and isNull() tell "sent as null" from "not sent"
        self::assertTrue($in->has('notes'));
        self::assertTrue($in->isNull('notes'));
        self::assertFalse($in->has('missing'));
        self::assertFalse($in->isNull('missing'));
    }

    // ---------------------------------------------------------------------------------------- V2

    public function testV2Number(): void
    {
        $in = new Input(self::body('{"avg_ticket": 0.5, "fee_pct": "0.1", "tips": true, "price": 20.01, "wage": [18]}'));
        self::assertInvalid('avg_ticket must be a number between 1 and 200', 'avg_ticket', 'V2', static fn () => $in->num('avg_ticket', 1.0, 200.0));
        self::assertInvalid('fee_pct must be a number between 0 and 1', 'fee_pct', 'V2', static fn () => $in->num('fee_pct', 0.0, 1.0));
        self::assertInvalid('tips must be a number between 0 and 0.5', 'tips', 'V2', static fn () => $in->num('tips', 0.0, 0.5));
        self::assertInvalid('price must be a number between 0.5 and 20', 'price', 'V2', static fn () => $in->num('price', 0.5, 20.0));
        self::assertInvalid('wage must be a number between 0 and 200', 'wage', 'V2', static fn () => $in->num('wage', 0.0, 200.0));
        self::assertInvalid('fee must be a number between 0 and 100000', 'fee', 'V2', static fn () => (new Input(['fee' => -1]))->num('fee', 0.0, 100000.0));
    }

    public function testNumbersAreReturnedAsFloats(): void
    {
        $in = new Input(self::body('{"a": 15, "b": 15.5, "c": 1, "d": 200, "e": 1e2, "f": 0.30000000000000004}'));
        self::assertSame(15.0, $in->num('a', 1, 200));
        self::assertSame(15.5, $in->num('b', 1, 200));
        self::assertSame(1.0, $in->num('c', 1, 200));
        self::assertSame(200.0, $in->num('d', 1, 200));
        self::assertSame(100.0, $in->num('e', 1, 200));
        self::assertSame(0.30000000000000004, $in->num('f', 0, 1));
    }

    // ---------------------------------------------------------------------------------------- V3

    public function testV3WholeNumber(): void
    {
        $in = new Input(self::body('{"paid_crew": 2.5, "limit": 61, "open_minute": "660", "vendors": true, "n": -1}'));
        self::assertInvalid('paid_crew must be a whole number between 0 and 12', 'paid_crew', 'V3', static fn () => $in->int('paid_crew', 0, 12));
        self::assertInvalid('limit must be a whole number between 5 and 60', 'limit', 'V3', static fn () => $in->int('limit', 5, 60));
        self::assertInvalid('open_minute must be a whole number between 0 and 2880', 'open_minute', 'V3', static fn () => $in->int('open_minute', 0, 2880));
        self::assertInvalid('vendors must be a whole number between 1 and 500', 'vendors', 'V3', static fn () => $in->int('vendors', 1, 500));
        self::assertInvalid('n must be a whole number between 0 and 5000', 'n', 'V3', static fn () => $in->int('n', 0, 5000));
    }

    public function testWholeNumbersAcceptIntegerValuedJsonNumbers(): void
    {
        $in = new Input(self::body('{"a": 45, "b": 45.0, "c": 0, "d": 2880, "e": 4.5e1}'));
        self::assertSame(45, $in->int('a', 0, 2880));
        self::assertSame(45, $in->int('b', 0, 2880));
        self::assertSame(0, $in->int('c', 0, 2880));
        self::assertSame(2880, $in->int('d', 0, 2880));
        self::assertSame(45, $in->int('e', 0, 2880));
    }

    // ---------------------------------------------------------------------------------------- V4

    public function testV4OneOf(): void
    {
        $in = new Input(self::body('{"fuel_type": "petrol", "visibility": 1, "status": "Draft"}'));
        self::assertInvalid('fuel_type must be one of: gasoline, diesel', 'fuel_type', 'V4', static fn () => $in->enum('fuel_type', ['gasoline', 'diesel']));
        self::assertInvalid('visibility must be one of: hidden, normal, prominent', 'visibility', 'V4', static fn () => $in->enum('visibility', ['hidden', 'normal', 'prominent']));
        self::assertInvalid('status must be one of: draft, planned, done, cancelled', 'status', 'V4', static fn () => $in->enum('status', ['draft', 'planned', 'done', 'cancelled']));
        self::assertSame('diesel', (new Input(['fuel_type' => 'diesel']))->enum('fuel_type', ['gasoline', 'diesel']));
    }

    // ---------------------------------------------------------------------------------------- V5

    public function testV5Text(): void
    {
        $in = new Input(['name' => str_repeat('x', 121), 'address' => 42, 'notes' => ['a'], 'flag' => true]);
        self::assertInvalid('name must be text of at most 120 characters', 'name', 'V5', static fn () => $in->str('name', 120));
        self::assertInvalid('address must be text of at most 255 characters', 'address', 'V5', static fn () => $in->str('address', 255));
        self::assertInvalid('notes must be text of at most 4000 characters', 'notes', 'V5', static fn () => $in->str('notes', 4000));
        self::assertInvalid('flag must be text of at most 10 characters', 'flag', 'V5', static fn () => $in->str('flag', 10));
    }

    public function testTextIsTrimmedAndCountedInCharacters(): void
    {
        $in = new Input(['name' => "  Smoke & Ember \n", 'wide' => str_repeat('é', 120), 'pad' => '  ' . str_repeat('x', 120) . '  ', 'empty' => '   ']);
        self::assertSame('Smoke & Ember', $in->str('name', 120));
        self::assertSame(str_repeat('é', 120), $in->str('wide', 120));
        self::assertSame(str_repeat('x', 120), $in->str('pad', 120));
        self::assertSame('', $in->str('empty', 5));
    }

    // ---------------------------------------------------------------------------------------- V6

    public function testV6Boolean(): void
    {
        $in = new Input(self::body('{"tips_include": 1, "sold_out": "true", "force": 0}'));
        self::assertInvalid('tips_include must be true or false', 'tips_include', 'V6', static fn () => $in->bool('tips_include'));
        self::assertInvalid('sold_out must be true or false', 'sold_out', 'V6', static fn () => $in->bool('sold_out'));
        self::assertInvalid('force must be true or false', 'force', 'V6', static fn () => $in->bool('force'));
        self::assertTrue((new Input(['a' => true]))->bool('a'));
        self::assertFalse((new Input(['a' => false]))->bool('a'));
    }

    // ---------------------------------------------------------------------------------------- V7

    public function testV7Date(): void
    {
        foreach (['2026-02-30', '2026-13-01', '26-10-08', '2026-10-8', '2026/10/08', '1969-12-31', '2200-01-01', ' 2026-10-08', '', 'today'] as $text) {
            $in = new Input(['date' => $text]);
            self::assertInvalid('date must be a date in the form YYYY-MM-DD', 'date', 'V7', static fn () => $in->date('date'));
        }
        $in = new Input(self::body('{"date": 20261008, "from": ["2026-10-08"]}'));
        self::assertInvalid('date must be a date in the form YYYY-MM-DD', 'date', 'V7', static fn () => $in->date('date'));
        self::assertInvalid('from must be a date in the form YYYY-MM-DD', 'from', 'V7', static fn () => $in->date('from'));
        self::assertSame('2026-10-08', (new Input(['date' => '2026-10-08']))->date('date'));
        self::assertSame('2028-02-29', (new Input(['date' => '2028-02-29']))->date('date'));
    }

    // ---------------------------------------------------------------------------------------- V8

    public function testV8List(): void
    {
        $in = new Input(self::body('{"stops": {"a": 1}, "days": [true, false], "visibilities": [], "points": "x", "pairs": [1,2,3,4]}'));
        self::assertInvalid('stops must be a list of 0 to 8 items', 'stops', 'V8', static fn () => $in->items('stops', 0, 8));
        self::assertInvalid('days must be a list of 7 to 7 items', 'days', 'V8', static fn () => $in->items('days', 7, 7));
        self::assertInvalid('visibilities must be a list of 1 to 3 items', 'visibilities', 'V8', static fn () => $in->items('visibilities', 1, 3));
        self::assertInvalid('points must be a list of 2 to 60 items', 'points', 'V8', static fn () => $in->items('points', 2, 60));
        self::assertInvalid('pairs must be a list of 1 to 3 items', 'pairs', 'V8', static fn () => $in->items('pairs', 1, 3));
        self::assertSame([true, false], $in->items('days', 0, 7));
        self::assertSame([], $in->items('visibilities', 0, 3));
    }

    // ---------------------------------------------------------------------------------------- V9

    public function testV9Object(): void
    {
        $in = new Input(self::body('{"base": [1, 2], "terms": "x", "event": 3, "overrides": {}}'));
        self::assertInvalid('base must be an object', 'base', 'V9', static fn () => $in->obj('base'));
        self::assertInvalid('terms must be an object', 'terms', 'V9', static fn () => $in->obj('terms'));
        self::assertInvalid('event must be an object', 'event', 'V9', static fn () => $in->obj('event'));
        self::assertInstanceOf(Input::class, $in->obj('overrides'));
        self::assertSame([], $in->obj('overrides')->all());
    }

    // ---------------------------------------------------------------------------------------- V10

    public function testV10Point(): void
    {
        $message = 'point must have lat between -90 and 90 and lng between -180 and 180';
        foreach ([
            '{"point": {"lat": 91, "lng": 0}}',
            '{"point": {"lat": 0, "lng": -180.01}}',
            '{"point": {"lat": "38.96", "lng": -77.36}}',
            '{"point": {"lat": 38.96}}',
            '{"point": {"lng": -77.36}}',
            '{"point": [38.96, -77.36]}',
            '{"point": "38.96,-77.36"}',
            '{"point": {"lat": null, "lng": -77.36}}',
            '{"point": {"lat": true, "lng": -77.36}}',
        ] as $json) {
            $in = new Input(self::body($json));
            self::assertInvalid($message, 'point', 'V10', static fn () => $in->point('point', true));
        }
        self::assertSame(
            ['lat' => 38.96, 'lng' => -77.36],
            (new Input(self::body('{"point": {"lat": 38.96, "lng": -77.36, "id": "s1"}}')))->point('point')
        );
        self::assertSame(['lat' => -90.0, 'lng' => 180.0], (new Input(self::body('{"p": {"lat": -90, "lng": 180}}')))->point('p'));
    }

    // ---------------------------------------------------------------------------------------- V11, V12

    public function testV11NotFound(): void
    {
        $in = new Input(self::body('{"spot_id": "nope", "stops": [{"spot_id": "a"}, {"spot_id": "b"}]}'));
        $e = $in->notFound('spot_id');
        self::assertSame('spot_id was not found', $e->getMessage());
        self::assertSame(['field' => 'spot_id', 'code' => 'V11'], $e->details());
        $e = $in->each('stops')->obj(1)->notFound('spot_id');
        self::assertSame('stops[1].spot_id was not found', $e->getMessage());
        self::assertSame('stops[1].spot_id', $e->field());
    }

    public function testV12NothingToUpdate(): void
    {
        $e = Input::nothingToUpdate();
        self::assertSame('Nothing to update', $e->getMessage());
        self::assertSame(['code' => 'V12'], $e->details());
        $in = new Input(self::body('{"unknown": 1}'));
        self::assertInvalid('Nothing to update', null, 'V12', static fn () => $in->requireAny(['name', 'notes']));
        (new Input(self::body('{"notes": null}')))->requireAny(['name', 'notes']);
        $this->addToAssertionCount(1);
    }

    // ---------------------------------------------------------------------------------------- paths

    public function testNestedPathsAreDotted(): void
    {
        $in = new Input(self::body('{"terms": {"fee_pct": 2, "host": {"size": 0, "segment": "x"}, "allowed": {"days": [true]}}}'));
        $terms = $in->obj('terms');
        self::assertInvalid('terms.fee_pct must be a number between 0 and 1', 'terms.fee_pct', 'V2', static fn () => $terms->num('fee_pct', 0, 1));
        self::assertInvalid('terms.host.size must be a number between 1 and 200000', 'terms.host.size', 'V2', static fn () => $terms->obj('host')->num('size', 1, 200000));
        self::assertInvalid('terms.allowed.days must be a list of 7 to 7 items', 'terms.allowed.days', 'V8', static fn () => $terms->obj('allowed')->items('days', 7, 7));
        self::assertSame('terms.host.segment', $terms->obj('host')->path('segment'));
    }

    public function testListItemsAreNamedByIndex(): void
    {
        $in = new Input(self::body('{"stops": [{"open_minute": 660}, {"open_minute": 2881}, 7], "licence_counties": ["51107", 51059], "points": [{"id": "a"}]}'));
        $stops = $in->each('stops');
        self::assertSame(660, $stops->obj(0, true)->int('open_minute', 0, 2880, true));
        self::assertInvalid('stops[1].open_minute must be a whole number between 0 and 2880', 'stops[1].open_minute', 'V3', static fn () => $stops->obj(1)->int('open_minute', 0, 2880));
        self::assertInvalid('stops[1].close_minute is required', 'stops[1].close_minute', 'V1', static fn () => $stops->obj(1)->int('close_minute', 0, 2880, true));
        self::assertInvalid('stops[2] must be an object', 'stops[2]', 'V9', static fn () => $stops->obj(2));
        self::assertInvalid('stops[3] is required', 'stops[3]', 'V1', static fn () => $stops->obj(3, true));

        $counties = $in->each('licence_counties');
        self::assertSame('51107', $counties->str(0, 5, true));
        self::assertInvalid('licence_counties[1] must be text of at most 5 characters', 'licence_counties[1]', 'V5', static fn () => $counties->str(1, 5, true));
        self::assertSame('points[0].id', $in->each('points')->obj(0)->path('id'));
    }

    public function testCustomMessagesKeepTheHouseForm(): void
    {
        $in = new Input(self::body('{"stops": [{}, {"close_minute": 600}]}'));
        $e = $in->each('stops')->obj(1)->error('close_minute', 'must be after open_minute');
        self::assertSame('stops[1].close_minute must be after open_minute', $e->getMessage());
        self::assertSame(['field' => 'stops[1].close_minute'], $e->details());
    }

    public function testAPrefixNamesTheWholeBody(): void
    {
        $in = new Input(['size' => 0], 'terms.host.');
        self::assertInvalid('terms.host.size must be a number between 1 and 200000', 'terms.host.size', 'V2', static fn () => $in->num('size', 1, 200000));
    }

    public function testTheFirstFailureWins(): void
    {
        $in = new Input(self::body('{"name": 5, "avg_ticket": "x"}'));
        $e = self::failure(static function () use ($in): void {
            $in->str('name', 120, true);
            $in->num('avg_ticket', 1, 200, true);
        });
        self::assertSame('name', $e->field());
    }

    // ---------------------------------------------------------------------------------------- query strings

    public function testQueryParametersAreStrings(): void
    {
        $q = Input::query(['from' => '2026-10-08', 'stops' => '1', 'archived' => '0', 'limit' => '25', 'spot_id' => ' b2f0 ', 'neg' => '-3']);
        self::assertSame('2026-10-08', $q->date('from'));
        self::assertTrue($q->bool('stops'));
        self::assertFalse($q->bool('archived'));
        self::assertSame(25, $q->int('limit', 1, 50));
        self::assertSame(-3, $q->int('neg', -10, 10));
        self::assertSame('b2f0', $q->str('spot_id', 64));
        self::assertNull($q->date('to'));
    }

    public function testQueryParametersAreStrict(): void
    {
        $q = Input::query(['stops' => 'yes', 'refresh' => 'true', 'limit' => '2.5', 'n' => '1e3', 'pad' => ' 7', 'from' => '2026-10-8', 'list' => ['a'], 'hex' => '0x10']);
        self::assertInvalid('stops must be true or false', 'stops', 'V6', static fn () => $q->bool('stops'));
        self::assertInvalid('refresh must be true or false', 'refresh', 'V6', static fn () => $q->bool('refresh'));
        self::assertInvalid('limit must be a whole number between 1 and 50', 'limit', 'V3', static fn () => $q->int('limit', 1, 50));
        self::assertInvalid('n must be a whole number between 0 and 5000', 'n', 'V3', static fn () => $q->int('n', 0, 5000));
        self::assertInvalid('pad must be a whole number between 0 and 9', 'pad', 'V3', static fn () => $q->int('pad', 0, 9));
        self::assertInvalid('hex must be a whole number between 0 and 99', 'hex', 'V3', static fn () => $q->int('hex', 0, 99));
        self::assertInvalid('from must be a date in the form YYYY-MM-DD', 'from', 'V7', static fn () => $q->date('from'));
        self::assertInvalid('list must be text of at most 10 characters', 'list', 'V5', static fn () => $q->str('list', 10));
    }

    public function testABodyDoesNotReadNumbersOrFlagsFromText(): void
    {
        $in = new Input(self::body('{"limit": "25", "flag": "1"}'));
        self::assertInvalid('limit must be a whole number between 1 and 50', 'limit', 'V3', static fn () => $in->int('limit', 1, 50));
        self::assertInvalid('flag must be true or false', 'flag', 'V6', static fn () => $in->bool('flag'));
    }

    public function testUnknownKeysAreIgnored(): void
    {
        $in = new Input(self::body('{"name": "x", "surprise": {"deep": [1, 2, 3]}}'));
        self::assertSame('x', $in->str('name', 120, true));
        self::assertTrue($in->has('surprise'));
    }
}
