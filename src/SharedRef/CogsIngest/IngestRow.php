<?php
declare(strict_types=1);

namespace App\SharedRef\CogsIngest;

/**
 * Value object — one canonical price observation from an upstream source.
 *
 * Normalized to the shape `cogs_benchmark` actually stores. Adapters do
 * all unit/region/commodity translation up front; the service just writes
 * what they hand it.
 */
final class IngestRow
{
    public function __construct(
        public readonly string $ingredientKey,    // 'tomato_roma'
        public readonly string $unit,             // 'lb' | 'oz' | 'each' | 'cup' | 'tbsp'
        public readonly int    $marketPriceCents, // wholesale cents per $unit
        public readonly ?string $region,          // 'US' | 'US-NE' | 'US-MID-ATLANTIC' | ...
        public readonly string $asOf,             // 'YYYY-MM-DD' — observation date, NOT fetch date
        public readonly ?string $sourceRef = null // adapter-specific provenance crumb (slug, report id, commodity name)
    ) {}

    public function toArray(): array
    {
        return [
            'ingredient_key'     => $this->ingredientKey,
            'unit'               => $this->unit,
            'market_price_cents' => $this->marketPriceCents,
            'region'             => $this->region,
            'as_of'              => $this->asOf,
            'source_ref'         => $this->sourceRef,
        ];
    }
}
