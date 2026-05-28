<?php
declare(strict_types=1);

namespace App\SharedRef\CogsIngest;

/**
 * Contract every COGS-benchmark upstream implements.
 *
 * CogsBenchmarkService::ingest() iterates over the registered adapters,
 * calls fetchBatch() per (region) it cares about, persists the rows
 * the adapter returns, and writes the corresponding cogs_ingest_batches
 * row from the IngestBatch metadata.
 *
 * Adapters MUST be defensive:
 *   - 3-second connect timeout on outbound HTTP (audit §5.4 discipline)
 *   - never throw on a malformed upstream row; skip + log
 *   - upstream outage → return IngestBatch(ok=false, errorMessage=...) so
 *     the service records the failed attempt and serves last-ingested
 *     data (mirror of DataFreshnessFooter)
 *   - return an empty-rows batch with ok=true when the upstream is fine
 *     but has nothing new to report for this (asOf, region)
 *
 * Adapters MAY be `enabled=false`: in stub mode (no API key) the service
 * skips them silently rather than calling fetchBatch().
 */
interface CogsIngestAdapter
{
    /** Stable short id used in cogs_ingest_batches.adapter and logs. */
    public function key(): string;

    /** ENUM value written to cogs_benchmark.source for rows from this adapter. */
    public function source(): string;

    /**
     * Region keys this adapter can serve. The service intersects this with
     * the per-call list. Empty array = "all regions, ignore the filter".
     *
     * Carafe region taxonomy:
     *   'US'                — national rollup
     *   'US-NE'             — Northeast (Census region 1)
     *   'US-MID-ATLANTIC'   — sub-region used by USDA AMS Baltimore report
     *   'US-MW'             — Midwest (Census region 2)
     *   'US-S'              — South (Census region 3)
     *   'US-SE'             — sub-region (Atlanta/Miami terminal markets)
     *   'US-W'              — West (Census region 4)
     */
    public function regions(): array;

    /** False → service skips this adapter (env not configured, etc.). */
    public function isEnabled(): bool;

    /**
     * One HTTP roundtrip's worth of rows for ($asOfDate, $region).
     * MUST NOT throw. See class docblock for the failure contract.
     */
    public function fetchBatch(string $asOfDate, ?string $region): IngestBatch;
}
