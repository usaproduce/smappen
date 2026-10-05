<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Support;

use App\TruckPlanner\Services\Http\OutboundHttp;

/**
 * An OutboundHttp that sends nothing: it records each request and answers with the tuples the test queued,
 * in order. Client classes take `?OutboundHttp $http = null`, so a test passes one of these.
 *
 *     $http = new FakeHttp();
 *     $http->json(200, [['originIndex' => 0, 'condition' => 'ROUTE_EXISTS', 'duration' => '713s']]);
 *     $http->fail('timeout');
 *     ...
 *     self::assertSame('POST', $http->requests[0]['method']);
 *
 * An address the real class would refuse is refused here too (`host_not_listed`, nothing is taken from
 * the queue), so a client that builds a wrong host fails its test. A request with nothing queued is a
 * mistake of the test and throws.
 */
final class FakeHttp extends OutboundHttp
{
    /**
     * @var list<array{method: string, url: string, headers: list<string>, body: ?string,
     *                 connect_timeout_s: int, timeout_s: int}>
     */
    public array $requests = [];

    /** @var list<array{0: int, 1: string, 2: int, 3: ?string, 4: array<string, string>}> */
    private array $queued = [];

    /**
     * Queue one answer as request() returns it.
     *
     * @param array<string, string> $headers lower-cased response header names
     */
    public function queue(int $status, string $body = '', array $headers = [], int $latencyMs = 12): self
    {
        $error = ($status >= 200 && $status < 300) ? null : 'http_' . $status;
        $this->queued[] = [$status, $body, $latencyMs, $error, $headers];
        return $this;
    }

    /**
     * Queue a JSON answer.
     *
     * @param array<int|string, mixed> $payload
     * @param array<string, string> $headers
     */
    public function json(int $status, array $payload, array $headers = []): self
    {
        return $this->queue($status, (string) json_encode($payload, JSON_UNESCAPED_SLASHES), $headers);
    }

    /** Queue a transport failure: "timeout", "connect" or "curl_<n>". No HTTP answer arrives. */
    public function fail(string $errorCode, int $latencyMs = 8000): self
    {
        $this->queued[] = [0, '', $latencyMs, $errorCode, []];
        return $this;
    }

    public function request(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        int $connectTimeoutS,
        int $timeoutS
    ): array {
        $this->requests[] = [
            'method' => $method,
            'url' => $url,
            'headers' => array_values($headers),
            'body' => $body,
            'connect_timeout_s' => $connectTimeoutS,
            'timeout_s' => $timeoutS,
        ];
        if (!self::isAllowedUrl($url)) {
            return [0, '', 0, self::ERROR_HOST, []];
        }
        if ($this->queued === []) {
            throw new \LogicException('FakeHttp: a request was made and no answer is queued');
        }
        return array_shift($this->queued);
    }

    /** Answers that were queued and never asked for. */
    public function pending(): int
    {
        return count($this->queued);
    }
}
