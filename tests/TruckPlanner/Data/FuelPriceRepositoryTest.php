<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Data;

use App\Tests\TruckPlanner\Support\RecordingDatabase;
use App\TruckPlanner\Data\FuelPriceRepository;
use PHPUnit\Framework\TestCase;

/**
 * `tp_fuel_prices`: weekly prices stored in thousandths of a dollar, read as dollars per gallon.
 */
final class FuelPriceRepositoryTest extends TestCase
{
    public function testTheNewestPriceOfAnAreaAndProduct(): void
    {
        $db = (new RecordingDatabase())->queue([
            'duoarea' => 'R1Z', 'product' => 'EPMR', 'period' => '2026-09-28', 'price_milli' => '4195',
            'series_id' => 'EMM_EPMR_PTE_R1Z_DPG', 'fetched_at' => '2026-09-29 14:05:11',
        ]);
        $row = (new FuelPriceRepository($db))->latest('R1Z', 'EPMR');
        self::assertSame(
            [
                'duoarea' => 'R1Z', 'product' => 'EPMR', 'period' => '2026-09-28', 'price' => 4.195,
                'series_id' => 'EMM_EPMR_PTE_R1Z_DPG', 'fetched_at' => '2026-09-29 14:05:11',
            ],
            $row
        );
        $call = $db->calls[0];
        self::assertSame('fetch', $call['kind']);
        self::assertSame(
            'SELECT duoarea, product, period, price_milli, series_id, fetched_at FROM tp_fuel_prices '
            . 'WHERE duoarea = ? AND product = ? ORDER BY period DESC LIMIT 1',
            $call['sql']
        );
        self::assertSame(['R1Z', 'EPMR'], $call['params']);
    }

    public function testNoStoredPrice(): void
    {
        self::assertNull((new FuelPriceRepository(new RecordingDatabase()))->latest('NUS', 'EPD2D'));
    }

    public function testThousandthsBecomeDollarsExactly(): void
    {
        foreach ([[4195, 4.195], [5953, 5.953], [6382, 6.382], [4000, 4.0], [1, 0.001], [19999, 19.999]] as [$milli, $dollars]) {
            $db = (new RecordingDatabase())->queue([
                'duoarea' => 'NUS', 'product' => 'EPMR', 'period' => '2026-09-28', 'price_milli' => $milli,
                'series_id' => 's', 'fetched_at' => '2026-09-29 14:05:11',
            ]);
            self::assertSame($dollars, (new FuelPriceRepository($db))->latest('NUS', 'EPMR')['price']);
        }
    }

    public function testTheNewestStoredWeek(): void
    {
        $db = (new RecordingDatabase())->queue(['newest' => '2026-09-28']);
        self::assertSame('2026-09-28', (new FuelPriceRepository($db))->newestPeriod());
        self::assertSame('SELECT MAX(period) AS newest FROM tp_fuel_prices', $db->calls[0]['sql']);

        // an empty table answers one row holding NULL
        $empty = (new RecordingDatabase())->queue(['newest' => null]);
        self::assertNull((new FuelPriceRepository($empty))->newestPeriod());
    }

    public function testUpsertStoresThousandthsAndReplacesAWeekThatIsThere(): void
    {
        $db = new RecordingDatabase();
        $written = (new FuelPriceRepository($db))->upsertMany([
            ['duoarea' => 'R1Z', 'product' => 'EPMR', 'period' => '2026-09-28', 'price' => 4.195, 'series_id' => 'EMM_EPMR_PTE_R1Z_DPG'],
            ['duoarea' => 'R1Y', 'product' => 'EPD2D', 'period' => '2026-09-28', 'price' => 6.531, 'series_id' => 'EMD_EPD2D_PTE_R1Y_DPG'],
            // the same area, product and week again: the later row wins and is written once
            ['duoarea' => 'R1Z', 'product' => 'EPMR', 'period' => '2026-09-28', 'price' => 4.2, 'series_id' => 'EMM_EPMR_PTE_R1Z_DPG'],
        ]);
        self::assertSame(2, $written);
        $call = $db->only('INSERT INTO tp_fuel_prices');
        self::assertSame(
            'INSERT INTO tp_fuel_prices (duoarea, product, period, price_milli, series_id, src, fetched_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, NOW()), (?, ?, ?, ?, ?, ?, NOW()) '
            . 'ON DUPLICATE KEY UPDATE price_milli = VALUES(price_milli), series_id = VALUES(series_id), src = VALUES(src), fetched_at = NOW()',
            $call['sql']
        );
        self::assertSame(
            [
                'R1Y', 'EPD2D', '2026-09-28', 6531, 'EMD_EPD2D_PTE_R1Y_DPG', 'eia',
                'R1Z', 'EPMR', '2026-09-28', 4200, 'EMM_EPMR_PTE_R1Z_DPG', 'eia',
            ],
            $call['params']
        );
    }

    public function testEveryPublishedThreeDecimalPriceIsStoredToTheThousandth(): void
    {
        // The check values of 03_DATA.md 13.2, and prices whose product with 1000 is not a whole double.
        $prices = [
            [4.195, 4195], [4.411, 4411], [5.953, 5953], [6.531, 6531], [4.465, 4465], [6.382, 6382],
            [1.005, 1005], [2.675, 2675], [8.115, 8115],
        ];
        $rows = [];
        foreach ($prices as $i => [$price]) {
            $rows[] = ['duoarea' => 'A' . $i, 'product' => 'EPMR', 'period' => '2026-09-28', 'price' => $price, 'series_id' => 's'];
        }
        $db = new RecordingDatabase();
        (new FuelPriceRepository($db))->upsertMany($rows);
        $params = $db->only('INSERT INTO tp_fuel_prices')['params'];
        foreach ($prices as $i => [, $milli]) {
            self::assertSame($milli, $params[$i * 6 + 3]);
        }
    }

    public function testNothingToStore(): void
    {
        $db = new RecordingDatabase();
        self::assertSame(0, (new FuelPriceRepository($db))->upsertMany([]));
        self::assertSame([], $db->calls);
    }
}
