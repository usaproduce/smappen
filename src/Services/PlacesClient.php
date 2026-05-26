<?php
namespace App\Services;

use App\Core\Config;
use App\Core\Database;

/**
 * PlacesClient — the single, mandatory choke point for every Google
 * Places (New) HTTP call in the Carafe seeding pipeline. Carafe Vendor
 * Network Spec v3 §7.
 *
 * What it does, and why each piece exists:
 *
 *   1. Field-mask discipline — every public method is parameterized by
 *      a preset name from config/google_places_pricing.php['masks'].
 *      Callers can't hand-roll masks that quietly trigger Contact +
 *      Atmosphere SKUs (spec §1 + §10 guardrail 4).
 *
 *   2. Cost ledger — every call writes a row to api_cost_events keyed
 *      on campaign_id, sku, field_mask_hash. Pre-call we derive the SKU
 *      set from the mask, look up the tier rate from the running
 *      monthly volume, and project total cost; post-call we record it
 *      with the actual HTTP status + latency (spec §5.3 + §5.4).
 *
 *   3. Budget cap halt — when a campaign context is set, the projected
 *      cost of the next call is summed with spent-so-far for that
 *      campaign before the HTTP request goes out. Over cap → throw,
 *      no overrun (spec §10 guardrail 5).
 *
 *   4. Grant flag gating — config/google_places_grant.php's
 *      places_storage_allowed controls behavior. Off → sweep masks
 *      degrade to id-only, Details/Photo calls refuse (spec §10
 *      guardrail 1). Flipping the flag back on restores full behavior
 *      with zero code changes.
 *
 * NOT done here (deferred to later phases per §9):
 *   - Request coalescing / TTL caching (VendorCacheService — phase 3+)
 *   - Idempotent vendor_google_details upserts (phase 3+)
 *   - Tile fingerprinting / delta seeding (SeedDeltaService — phase 8)
 *   - Pagination orchestration (caller paginates; PlacesClient is one-call-one-bill)
 *
 * Existing call sites in GoogleMapsService::searchPlacesNearby/Text/
 * getPlaceDetails are NOT migrated here — they remain on api_usage_log.
 * Phase 2+ migrates them so api_cost_events becomes the sole ledger.
 *
 * Testability: HTTP transport, cost-ledger writes, and monthly-volume
 * reads are protected methods. Tests subclass PlacesClient and override
 * those three to assert behavior without DB or network.
 */
class PlacesClient
{
    public const ENDPOINT_NEARBY  = 'places_nearby_pro';
    public const ENDPOINT_TEXT    = 'places_text_pro';
    public const ENDPOINT_DETAILS = 'place_details_pro';
    public const ENDPOINT_PHOTO   = 'place_photo';

    private const URL_SEARCH_NEARBY = 'https://places.googleapis.com/v1/places:searchNearby';
    private const URL_SEARCH_TEXT   = 'https://places.googleapis.com/v1/places:searchText';
    private const URL_DETAILS_FMT   = 'https://places.googleapis.com/v1/places/%s';
    private const URL_PHOTO_FMT     = 'https://places.googleapis.com/v1/%s/media';

    private string $apiKey;
    private array $pricing;
    private array $grant;

    private ?string $campaignId = null;
    private ?string $tileId     = null;
    private ?float $budgetCap   = null;
    /** Cached spent-so-far for the active campaign; refreshed lazily. */
    private ?float $campaignSpent = null;

    public function __construct(
        ?string $apiKey  = null,
        ?array  $pricing = null,
        ?array  $grant   = null
    ) {
        $this->apiKey  = $apiKey ?? (string) Config::get('GOOGLE_API_KEY', '');
        $this->pricing = $pricing ?? require dirname(__DIR__, 2) . '/config/google_places_pricing.php';
        $this->grant   = $grant   ?? require dirname(__DIR__, 2) . '/config/google_places_grant.php';
    }

    // ─────────────────────────────────────────────────────────────────
    // Public API — campaign context (budget-cap + ledger attribution)
    // ─────────────────────────────────────────────────────────────────

    public function setCampaignContext(string $campaignId, ?string $tileId = null, ?float $budgetCapUsd = null): self
    {
        $this->campaignId    = $campaignId;
        $this->tileId        = $tileId;
        $this->budgetCap     = $budgetCapUsd;
        $this->campaignSpent = null; // force lazy refresh
        return $this;
    }

