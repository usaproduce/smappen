<?php
declare(strict_types=1);

namespace App\TruckPlanner\Model;

/**
 * An error raised by the model. The message is the code.
 *
 * The three codes of 02_MODEL.md are `invalid_date`, `invalid_window` and `missing_context`. Two more belong
 * to this port: `non_finite` (a result would hold INF or NAN, or a ranking key or a whole minute left the
 * integer range: the inputs were outside what the model accepts) and `invalid_overrides` (raised by
 * Seeds::withOverrides; details() lists the offending paths).
 *
 * Callers validate their inputs before they call the model, so one of these reaching a controller is a
 * server defect.
 */
final class ModelError extends \InvalidArgumentException
{
    public const INVALID_DATE = 'invalid_date';
    public const INVALID_WINDOW = 'invalid_window';
    public const MISSING_CONTEXT = 'missing_context';
    public const NON_FINITE = 'non_finite';
    public const INVALID_OVERRIDES = 'invalid_overrides';

    /** @var array<int|string, mixed> */
    private array $details;

    /**
     * @param array<int|string, mixed> $details what the code alone does not say (never part of the message)
     */
    public function __construct(string $errorCode, array $details = [])
    {
        parent::__construct($errorCode);
        $this->details = $details;
    }

    /** The code, for example "invalid_window". Same as getMessage(). */
    public function errorCode(): string
    {
        return $this->getMessage();
    }

    /** @return array<int|string, mixed> */
    public function details(): array
    {
        return $this->details;
    }
}
