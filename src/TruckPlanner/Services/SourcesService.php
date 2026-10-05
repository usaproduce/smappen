<?php
declare(strict_types=1);

namespace App\TruckPlanner\Services;

use App\Core\Config;
use App\TruckPlanner\Data\RegionRepository;
use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Services\Support\Registry;

/**
 * Where the numbers come from (04_BACKEND.md 4.16, 03_DATA.md section 14): the versions of the model and of
 * the region data a truck is working with, the fuel price in use, and the source lines the product must
 * show.
 *
 * The twelve source lines are those of 03_DATA.md section 14, with its numbering as their `id` and every
 * placeholder filled. A line whose placeholder cannot be filled (no dataset loaded, no dated fuel price, no
 * contact address) is left out, never sent with a brace in it. Line 11 is left out while the time-of-day
 * traffic table is neutral.
 *
 * It answers without a truck: the lines that need no dataset are still true then.
 */
class SourcesService
{
    private const OSM_URL = 'https://www.openstreetmap.org/copyright';
    private const LEHD_URL = 'https://lehd.ces.census.gov/data/';

    /**
     * The strings of 03_DATA.md section 14, character for character, by their number there.
     *
     * @var array<int, string>
     */
    public const TEXTS = [
        1 => "\u{00A9} OpenStreetMap contributors",
        2 => "Place data \u{00A9} OpenStreetMap contributors, available under the Open Database License (ODbL).",
        3 => 'Residents: U.S. Census Bureau, 2020 Census Redistricting Data (Public Law 94-171). '
            . 'Counts as of April 1, 2020, not adjusted for growth.',
        4 => 'Jobs: U.S. Census Bureau, LEHD Origin-Destination Employment Statistics (LODES), version 8.4, 2023, '
            . 'all jobs. Job counts are jobs of record with statistical noise added by the Census Bureau, not people '
            . 'present. {blocks_adjusted} payroll-address blocks holding {jobs_spread} jobs were spread over their '
            . 'county (corrections {corrections_version}); construction jobs count at {cns04_weight_percent} %.',
        5 => "Places: OpenStreetMap snapshot of {osm_snapshot_date} (Geofabrik extracts). \u{00A9} OpenStreetMap "
            . 'contributors, ODbL 1.0. The places table is a database derived from OpenStreetMap and is available '
            . 'under the ODbL on request: {contact}.',
        6 => 'Forecast: National Weather Service (weather.gov).',
        7 => 'Fuel price: U.S. Energy Information Administration, weekly retail prices, week of {period}.',
        8 => 'County boundaries: U.S. Census Bureau, TIGERweb.',
        9 => 'Drive times and distances: Google Maps Platform. Kept for at most 30 days.',
        10 => "People: US Census {census_year}, LEHD {lodes_year} \u{00B7} Venues: \u{00A9} OpenStreetMap contributors",
        11 => 'Time-of-day traffic factors: derived from the TomTom Traffic Index 2025.',
        12 => 'Phone and website from Google Maps',
    ];

    /** The page a line links to, by its number. Lines without an entry carry no link. */
    private const URLS = [1 => self::OSM_URL, 2 => self::OSM_URL, 4 => self::LEHD_URL, 10 => self::OSM_URL];

    private const TRAFFIC_LINE = 11;

    private RegionRepository $regionRows;
    private RegionService $regions;
    private AssumptionsFactory $factory;

    /** @var callable(string): ?string */
    private $setting;

    /**
     * @param callable(string): ?string|null $setting reads one environment setting by its name, null when
     *                                                it is not set (a test passes its own)
     */
    public function __construct(
        ?RegionRepository $regionRows = null,
        ?RegionService $regions = null,
        ?AssumptionsFactory $factory = null,
        ?callable $setting = null
    ) {
        $this->regionRows = $regionRows ?? new RegionRepository();
        $this->regions = $regions ?? new RegionService($this->regionRows);
        $this->factory = $factory ?? new AssumptionsFactory();
        $this->setting = $setting ?? static function (string $name): ?string {
            $value = Config::get($name);
            return is_string($value) && trim($value) !== '' ? trim($value) : null;
        };
    }

