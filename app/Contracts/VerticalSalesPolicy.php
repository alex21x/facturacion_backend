<?php

namespace App\Contracts;

/**
 * Contract for vertical-specific sales behaviour.
 *
 * Every vertical that needs to customise the shared sales nucleus MUST implement
 * this interface via its own vertical service (e.g. RestaurantOrderService).
 * Retail, pharmacy, workshop, hardware, hotel — each gets its own implementation
 * without ever modifying the shared CreateCommercialDocumentUseCase.
 *
 * USAGE PATTERN
 * ─────────────
 *   1. Vertical controller receives the HTTP request.
 *   2. Calls $policy->validateBeforeCreate()  — throws on bad input.
 *   3. Calls $payload = $policy->buildPayload() — enriches with vertical metadata.
 *   4. Calls CreateCommercialDocumentUseCase::execute($payload, ...).
 *   5. Calls $policy->onDocumentCreated()   — post-create side-effects.
 *
 * ADDING A NEW VERTICAL
 * ─────────────────────
 *   class PharmacySalesService implements VerticalSalesPolicy { ... }
 *   class WorkshopSalesService    implements VerticalSalesPolicy { ... }
 *   class HotelCheckoutService    implements VerticalSalesPolicy { ... }
 *
 * No changes to the nucleus. No changes to other verticals.
 */
interface VerticalSalesPolicy
{
    /**
     * Validate vertical-specific rules before the document is persisted.
     * MUST throw a RuntimeException (or domain-specific exception) on failure.
     *
     * @param  array   $input      Raw validated request payload from the controller
     * @param  object  $authUser   Authenticated user (Eloquent model or value object)
     * @param  int     $companyId  Tenant company
     * @throws \RuntimeException   On business-rule violations
     */
    public function validateBeforeCreate(array $input, object $authUser, int $companyId): void;

    /**
     * Build the final document payload that will be passed to the nucleus.
     * Add vertical-specific metadata, default fields, or override caller values here.
     *
     * @param  array   $input      Raw validated request payload from the controller
     * @param  object  $authUser   Authenticated user
     * @param  int     $companyId  Tenant company
     * @return array               Complete payload ready for CreateCommercialDocumentUseCase
     */
    public function buildPayload(array $input, object $authUser, int $companyId): array;

    /**
     * Post-creation side-effects specific to this vertical.
     * Examples: mark mesa as OCCUPIED, notify kitchen, reserve a hotel room.
     * Called AFTER the document has been persisted and committed.
     *
     * @param  array  $document   The persisted document array returned by the use case
     * @param  int    $companyId  Tenant company
     */
    public function onDocumentCreated(array $document, int $companyId): void;
}
