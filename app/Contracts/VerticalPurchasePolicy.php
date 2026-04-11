<?php

namespace App\Contracts;

/**
 * Contract for vertical-specific purchase behaviour.
 *
 * Every vertical that customises the shared purchases nucleus implements this.
 * Examples:
 *   - RestaurantPurchasePolicy: daily perishable market runs, auto-link to recipe ingredient
 *   - PharmacyPurchasePolicy:   lot/batch tracking, expiry date capture, controlled-substance flag
 *   - WorkshopPurchasePolicy:   link parts purchase to a specific work order
 *
 * ADDING A NEW VERTICAL
 * ─────────────────────
 *   class PharmacyPurchaseService  implements VerticalPurchasePolicy { ... }
 *   class RestaurantPurchaseService implements VerticalPurchasePolicy { ... }
 *   class WorkshopPurchaseService  implements VerticalPurchasePolicy { ... }
 */
interface VerticalPurchasePolicy
{
    /**
     * Validate vertical-specific rules before the purchase document is persisted.
     * MUST throw a RuntimeException on failure.
     *
     * @param  array   $input      Raw validated request payload from the controller
     * @param  object  $authUser   Authenticated user
     * @param  int     $companyId  Tenant company
     * @throws \RuntimeException
     */
    public function validateBeforeCreate(array $input, object $authUser, int $companyId): void;

    /**
     * Enrich the raw input into the final purchase payload.
     * Add lot numbers, expiry fields, work-order links, etc.
     *
     * @return array  Complete payload ready for CreatePurchaseDocumentUseCase
     */
    public function buildPayload(array $input, object $authUser, int $companyId): array;

    /**
     * Post-creation hook — e.g. auto-create lot records for pharmacy,
     * update work-order parts list for workshop.
     *
     * @param  array  $document   Persisted document data
     * @param  int    $companyId  Tenant company
     */
    public function onDocumentCreated(array $document, int $companyId): void;
}
