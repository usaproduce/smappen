<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Services;

use App\Tests\TruckPlanner\Support\LogCapture;
use App\Tests\TruckPlanner\Support\RecordingDatabase;
use App\TruckPlanner\Data\RegionRepository;
use App\TruckPlanner\Data\TruckRepository;
use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Model\ModelError;
use App\TruckPlanner\Model\Seeds;
use App\TruckPlanner\Services\AssumptionsFactory;
use App\TruckPlanner\Services\AssumptionsService;
use App\TruckPlanner\Services\Support\JsonSafe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AssumptionsServiceTest extends TestCase
{
    private const ORG = '11111111-1111-4111-8111-111111111111';
    private const TRUCK = '33333333-3333-4333-8333-333333333333';

    private RecordingDatabase $db;

    protected function setUp(): void
    {
        $this->db = new RecordingDatabase();
        $this->db->when('FROM tp_regions WHERE region_id = ?', [
            'region_id' => 'dc', 'name' => 'Washington, DC region', 'cbsa' => '47900', 'timezone' => 'America/New_York',
            'h3_res' => 9, 'bbox_lat_min' => 37.9907, 'bbox_lng_min' => -78.3947, 'bbox_lat_max' => 39.7201,
            'bbox_lng_max' => -76.6625, 'center_lat' => 38.9072, 'center_lng' => -77.0369,
            'active_version' => null, 'previous_version' => null,
            'config_json' => '{"traffic_matrix": "dc", "holidays": {"inauguration_day": true}}',
            'created_at' => '2026-10-04 20:00:00', 'updated_at' => '2026-10-04 20:00:00',
        ]);
    }

    private function service(): AssumptionsService
    {
        return new AssumptionsService(new TruckRepository($this->db), new RegionRepository($this->db));
    }

    /**
     * The truck value of the base controller, as far as this service reads it.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function truck(array $overrides = [], ?int $revision = null, string $regionId = 'dc'): array
    {
        return [
            'id' => self::TRUCK,
            'organization_id' => self::ORG,
            'overrides' => $overrides,
            'overrides_seeds_rev' => $revision ?? Seeds::revision(),
            'profile' => ['region_id' => $regionId],
        ];
    }

    /**
     * The one UPDATE of the request: [the JSON text that was stored, the revision it was stamped with].
     *
     * @return array{0: string, 1: int}
     */
    private function written(): array
    {
        $call = $this->db->only('UPDATE tp_trucks SET overrides_json = ?, overrides_seeds_rev = ?');
        self::assertStringContainsString('WHERE id = ? AND organization_id = ?', $call['sql']);
        self::assertSame([self::TRUCK, self::ORG], array_slice($call['params'], 2));
        return [$call['params'][0], $call['params'][1]];
    }

    /**
     * @param array<string, mixed> $changes
     * @param array<string, mixed> $stored
     * @return list<array{path: string, error: string}>
     */
    private function problemsOf(array $changes, array $stored = []): array
    {
        try {
            $this->service()->merge(self::ORG, self::truck($stored), $changes);
        } catch (ModelError $e) {
            self::assertSame(ModelError::INVALID_OVERRIDES, $e->errorCode());
            self::assertSame([], $this->db->find('UPDATE'), 'a refused map is not stored');
            return $e->details();
        }
        self::fail('accepted: ' . json_encode($changes));
    }

    // ------------------------------------------------------------------------------------ merge

    public function testChangesAreMergedIntoTheStoredMap(): void
    {
        $info = $this->service()->merge(
            self::ORG,
            self::truck(['weather.floor' => 0.2, 'host.captive_share' => 0.6]),
            ['events.attendance_haircut' => 0.5, 'host.captive_share' => 0.7]
        );

        [$json, $revision] = $this->written();
        // Paths in ascending order, whatever order they arrived in.
        self::assertSame('{"events.attendance_haircut":0.5,"host.captive_share":0.7,"weather.floor":0.2}', $json);
        self::assertSame(Seeds::revision(), $revision);
        self::assertSame(['model_version', 'seeds_revision', 'overrides', 'region'], array_keys($info));
        self::assertSame(['events.attendance_haircut' => 0.5, 'host.captive_share' => 0.7, 'weather.floor' => 0.2], $info['overrides']);
        self::assertSame(Seeds::revision(), $info['seeds_revision']);
    }

    public function testNullTakesAPathOutOfTheMap(): void
    {
        $info = $this->service()->merge(
            self::ORG,
            self::truck(['weather.floor' => 0.2, 'host.captive_share' => 0.6]),
            ['weather.floor' => null, 'events.attendance_haircut' => null]
        );
        self::assertSame('{"host.captive_share":0.6}', $this->written()[0]);
        self::assertSame(['host.captive_share' => 0.6], $info['overrides']);
    }

    public function testAnEmptyMapIsStoredAndSentAsAnObject(): void
    {
        $info = $this->service()->merge(self::ORG, self::truck(['weather.floor' => 0.2]), ['weather.floor' => null]);
        self::assertSame('{}', $this->written()[0]);
        self::assertSame([], $info['overrides']);
        // The controller names `assumptions.overrides` as a map when it answers.
        $sent = json_encode(JsonSafe::clean(['assumptions' => $info], ['assumptions.overrides']));
        self::assertStringContainsString('"overrides":{}', (string) $sent);
    }

    public function testEveryNumberReadsBackAsTheDoubleThatWasSent(): void
    {
        // 17 significant digits: a JSON column would hand back 0.2000001. The text column keeps the text.
        $sent = json_decode('{"weather.floor": 0.20000010000000001, "host.captive_share": 0.30000000000000004}', true);
        $info = $this->service()->merge(self::ORG, self::truck(), $sent);

        $json = $this->written()[0];
        self::assertSame('{"host.captive_share":0.30000000000000004,"weather.floor":0.20000010000000001}', $json);
        self::assertSame($sent['weather.floor'], json_decode($json, true)['weather.floor']);
        self::assertSame(0.20000010000000001, $info['overrides']['weather.floor']);
        self::assertNotSame(0.2000001, $info['overrides']['weather.floor']);
    }

    public function testAWholeNumberIsAcceptedWhereTheSeedIsARealAndReadsAsOne(): void
    {
        $info = $this->service()->merge(self::ORG, self::truck(), json_decode('{"weather.floor": 0, "host.onsite_kitchen_weight": 3}', true));
        self::assertSame(0.0, $info['overrides']['weather.floor']);
        self::assertSame(3.0, $info['overrides']['host.onsite_kitchen_weight']);
        self::assertSame('{"host.onsite_kitchen_weight":3,"weather.floor":0}', $this->written()[0]);
    }

    public function testCurvesAndChoicesAreReplacedWhole(): void
    {
        $curve = array_fill(0, 24, 0.25);
        $info = $this->service()->merge(self::ORG, self::truck(), [
            'segments.w_office.presence.weekday' => $curve,
            'segments.res.holiday_day_type.major' => 'saturday',
        ]);
        self::assertSame($curve, $info['overrides']['segments.w_office.presence.weekday']);
        self::assertSame('saturday', $info['overrides']['segments.res.holiday_day_type.major']);
    }

    public function testTheAnswerCarriesTheRegionOfTheTruck(): void
    {
        $info = $this->service()->merge(self::ORG, self::truck(), ['host.captive_share' => 0.6]);
        self::assertSame(['id' => 'dc', 'traffic_matrix' => 'dc', 'flags' => ['inauguration_day' => true]], $info['region']);
        self::assertSame(['dc'], $this->db->only('FROM tp_regions WHERE region_id = ?')['params']);
    }

    public function testATruckWithoutARegionAsksForNone(): void
    {
        $info = $this->service()->merge(self::ORG, self::truck([], null, 'none'), ['host.captive_share' => 0.6]);
        self::assertSame(['id' => 'none', 'traffic_matrix' => 'us_mean', 'flags' => ['inauguration_day' => false]], $info['region']);
        self::assertSame([], $this->db->find('FROM tp_regions'));
    }

    // ------------------------------------------------------------------------------------ refusals

    /**
     * The examples of 02_MODEL.md 2.2, one per error code, with the sentence of the 422.
     *
     * @return array<string, array{0: string, 1: mixed, 2: string, 3: string}> path, value, code, sentence
     */
    public static function refusedOverrides(): array
    {
        return [
            'unknown_path' => ['no.such.path', 1, 'unknown_path', 'overrides.no.such.path: unknown_path'],
            'not_a_seed' => ['host.captive_share.value', 0.5, 'not_a_seed', 'overrides.host.captive_share.value: not_a_seed'],
            'not_a_seed (a band limit)' => [
                'weather.temperature_bands.rows.50_59.upper_f', 61, 'not_a_seed',
                'overrides.weather.temperature_bands.rows.50_59.upper_f: not_a_seed',
            ],
            'not_overridable (build scope)' => ['kernel.outside_option_a0', 2.0, 'not_overridable', 'overrides.kernel.outside_option_a0: not_overridable'],
            'not_overridable (a profile default)' => ['profile_defaults.avg_ticket', 12.0, 'not_overridable', 'overrides.profile_defaults.avg_ticket: not_overridable'],
            'not_a_leaf' => ['segments.w_office.presence', ['weekday' => [1]], 'not_a_leaf', 'overrides.segments.w_office.presence: not_a_leaf'],
            'wrong_shape (23 numbers)' => [
                'segments.w_office.presence.weekday', array_fill(0, 23, 0.1), 'wrong_shape',
                'overrides.segments.w_office.presence.weekday: wrong_shape',
            ],
            'wrong_shape (text for a number)' => ['host.captive_share', '0.6', 'wrong_shape', 'overrides.host.captive_share: wrong_shape'],
            'wrong_shape (true for a number)' => ['host.captive_share', true, 'wrong_shape', 'overrides.host.captive_share: wrong_shape'],
            'out_of_bounds' => ['host.captive_share', 1.5, 'out_of_bounds', 'overrides.host.captive_share: out_of_bounds'],
            'not_allowed' => ['segments.res.holiday_day_type.major', 'monday', 'not_allowed', 'overrides.segments.res.holiday_day_type.major: not_allowed'],
        ];
    }

    #[DataProvider('refusedOverrides')]
    public function testEachErrorCodeRefusesTheSaveWithItsSentence(string $path, mixed $value, string $code, string $sentence): void
    {
        $problems = $this->problemsOf([$path => $value]);
        self::assertSame([['path' => $path, 'error' => $code]], $problems);
        self::assertSame(['message' => $sentence, 'details' => $problems], AssumptionsService::refusal($problems));
    }

    public function testEveryCodeOfTheModelHasAnExample(): void
    {
        $codes = array_values(array_unique(array_column(self::refusedOverrides(), 2)));
        sort($codes);
        self::assertSame(
            ['not_a_leaf', 'not_a_seed', 'not_allowed', 'not_overridable', 'out_of_bounds', 'unknown_path', 'wrong_shape'],
            $codes
        );
    }

    public function testTheSentenceNamesTheFirstProblemAndTheDetailsListThemAll(): void
    {
        $problems = $this->problemsOf(['weather.floor' => 7, 'host.captive_share' => 0.5, 'no.such.path' => 1, 'events.p_buy.general' => 'many']);
        self::assertSame(
            [
                ['path' => 'events.p_buy.general', 'error' => 'wrong_shape'],
                ['path' => 'no.such.path', 'error' => 'unknown_path'],
                ['path' => 'weather.floor', 'error' => 'out_of_bounds'],
            ],
            $problems
        );
        $refusal = AssumptionsService::refusal($problems);
        self::assertSame('overrides.events.p_buy.general: wrong_shape', $refusal['message']);
        self::assertSame($problems, $refusal['details']);
        self::assertSame(
            '[{"path":"events.p_buy.general","error":"wrong_shape"},{"path":"no.such.path","error":"unknown_path"},{"path":"weather.floor","error":"out_of_bounds"}]',
            json_encode($refusal['details'])
        );
    }

    public function testNothingIsClamped(): void
    {
        // Just outside the bounds is refused, the bounds themselves are taken as they are.
        self::assertSame([['path' => 'weather.floor', 'error' => 'out_of_bounds']], $this->problemsOf(['weather.floor' => 1.0000001]));
        self::assertSame([['path' => 'weather.floor', 'error' => 'out_of_bounds']], $this->problemsOf(['weather.floor' => -0.0000001]));
        $info = $this->service()->merge(self::ORG, self::truck(), ['weather.floor' => 1.0, 'weather.pop_when_missing' => 0.0]);
        self::assertSame(['weather.floor' => 1.0, 'weather.pop_when_missing' => 0.0], $info['overrides']);
    }

    public function testTheWholeMergedMapIsValidatedNotOnlyTheChanges(): void
    {
        // A stored value that the present seed file refuses blocks the save, although the change is fine.
        $problems = $this->problemsOf(['weather.floor' => 0.3], ['host.captive_share' => 1.5]);
        self::assertSame([['path' => 'host.captive_share', 'error' => 'out_of_bounds']], $problems);
    }

    // ------------------------------------------------------------------------------------ another seeds revision

    public function testASaveStartsFromTheMapTheOwnerSeesWhenTheSeedFileChanged(): void
    {
        // Stored under another revision: `no.such.path` and the out-of-range share no longer validate, and
        // AssumptionsFactory leaves them out of `A`. The save removes them from the store as well.
        $truck = self::truck(['no.such.path' => 1, 'host.captive_share' => 1.5, 'weather.floor' => 0.2], Seeds::revision() + 1);
        $info = $this->service()->merge(self::ORG, $truck, ['events.attendance_haircut' => 0.5]);
        [$json, $revision] = $this->written();
        self::assertSame('{"events.attendance_haircut":0.5,"weather.floor":0.2}', $json);
        self::assertSame(Seeds::revision(), $revision);
        self::assertSame(['events.attendance_haircut' => 0.5, 'weather.floor' => 0.2], $info['overrides']);
    }

    public function testWhatASaveStartsFromIsWhatTheFactoryShows(): void
    {
        $stored = ['no.such.path' => 1, 'host.captive_share' => 1.5, 'weather.floor' => 0.2, 'kernel.walk_decay_m' => 300];
        $truck = self::truck($stored, Seeds::revision() + 1);
        $seen = [];
        LogCapture::during(function () use ($truck, &$seen): void {
            $seen = (new AssumptionsFactory())->forTruck($truck, null)['overrides'];
        });
        $info = $this->service()->reset(self::ORG, $truck, []);
        self::assertSame($seen, $info['overrides']);
        self::assertSame(['weather.floor' => 0.2], $info['overrides']);
    }

    // ------------------------------------------------------------------------------------ reset

    public function testResetTakesTheNamedPathsOutAndIgnoresTheRest(): void
    {
        $truck = self::truck(['weather.floor' => 0.2, 'host.captive_share' => 0.6, 'events.attendance_haircut' => 0.5]);
        $info = $this->service()->reset(self::ORG, $truck, ['weather.floor', 'no.such.path', 'kernel.outside_option_a0', 'weather.pop_when_missing']);
        [$json, $revision] = $this->written();
        self::assertSame('{"events.attendance_haircut":0.5,"host.captive_share":0.6}', $json);
        self::assertSame(Seeds::revision(), $revision);
        self::assertSame(['events.attendance_haircut' => 0.5, 'host.captive_share' => 0.6], $info['overrides']);
    }

    public function testResetWithoutPathsEmptiesTheMap(): void
    {
        $info = $this->service()->reset(self::ORG, self::truck(['weather.floor' => 0.2, 'host.captive_share' => 0.6]), null);
        self::assertSame(['{}', Seeds::revision()], $this->written());
        self::assertSame([], $info['overrides']);
        self::assertSame('dc', $info['region']['id']);
    }

    public function testResetWithAnEmptyListChangesNothing(): void
    {
        $info = $this->service()->reset(self::ORG, self::truck(['weather.floor' => 0.2]), []);
        self::assertSame('{"weather.floor":0.2}', $this->written()[0]);
        self::assertSame(['weather.floor' => 0.2], $info['overrides']);
    }

    // ------------------------------------------------------------------------------------ what the model reads

    public function testTheModelReadsWhatWasSaved(): void
    {
        $info = $this->service()->merge(self::ORG, self::truck(), ['host.captive_share' => 0.6]);
        $A = Seeds::assumptions($info['overrides'], $info['region']);
        self::assertSame(0.6, Estimator::seed($A, 'host.captive_share'));
        self::assertSame([], Estimator::validateOverrides(Seeds::data(), $info['overrides']));
    }

    public function testARefusalNeedsAProblem(): void
    {
        $this->expectException(\LogicException::class);
        AssumptionsService::refusal([]);
    }
}