    public function clearCampaignContext(): self
    {
        $this->campaignId    = null;
        $this->tileId        = null;
        $this->budgetCap     = null;
        $this->campaignSpent = null;
        return $this;
    }

    public function isStorageAllowed(): bool
    {
        return (bool) ($this->grant['places_storage_allowed'] ?? false);
    }

    // ─────────────────────────────────────────────────────────────────
    // Public API — Places calls
    // ─────────────────────────────────────────────────────────────────

    /**
     * Nearby Search — Pro SKU only (sweep mask). $params accepts the
     * Places (New) searchNearby payload shape: locationRestriction
     * (circle or rectangle), includedTypes, maxResultCount, etc.
     */
    public function searchNearby(array $params, string $maskPreset = 'sweep'): array
    {
        $mask = $this->resolveSweepMask($maskPreset);
        $skus = [self::ENDPOINT_NEARBY];
        $this->enforceBudgetCap($skus, 1);
        $payload = json_encode($params + ['languageCode' => 'en']);
        $headers = $this->buildHeaders($mask);
        [$status, $body, $latencyMs, $err] = $this->httpRequest('POST', self::URL_SEARCH_NEARBY, $headers, $payload);
        $this->recordCall($skus, $mask, $status, $latencyMs, $err);
        if ($err !== null) {
            throw new \RuntimeException("Places searchNearby failed (HTTP $status): $err");
        }
        return json_decode($body, true) ?? [];
    }

    /**
     * Text Search — Pro SKU only (sweep mask). Caller paginates by
     * passing nextPageToken in subsequent calls; PlacesClient is one
     * call = one billed unit.
     */
    public function searchText(array $params, string $maskPreset = 'sweep_text'): array
    {
        $mask = $this->resolveSweepMask($maskPreset);
        $skus = [self::ENDPOINT_TEXT];
        $this->enforceBudgetCap($skus, 1);
        $payload = json_encode($params + ['languageCode' => 'en']);
        $headers = $this->buildHeaders($mask);
        [$status, $body, $latencyMs, $err] = $this->httpRequest('POST', self::URL_SEARCH_TEXT, $headers, $payload);
        $this->recordCall($skus, $mask, $status, $latencyMs, $err);
        if ($err !== null) {
            throw new \RuntimeException("Places searchText failed (HTTP $status): $err");
        }
        return json_decode($body, true) ?? [];
    }

    /**
     * Place Details — Pro SKU plus Contact and/or Atmosphere add-ons
     * depending on the mask preset's fields. Mask presets: enrich_full,
     * tier_cold, tier_warm, tier_hot (config['masks']).
     *
     * Refuses to fire when places_storage_allowed=false — no point
     * paying for detail you can't legally retain.
     */
    public function placeDetails(string $placeId, string $maskPreset = 'enrich_full'): array
    {
        if (!$this->isStorageAllowed()) {
            throw new \RuntimeException('placeDetails refused: places_storage_allowed=false. Set the grant in config/google_places_grant.php or operate in id-only sweep mode.');
        }
        if ($placeId === '') {
            throw new \InvalidArgumentException('placeDetails requires a non-empty placeId');
        }
        $maskTokens = $this->maskTokens($maskPreset);
        $mask       = implode(',', $maskTokens);
        $skus       = $this->skusForDetailsMask($maskTokens);
        $this->enforceBudgetCap($skus, 1);
        $url     = sprintf(self::URL_DETAILS_FMT, rawurlencode($placeId));
        $headers = $this->buildHeaders($mask);
        [$status, $body, $latencyMs, $err] = $this->httpRequest('GET', $url, $headers, null);
        $this->recordCall($skus, $mask, $status, $latencyMs, $err);
        if ($err !== null) {
            throw new \RuntimeException("Places placeDetails($placeId) failed (HTTP $status): $err");
        }
        return json_decode($body, true) ?? [];
    }

