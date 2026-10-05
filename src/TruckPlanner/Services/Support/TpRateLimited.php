<?php
declare(strict_types=1);

namespace App\TruckPlanner\Services\Support;

/**
 * A request a service turns away because a shared upstream bucket is empty: answered 429 with this message
 * ("Too many lookups right now. Try again in a minute"). The per-user limits of the route table are the
 * rate-limit middleware's and never pass through here.
 */
final class TpRateLimited extends \RuntimeException
{
}
