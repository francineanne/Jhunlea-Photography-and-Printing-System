<?php

namespace App\Services\Assistant\Eval;

/**
 * The facts the checks of one case need (Phase 7E-2b), all from the sandbox database: the scenario customer's own
 * records by reference, every other fake customer's references, and the peso amounts the shop's own data holds (the
 * published catalog, this customer's records and payments, and whatever the case's own messages say).
 */
final class EvalContext
{
    /**
     * @param  array<string, array{type: string, id: int}>  $ownRecords  reference => morph alias and id
     * @param  list<string>  $foreignReferences
     * @param  list<string>  $allowedAmounts  normalised like PriceGuard::amounts()
     */
    public function __construct(
        public readonly array $ownRecords,
        public readonly array $foreignReferences,
        public readonly array $allowedAmounts,
    ) {}
}
