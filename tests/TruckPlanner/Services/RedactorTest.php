<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Services;

use App\TruckPlanner\Services\Support\Redactor;
use PHPUnit\Framework\TestCase;

final class RedactorTest extends TestCase
{
    public function testUrlKeepsSchemeHostAndPathOnly(): void
    {
        self::assertSame(
            'https://maps.googleapis.com/maps/api/distancematrix/json',
            Redactor::url('https://maps.googleapis.com/maps/api/distancematrix/json?origins=39.003,-77.405&key=AIzaSyD-abcdefghijklmnop')
        );
        self::assertSame(
            'https://api.eia.gov/v2/petroleum/pri/gnd/data/',
            Redactor::url('https://api.eia.gov/v2/petroleum/pri/gnd/data/?api_key=0123456789abcdef&frequency=weekly#top')
        );
        self::assertSame('https://api.weather.gov/points/39.003,-77.405', Redactor::url('https://user:secret@api.weather.gov:8443/points/39.003,-77.405'));
        self::assertSame('https://api.weather.gov', Redactor::url('https://api.weather.gov'));
    }

    public function testUrlThatDoesNotParseShowsNothing(): void
    {
        self::assertSame('[url]', Redactor::url('not a url ?key=AIzaSyD-abcdefghijklmnop'));
        self::assertSame('[url]', Redactor::url(''));
    }

    public function testTextMasksKeyParameters(): void
    {
        self::assertSame('GET /x?key=[redacted]&mode=driving', Redactor::text('GET /x?key=AbC123&mode=driving'));
        self::assertSame('api_key=[redacted] failed', Redactor::text('api_key=0123456789abcdef failed'));
        self::assertSame('token=[redacted]', Redactor::text('token=eyJhbGciOiJIUzI1NiJ9.e30.abc'));
        self::assertSame('KEY=[redacted]&Api_Key=[redacted]', Redactor::text('KEY=one&Api_Key=two'));
        self::assertSame('access_token=[redacted]', Redactor::text('access_token=abc'));
    }

    public function testAnAddressInsideAMessageIsNotLogged(): void
    {
        self::assertSame(
            'Routes API has not been used in project 123. Enable it by visiting [url] then retry.',
            Redactor::text('Routes API has not been used in project 123. Enable it by visiting https://console.developers.google.com/apis/api/routes.googleapis.com/overview?project=123 then retry.')
        );
        self::assertSame('see [url] and [url]', Redactor::text("see http://example.org/a?key=AIzaSyD-abcdefghijklmnop\nand ftp://user:secret@example.org/x"));
        self::assertSame('SQLSTATE[HY000]: 1:2 is a ratio, a/b a path', Redactor::text('SQLSTATE[HY000]: 1:2 is a ratio, a/b a path'), 'what is no address stays');
        // url() is the way to log an address: it still answers scheme, host and path
        self::assertSame('https://api.weather.gov/points/39.003,-77.405', Redactor::url('https://api.weather.gov/points/39.003,-77.405?units=us'));
    }

    public function testTextMasksAGoogleKeyWhereverItStands(): void
    {
        $text = Redactor::text('X-Goog-Api-Key: AIzaSyD-abc_DEF-0123456789 was refused');
        self::assertSame('X-Goog-Api-Key: [redacted] was refused', $text);
        self::assertStringNotContainsString('AIza', Redactor::text('{"error":"API key AIzaSyBxxxxxxxxxxxxxxxxxxxxxxxxxxxxx not valid"}'));
    }

    public function testTextIsOneShortLine(): void
    {
        self::assertSame('a b c', Redactor::text("a\nb\r\n\tc"));
        $long = Redactor::text(str_repeat('é', 500));
        self::assertSame(200, mb_strlen($long, 'UTF-8'));
        self::assertTrue(mb_check_encoding($long, 'UTF-8'));
        self::assertSame('plain message', Redactor::text('plain message'));
        self::assertSame('', Redactor::text(''));
    }

    public function testInvalidBytesDoNotBreakTheLog(): void
    {
        $text = Redactor::text("bad \xC3\x28 bytes key=secret");
        self::assertTrue(mb_check_encoding($text, 'UTF-8'));
        self::assertStringNotContainsString('secret', $text);
    }

    public function testAKeyCutByTheLengthLimitIsAlreadyMasked(): void
    {
        $text = Redactor::text(str_repeat('x', 190) . ' key=AIzaSyD-abcdefghijklmnopqrstuvwxyz');
        self::assertStringNotContainsString('AIza', $text);
        self::assertLessThanOrEqual(200, strlen($text));
    }
}
