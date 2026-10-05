<?php
declare(strict_types=1);

namespace App\TruckPlanner\Services\Support;

/**
 * A request that fails validation: answered 422 with this message by TruckBaseController::run().
 *
 * Every validation failure in Truck Planner code is a TpInvalid. A bare \InvalidArgumentException can only
 * come from the model and is a server defect (500), so the base controller catches this class first.
 *
 * `field` is the dotted path of the field the message is about ("stops[1].open_minute") and `rule` the id
 * of the fixed message ("V1" .. "V12") when it is one of them. Both travel in the `details` of the answer,
 * so a form can attach the sentence to its field.
 */
final class TpInvalid extends \InvalidArgumentException
{
    private ?string $field;
    private ?string $rule;

    public function __construct(string $message, ?string $field = null, ?string $code = null)
    {
        parent::__construct($message);
        $this->field = $field;
        $this->rule = $code;
    }

    /** The dotted path of the field, or null when the message is not about one field. */
    public function field(): ?string
    {
        return $this->field;
    }

    /** "V1" .. "V12" for the fixed messages of 04_BACKEND.md 4.2, else null. */
    public function rule(): ?string
    {
        return $this->rule;
    }

    /**
     * The third argument of Response::error: {"field": ..., "code": ...} without the absent parts, or null
     * when there is neither.
     *
     * @return array<string, string>|null
     */
    public function details(): ?array
    {
        $details = [];
        if ($this->field !== null) {
            $details['field'] = $this->field;
        }
        if ($this->rule !== null) {
            $details['code'] = $this->rule;
        }
        return $details === [] ? null : $details;
    }
}
