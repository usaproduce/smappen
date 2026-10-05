<?php
declare(strict_types=1);

namespace App\TruckPlanner\Services\Support;

/**
 * An id or key that does not exist for this organization: answered 404 with this message.
 *
 * An id that belongs to another organization is reported with the same sentence as a missing one. It is
 * never a 403.
 */
final class TpNotFound extends \RuntimeException
{
}
