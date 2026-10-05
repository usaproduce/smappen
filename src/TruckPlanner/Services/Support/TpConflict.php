<?php
declare(strict_types=1);

namespace App\TruckPlanner\Services\Support;

/**
 * A request that is valid but cannot be carried out in the present state: answered 409 with this message
 * ("A plan already exists for this date", "Region data was built with different model constants").
 */
final class TpConflict extends \RuntimeException
{
}