    /**
     * Place Photo — separate billable SKU. $photoName is the resource
     * name returned in vendor_google_photos.photo_name (e.g.
     * "places/ABC/photos/XYZ"). Returns the raw binary string.
     */
    public function placePhoto(string $photoName, int $maxWidthPx = 800): string
    {
        if (!$this->isStorageAllowed()) {
            throw new \RuntimeException('placePhoto refused: places_storage_allowed=false.');
        }
        $skus = [self::ENDPOINT_PHOTO];
        $this->enforceBudgetCap($skus, 1);
        $url = sprintf(self::URL_PHOTO_FMT, $photoName) . '?maxWidthPx=' . $maxWidthPx . '&skipHttpRedirect=false&key=' . urlencode($this->apiKey);
        [$status, $body, $latencyMs, $err] = $this->httpRequest('GET', $url, [], null);
        $this->recordCall($skus, null, $status, $latencyMs, $err);
        if ($err !== null) {
            throw new \RuntimeException("Places placePhoto failed (HTTP $status): $err");
        }
        return $body;
    }

    // ─────────────────────────────────────────────────────────────────
    // Public-but-pure helpers (also useful to SeedEstimatorService later)
    // ─────────────────────────────────────────────────────────────────

    /** Comma-joined field-mask string for a preset name. */
    public function maskFor(string $preset): string
    {
        return implode(',', $this->maskTokens($preset));
    }

    /**
     * Derive the SKU set billable for a Place Details call with the
     * given mask. The Pro SKU is always present; Contact and Atmosphere
     * stack on top when any of their trigger fields appear in the mask
     * (see config['field_triggers']).
     */
    public function skusForDetailsMask(array $maskTokens): array
    {
        $skus       = [self::ENDPOINT_DETAILS];
        $triggers   = $this->pricing['field_triggers'] ?? [];
        $tokenSet   = array_flip(array_map(fn($t) => $this->stripPrefix($t), $maskTokens));
        foreach (['place_details_contact', 'place_details_atmosphere'] as $addon) {
            foreach (($triggers[$addon] ?? []) as $field) {
                if (isset($tokenSet[$field])) {
                    $skus[] = $addon;
                    break;
                }
            }
        }
        return $skus;
    }

    /**
     * Project the cost of a call billing $skus for $units, in USD,
     * at the *current* monthly volume tier. Pure on cost given the
     * volume reader — used by enforceBudgetCap pre-flight.
     */
    public function projectCost(array $skus, int $units = 1): float
    {
        $total = 0.0;
        foreach ($skus as $sku) {
            $total += $this->rateForSku($sku, $units) * $units / 1000.0;
        }
        return round($total, 6);
    }

    // ─────────────────────────────────────────────────────────────────
    // Protected — override in tests to mock transport / persistence
    // ─────────────────────────────────────────────────────────────────

