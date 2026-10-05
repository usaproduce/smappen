<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Services;

use App\Tests\TruckPlanner\Support\FakeHttp;
use App\TruckPlanner\Services\Http\OutboundHttp;
use PHPUnit\Framework\TestCase;

/**
 * No test here opens a connection: the allow-list is checked before anything is sent.
 */
final class OutboundHttpTest extends TestCase
{
    public function testTheAllowListIsTheFiveHostsOfTheDecisions(): void
    {
        self::assertSame(
            ['routes.googleapis.com', 'maps.googleapis.com', 'places.googleapis.com', 'api.weather.gov', 'api.eia.gov'],
            OutboundHttp::ALLOWED_HOSTS
        );
    }

    public function testListedHostsOverHttpsAreAllowed(): void
    {
        foreach ([
            'https://routes.googleapis.com/distanceMatrix/v2:computeRouteMatrix',
            'https://maps.googleapis.com/maps/api/distancematrix/json?origins=39.003,-77.405&key=k',
            'https://places.googleapis.com/v1/places:searchText',
            'https://api.weather.gov/points/39.003,-77.405',
            'https://api.eia.gov/v2/petroleum/pri/gnd/data/?api_key=k&frequency=weekly',
            'https://api.weather.gov:443/gridpoints/LWX/88,74/forecast/hourly',
            'HTTPS://API.WEATHER.GOV/points/39,-77',
        ] as $url) {
            self::assertTrue(OutboundHttp::isAllowedUrl($url), $url);
        }
    }

    public function testEverythingElseIsRefused(): void
    {
        foreach ([
            'http://api.weather.gov/points/39,-77',                         // not https
            'https://example.com/',
            'https://www.google.com/maps/search/?api=1',                    // a link-only host is not a call target
            'https://overpass-api.de/api/interpreter',
            'https://x.routes.googleapis.com/',                             // a subdomain is another host
            'https://routes.googleapis.com.example.com/',
            'https://routes.googleapis.com@example.com/',                   // credentials trick
            'https://user:pw@api.weather.gov/points/39,-77',
            'https://api.weather.gov:8443/points/39,-77',                   // another port
            'https://example.com/?next=https://api.weather.gov/',
            'ftp://api.eia.gov/file',
            '//api.weather.gov/points/39,-77',
            'api.weather.gov/points/39,-77',
            'https://127.0.0.1/',
            'https://localhost/',
            'file:///etc/passwd',
            '',
            'https://',
        ] as $url) {
            self::assertFalse(OutboundHttp::isAllowedUrl($url), $url);
        }
    }

    public function testARefusedAddressIsAnsweredWithoutAnyRequest(): void
    {
        $http = new OutboundHttp();
        foreach (['https://example.com/x', 'http://api.weather.gov/points/39,-77', 'https://localhost/'] as $url) {
            self::assertSame([0, '', 0, 'host_not_listed', []], $http->request('GET', $url, [], null, 3, 6));
        }
    }

    public function testTheErrorCodesAreShortWords(): void
    {
        self::assertSame('host_not_listed', OutboundHttp::ERROR_HOST);
        self::assertSame('timeout', OutboundHttp::ERROR_TIMEOUT);
        self::assertSame('connect', OutboundHttp::ERROR_CONNECT);
    }

    public function testTheTestDoubleAnswersInOrderAndRecordsRequests(): void
    {
        $http = new FakeHttp();
        $http->json(200, [['originIndex' => 0, 'duration' => '713s']], ['content-type' => 'application/json'])
            ->queue(403, '{"error":{"status":"PERMISSION_DENIED"}}')
            ->fail('timeout');

        $first = $http->request('POST', 'https://routes.googleapis.com/distanceMatrix/v2:computeRouteMatrix', ['X-Goog-Api-Key: k'], '{"origins":[]}', 3, 8);
        self::assertSame(200, $first[0]);
        self::assertSame('[{"originIndex":0,"duration":"713s"}]', $first[1]);
        self::assertNull($first[3]);
        self::assertSame(['content-type' => 'application/json'], $first[4]);

        $second = $http->request('GET', 'https://maps.googleapis.com/maps/api/distancematrix/json?key=k', [], null, 3, 8);
        self::assertSame(403, $second[0]);
        self::assertSame('http_403', $second[3]);

        $third = $http->request('GET', 'https://api.weather.gov/points/39,-77', [], null, 3, 6);
        self::assertSame([0, '', 8000, 'timeout', []], $third);

        self::assertCount(3, $http->requests);
        self::assertSame('POST', $http->requests[0]['method']);
        self::assertSame(['X-Goog-Api-Key: k'], $http->requests[0]['headers']);
        self::assertSame('{"origins":[]}', $http->requests[0]['body']);
        self::assertSame(3, $http->requests[0]['connect_timeout_s']);
        self::assertSame(8, $http->requests[0]['timeout_s']);
        self::assertSame(0, $http->pending());
    }

    public function testTheTestDoubleRefusesWhatTheRealClassRefuses(): void
    {
        $http = (new FakeHttp())->queue(200, 'never served');
        self::assertSame([0, '', 0, 'host_not_listed', []], $http->request('GET', 'https://example.com/', [], null, 1, 1));
        self::assertSame(1, $http->pending());
        self::assertCount(1, $http->requests);
    }

    public function testTheTestDoubleFailsLoudlyWithoutAQueuedAnswer(): void
    {
        $this->expectException(\LogicException::class);
        (new FakeHttp())->request('GET', 'https://api.weather.gov/points/39,-77', [], null, 1, 1);
    }
}
