<?php
declare(strict_types=1);

namespace App\SharedRef\CogsIngest;

/**
 * Adapter return value — one HTTP roundtrip's worth of price rows plus
 * the provenance metadata the service needs to record in
 * `cogs_ingest_batches`.
 *
 * Adapters that fail to reach upstream should still return an IngestBatch
 * with `ok=false`, an httpStatus/errorMessage, and `rows=[]`. The service
 * records the failed attempt so the operator dashboard distinguishes
 * "we tried and the upstream is down" from "we never tried".
 */
final class IngestBatch
{
    /**
     * @param IngestRow[] $rows
     * @param array<int, array{commodity:string, variety:string, unit_hint:?string}> $unmatched
     *        Upstream rows whose commodity/variety didn't map to any
     *        ingredient_key — surfaced via cogs_unmatched_commodities.
     */
    public function __construct(
        public readonly string  $adapter,        // 'usda_ams' | 'usda_nass' | 'greendock'
        public readonly string  $source,         // matches cogs_benchmark.source ENUM
        public readonly ?string $region,
        public readonly string  $asOf,
        public readonly array   $rows = [],
        public readonly ?string $endpoint    = null,
        public readonly ?string $sourceRef   = null,
        public readonly ?int    $httpStatus  = null,
        public readonly ?int    $latencyMs   = null,
        public readonly bool    $ok          = true,
        public readonly ?string $errorMessage = null,
        public readonly ?array  $notes       = null,
        public readonly array   $unmatched   = []
    ) {}
}
