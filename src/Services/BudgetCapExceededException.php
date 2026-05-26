<?php
namespace App\Services;

/**
 * Thrown by PlacesClient when projecting the next call's cost would
 * push a campaign's cumulative spend past its budget cap. Carafe Vendor
 * Network Spec v3 §10 guardrail 5 — "Budget cap halts, never overruns."
 *
 * Workers catch this, mark the seed_campaign as paused, write a halt
 * record, and alert the operator. Callers should NOT treat this as a
 * transient failure to retry — the cap is a hard ceiling.
 */
class BudgetCapExceededException extends \RuntimeException
{
}