    /**
     * @param array<string, mixed>|null $truck the truck value of TruckBaseController::truck(), null when
     *                                         the organization has none
     * @return array{model_version: string, seeds_revision: int, region: ?array<string, mixed>,
     *               dataset: ?array<string, mixed>, fuel: ?array<string, mixed>, contact: string,
     *               attribution: list<array{id: int, text: string, url: ?string}>}
     *         `region` is the truck's RegionInfo (null for region `none` and without a truck); `dataset`
     *         describes its active dataset version (null when none is loaded); `fuel` is the FuelInfo in
     *         use (null without a truck); `contact` is the address the places table is offered at, ''
     *         when the server has none
     */
    public function build(?array $truck): array
    {
        $regionId = RegionService::NONE;
        if ($truck !== null && is_string($truck['profile']['region_id'] ?? null) && $truck['profile']['region_id'] !== '') {
            $regionId = $truck['profile']['region_id'];
        }
        $regionRow = $regionId === RegionService::NONE ? null : $this->regionRows->find($regionId);
        $A = $this->factory->forTruck($truck, $regionRow);

        $manifest = null;
        $active = $this->regions->active($regionId);
        if ($active !== null) {
            $manifest = $this->regionRows->manifest($regionId, (string) $active['dataset_version']);
        }
        $dataset = $active === null || $manifest === null
            ? null
            : self::dataset((string) $active['dataset_version'], $manifest);

        $fuel = $truck === null ? null : Registry::fuel()->resolve($truck);
        $contact = $this->contact();

        return [
            'model_version' => (string) $A['model_version'],
            'seeds_revision' => (int) $A['seeds_revision'],
            'region' => $this->regions->info($regionId),
            'dataset' => $dataset,
            'fuel' => $fuel,
            'contact' => $contact ?? '',
            'attribution' => self::attribution(self::values($manifest, $fuel, $contact), self::trafficIsNeutral($A)),
        ];
    }

    /**
     * The source lines for a set of placeholder values.
     *
     * @param array<string, string> $values placeholder name (without braces) => its text. A placeholder
     *                                      that is absent cannot be filled
     * @param bool $trafficNeutral leave line 11 out
     * @return list<array{id: int, text: string, url: ?string}> in ascending id
     */
    public static function attribution(array $values, bool $trafficNeutral): array
    {
        $replace = [];
        foreach ($values as $name => $text) {
            $replace['{' . $name . '}'] = $text;
        }
        $lines = [];
        foreach (self::TEXTS as $id => $template) {
            if ($id === self::TRAFFIC_LINE && $trafficNeutral) {
                continue;
            }
            $text = strtr($template, $replace);
            // A placeholder that was not filled, or a value that brought a brace of its own: not sent.
            if (strpbrk($text, '{}') !== false) {
                continue;
            }
            $lines[] = ['id' => $id, 'text' => $text, 'url' => self::URLS[$id] ?? null];
        }
        return $lines;
    }