    /** @return array{0:int,1:string,2:int,3:?string} [http_status, body, latency_ms, error_message_or_null] */
    protected function httpRequest(string $method, string $url, array $headers, ?string $body): array
    {
        $start = microtime(true);
        $ch    = curl_init($url);
        $opts  = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 30,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $opts);
        $resp      = curl_exec($ch);
        $status    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr   = curl_error($ch);
        curl_close($ch);
        $latencyMs = (int) round((microtime(true) - $start) * 1000);
        if ($resp === false) {
            return [$status ?: 0, '', $latencyMs, $curlErr ?: 'curl failed'];
        }
        if ($status >= 400) {
            return [$status, (string) $resp, $latencyMs, "HTTP $status"];
        }
        return [$status, (string) $resp, $latencyMs, null];
    }

    protected function persistEvent(array $event): void
    {
        try {
            Database::getInstance()->insert('api_cost_events', $event);
        } catch (\Throwable $e) {
            error_log('PlacesClient persistEvent failed: ' . $e->getMessage());
        }
    }

    /**
     * Running monthly billable-unit count for a SKU family (current
     * calendar month). Override in tests to fix the tier.
     *
     * $skuFamily is one of 'search', 'details'. Add-on SKUs share the
     * 'details' family for tiering since they're billed in lockstep.
     */
    protected function monthlyVolume(string $skuFamily): int
    {
        $skus = $skuFamily === 'search'
            ? ['places_nearby_pro', 'places_text_pro']
            : ['place_details_pro', 'place_details_contact', 'place_details_atmosphere'];
        $placeholders = implode(',', array_fill(0, count($skus), '?'));
        try {
            $row = Database::getInstance()->fetch(
                "SELECT COALESCE(SUM(billable_units), 0) AS units
                 FROM api_cost_events
                 WHERE sku IN ($placeholders)
                   AND called_at >= DATE_FORMAT(NOW(), '%Y-%m-01')",
                $skus
            );
            return (int) ($row['units'] ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** Spent-so-far for the active campaign. Lazy + cached per call run. */
    protected function spentForCampaign(string $campaignId): float
    {
        try {
            $row = Database::getInstance()->fetch(
                'SELECT COALESCE(SUM(total_cost_usd), 0) AS total
                 FROM api_cost_events
                 WHERE campaign_id = ?',
                [$campaignId]
            );
            return (float) ($row['total'] ?? 0.0);
        } catch (\Throwable $e) {
            return 0.0;
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // Internal helpers
    // ─────────────────────────────────────────────────────────────────

    private function buildHeaders(string $mask): array
    {
        return [
            'Content-Type: application/json',
            'X-Goog-Api-Key: ' . $this->apiKey,
            'X-Goog-FieldMask: ' . $mask,
        ];
    }

    private function maskTokens(string $preset): array
    {
        $masks = $this->pricing['masks'] ?? [];
        if (!isset($masks[$preset])) {
            throw new \InvalidArgumentException("Unknown field-mask preset: $preset");
        }
        return $masks[$preset];
    }

    /** Sweep masks degrade to id-only when storage isn't permitted. */
    private function resolveSweepMask(string $preset): string
    {
        $tokens = $this->maskTokens($preset);
        if (!$this->isStorageAllowed()) {
            $tokens = ['places.id'];
            if ($preset === 'sweep_text') {
                $tokens[] = 'nextPageToken';
            }
        }
        return implode(',', $tokens);
    }

    private function stripPrefix(string $token): string
    {
        return str_starts_with($token, 'places.') ? substr($token, 7) : $token;
    }

    private function rateForSku(string $sku, int $units): float
    {
        // Add-on SKUs are flat-rate.
        if (isset($this->pricing['addons'][$sku])) {
            return (float) $this->pricing['addons'][$sku];
        }
        $family = match (true) {
            in_array($sku, ['places_nearby_pro', 'places_text_pro'], true) => 'search',
            $sku === 'place_details_pro'                                   => 'details',
            default                                                        => null,
        };
        if ($family === null) {
            return 0.0;
        }
        $tiers   = $this->pricing['tiers'][$family] ?? [];
        $running = $this->monthlyVolume($family);
        foreach ($tiers as $tier) {
            $cap = $tier['up_to_units'] ?? null;
            if ($cap === null || $running < $cap) {
                return (float) $tier['rate_per_1k_usd'];
            }
        }
        return 0.0;
    }

    private function enforceBudgetCap(array $skus, int $units): void
    {
        if ($this->campaignId === null || $this->budgetCap === null) {
            return;
        }
        $projected = $this->projectCost($skus, $units);
        if ($this->campaignSpent === null) {
            $this->campaignSpent = $this->spentForCampaign($this->campaignId);
        }
        if ($this->campaignSpent + $projected > $this->budgetCap) {
            throw new BudgetCapExceededException(sprintf(
                'Budget cap halted call: campaign %s spent $%.4f + projected $%.4f exceeds cap $%.4f',
                $this->campaignId,
                $this->campaignSpent,
                $projected,
                $this->budgetCap
            ));
        }
    }

    private function recordCall(array $skus, ?string $mask, int $httpStatus, int $latencyMs, ?string $errorMessage): void
    {
        $hash = $mask !== null ? $this->fieldMaskHash($mask) : null;
        // Per-SKU rows so the reconciliation join in §5.4 lines up with
        // Google's billing export (which also lists add-ons separately).
        foreach ($skus as $sku) {
            $unit  = $this->rateForSku($sku, 1);
            $total = round($unit / 1000.0, 6); // one unit, per-1k rate
            $event = [
                'campaign_id'     => $this->campaignId,
                'tile_id'         => $this->tileId,
                'sku'             => $sku,
                'billable_units'  => 1,
                'unit_cost_usd'   => $unit / 1000.0,
                'total_cost_usd'  => $total,
                'field_mask_hash' => $hash,
                'http_status'     => $httpStatus ?: null,
                'latency_ms'      => $latencyMs,
                'error_message'   => $errorMessage,
                'called_at'       => date('Y-m-d H:i:s'),
            ];
            $this->persistEvent($event);
            if ($this->campaignSpent !== null) {
                $this->campaignSpent += $total;
            }
        }
    }

    private function fieldMaskHash(string $mask): string
    {
        $tokens = array_filter(array_map('trim', explode(',', $mask)));
        sort($tokens);
        return substr(hash('sha256', implode(',', $tokens)), 0, 16);
    }
}
