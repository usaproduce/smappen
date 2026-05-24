<?php
declare(strict_types=1);

namespace App\Services;

/**
 * NF1 — Drive-Time Matrix.
 *
 * Given two coordinate lists (`origins` and `destinations`), returns an
 * N × M matrix of drive times in seconds + distances in meters. Backed
 * by OpenRouteService /matrix endpoint, which already powers our isochrone
 * pipeline so the API key + cost model are familiar.
 *
 * ORS limits a single request to 50 × 50 (Free) or 100 × 100 (Standard).
 * Above that we chunk the destination side; each chunk is one API call.
 * Total cost: ceil(N/chunk) * ceil(M/chunk) ORS units.
 *
 * Cache: identical (origins, destinations, mode) hashes the same key; 7d TTL.
 */
class DriveTimeMatrixService
{
    private const ORS_URL = 'https://api.openrouteservice.org/v2/matrix/';
    private const MAX_PER_REQUEST = 49;

    public function compute(
        array $origins,        // [['lat'=>..,'lng'=>..,'label'=>...], ...]
        array $destinations,
        string $mode = 'driving-car'
    ): array {
        if (empty($origins) || empty($destinations)) {
            throw new \InvalidArgumentException('origins and destinations required');
        }
        if (count($origins) > 200 || count($destinations) > 200) {
            throw new \InvalidArgumentException('Each side capped at 200 locations for v1');
        }

        $cacheKey = 'dtm:' . substr(md5(json_encode([$origins, $destinations, $mode])), 0, 32);
        $cached = CacheService::getJson($cacheKey);
        if ($cached) return $cached;

        $key = Config::get('ORS_API_KEY');
        if (!$key) throw new \RuntimeException('ORS_API_KEY not configured');

        // Build the combined `locations` array — ORS expects a single list
        // with index-based sources/destinations pointers.
        // For chunking we slice destinations into batches.
        $n = count($origins);
        $m = count($destinations);
        $matrix = ['durations' => array_fill(0, $n, array_fill(0, $m, null)),
                   'distances' => array_fill(0, $n, array_fill(0, $m, null))];

        $batchSize = self::MAX_PER_REQUEST;
        // ORS limits the TOTAL locations to ~3500, so we also chunk origins
        // when needed. For our v1 cap (200×200) this means up to ceil(200/49)
        // = 5 batches per axis = 25 calls per matrix. Fine.
        $oBatches = array_chunk(array_keys($origins), $batchSize);
        $dBatches = array_chunk(array_keys($destinations), $batchSize);

        foreach ($oBatches as $oIdx) {
            foreach ($dBatches as $dIdx) {
                $locs = [];
                $srcLocal = []; $dstLocal = [];
                foreach ($oIdx as $i) { $srcLocal[] = count($locs); $locs[] = [(float)$origins[$i]['lng'], (float)$origins[$i]['lat']]; }
                foreach ($dIdx as $j) { $dstLocal[] = count($locs); $locs[] = [(float)$destinations[$j]['lng'], (float)$destinations[$j]['lat']]; }

                $body = [
                    'locations'    => $locs,
                    'sources'      => $srcLocal,
                    'destinations' => $dstLocal,
                    'metrics'      => ['duration', 'distance'],
                    'units'        => 'm',
                ];
                $resp = self::orsRequest($mode, $body, $key);
                $durs = $resp['durations'] ?? [];
                $dists = $resp['distances'] ?? [];
                foreach ($oIdx as $li => $i) {
                    foreach ($dIdx as $lj => $j) {
                        $matrix['durations'][$i][$j] = $durs[$li][$lj] ?? null;
                        $matrix['distances'][$i][$j] = $dists[$li][$lj] ?? null;
                    }
                }
                // Brief throttle so we don't blow through the free tier
                // when the matrix is wide.
                usleep(200_000);
            }
        }

        // Annotate with summary stats per origin: best/worst/avg destination.
        $perOrigin = [];
        foreach ($origins as $i => $o) {
            $row = array_filter($matrix['durations'][$i], fn($v) => $v !== null);
            if (empty($row)) {
                $perOrigin[] = ['origin' => $o, 'best' => null, 'worst' => null, 'avg' => null];
                continue;
            }
            $perOrigin[] = [
                'origin' => $o,
                'best'   => min($row),
                'worst'  => max($row),
                'avg'    => array_sum($row) / count($row),
            ];
        }

        $result = [
            'origins'      => $origins,
            'destinations' => $destinations,
            'mode'         => $mode,
            'durations'    => $matrix['durations'],
            'distances'    => $matrix['distances'],
            'per_origin'   => $perOrigin,
            'computed_at'  => date('c'),
        ];
        CacheService::set($cacheKey, $result, 7 * 86400);
        return $result;
    }

    private static function orsRequest(string $mode, array $body, string $key): array
    {
        $url = self::ORS_URL . $mode;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => [
                'Authorization: ' . $key,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 90,
        ]);
        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 400) {
            throw new \RuntimeException('ORS matrix HTTP ' . $code . ': ' . substr((string)$raw, 0, 200));
        }
        $j = json_decode((string)$raw, true);
        if (!is_array($j)) throw new \RuntimeException('ORS matrix returned non-JSON');
        return $j;
    }
}
