<?php
declare(strict_types=1);

namespace App\TruckPlanner\Services\Support;

/**
 * A feature this server cannot offer right now: answered 503 with this message ("Contact lookup is not
 * available on this server"). The message is a fixed sentence, never upstream text.
 */
final class TpUnavailable extends \RuntimeException
{
}