    /**
     * Is the time-of-day traffic table of these Assumptions neutral: every hour of the week of the
     * region's matrix, and the matrix's typical value, equal to 1?
     *
     * @param array<string, mixed> $A
     */
    public static function trafficIsNeutral(array $A): bool
    {
        $name = (string) ($A['region']['traffic_matrix'] ?? '');
        try {
            $matrix = Estimator::seed($A, 'traffic.' . $name);
            $typical = Estimator::seed($A, 'traffic.' . $name . '_typical');
        } catch (\OutOfBoundsException | \TypeError $e) {
            return false;                     // no such table: nothing says the factors are neutral
        }
        if (!self::isOne($typical) || !is_array($matrix) || $matrix === []) {
            return false;
        }
        foreach ($matrix as $day) {
            if (!is_array($day) || $day === []) {
                return false;
            }
            foreach ($day as $factor) {
                if (!self::isOne($factor)) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * What the Data page shows of a dataset version, read from its manifest (03_DATA.md 8.5).
     *
     * @param array<string, mixed> $manifest
     * @return array{dataset_version: string, pipeline_version: ?string, corrections_version: ?string,
     *               places_source: ?string,
     *               vintages: array{census_reference_date: ?string, lodes_year: ?int, osm_snapshot_date: ?string},
     *               counts: array{points: ?int, places: ?int, cells: ?int},
     *               totals: array{residents: ?int, jobs: ?int}, warn_gates: list<string>}
     *         `warn_gates` are the ids of the quality gates of level `warn` that did not pass, each once,
     *         in the order of the manifest
     */
    private static function dataset(string $version, array $manifest): array
    {
        $inputs = is_array($manifest['inputs'] ?? null) ? $manifest['inputs'] : [];
        $vintages = is_array($manifest['vintages'] ?? null) ? $manifest['vintages'] : [];
        $counts = is_array($manifest['counts'] ?? null) ? $manifest['counts'] : [];
        $totals = is_array($manifest['totals'] ?? null) ? $manifest['totals'] : [];

        $warnGates = [];
        foreach (is_array($manifest['gates'] ?? null) ? $manifest['gates'] : [] as $gate) {
            if (is_array($gate) && ($gate['level'] ?? null) === 'warn' && ($gate['pass'] ?? null) === false
                && is_string($gate['id'] ?? null) && !in_array($gate['id'], $warnGates, true)) {
                $warnGates[] = $gate['id'];
            }
        }

        return [
            'dataset_version' => $version,
            'pipeline_version' => self::textOf($manifest['pipeline_version'] ?? null),
            'corrections_version' => self::textOf($inputs['corrections_version'] ?? null),
            'places_source' => self::textOf($inputs['places_source'] ?? null),
            'vintages' => [
                'census_reference_date' => self::textOf($vintages['census_reference_date'] ?? null),
                'lodes_year' => self::wholeOf($vintages['lodes_year'] ?? null),
                'osm_snapshot_date' => self::textOf($vintages['osm_snapshot_date'] ?? null),
            ],
            'counts' => [
                'points' => self::wholeOf($counts['points']['total'] ?? null),
                'places' => self::wholeOf($counts['places']['total'] ?? null),
                'cells' => self::wholeOf($counts['cells']['kept'] ?? null),
            ],
            'totals' => [
                'residents' => self::wholeOf($totals['residents'] ?? null),
                'jobs' => self::wholeOf($totals['jobs'] ?? null),
            ],
            'warn_gates' => $warnGates,
        ];
    }

    /**
     * The placeholder values that can be filled, as the table of 03_DATA.md section 14 says.
     *
     * @param array<string, mixed>|null $manifest the manifest of the active dataset version
     * @param array<string, mixed>|null $fuel FuelInfo
     * @return array<string, string>
     */
    private static function values(?array $manifest, ?array $fuel, ?string $contact): array
    {
        $values = [];
        if ($contact !== null) {
            $values['contact'] = $contact;
        }
        $period = self::textOf($fuel['period'] ?? null);
        if ($period !== null) {
            $values['period'] = $period;
        }
        if ($manifest === null) {
            return $values;
        }

        $snapshot = self::textOf($manifest['vintages']['osm_snapshot_date'] ?? null);
        if ($snapshot !== null) {
            $values['osm_snapshot_date'] = $snapshot;
        }
        $census = self::textOf($manifest['vintages']['census_reference_date'] ?? null);
        if ($census !== null && preg_match('/^[0-9]{4}/', $census) === 1) {
            $values['census_year'] = substr($census, 0, 4);
        }
        $lodes = self::wholeOf($manifest['vintages']['lodes_year'] ?? null);
        if ($lodes !== null) {
            $values['lodes_year'] = (string) $lodes;
        }
        $blocks = self::wholeOf($manifest['totals']['blocks_adjusted'] ?? null);
        if ($blocks !== null) {
            $values['blocks_adjusted'] = (string) $blocks;
        }
        $spread = self::wholeOf($manifest['totals']['jobs_spread'] ?? null);
        if ($spread !== null) {
            $values['jobs_spread'] = (string) $spread;
        }
        $corrections = self::textOf($manifest['inputs']['corrections_version'] ?? null);
        if ($corrections !== null) {
            $values['corrections_version'] = $corrections;
        }
        $weight = $manifest['parameters']['cns04_weight'] ?? null;
        if (self::isNumber($weight)) {
            $values['cns04_weight_percent'] = (string) (int) Estimator::roundHalfAway((float) $weight * 100.0, 0);
        }
        return $values;
    }

    /** The address in the offer of the places table: TP_CONTACT_EMAIL, else MAIL_FROM, else none. */
    private function contact(): ?string
    {
        foreach (['TP_CONTACT_EMAIL', 'MAIL_FROM'] as $name) {
            $value = ($this->setting)($name);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }
        return null;
    }

    private static function isNumber(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value);
    }

    private static function isOne(mixed $value): bool
    {
        return self::isNumber($value) && (float) $value === 1.0;
    }

    /** A non-empty text, or a number as text; null for anything else. */
    private static function textOf(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value === '' ? null : $value;
        }
        return is_int($value) ? (string) $value : null;
    }

    /** A number as a whole number, rounded with the model's one rule; null for anything else. */
    private static function wholeOf(mixed $value): ?int
    {
        return self::isNumber($value) ? (int) Estimator::roundHalfAway((float) $value, 0) : null;
    }
}
