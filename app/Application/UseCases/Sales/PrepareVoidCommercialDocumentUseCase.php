<?php

namespace App\Application\UseCases\Sales;

use App\Services\Sales\Documents\SalesDocumentException;
use App\Services\Sales\SalesLookupService;
use Illuminate\Support\Facades\Hash;

class PrepareVoidCommercialDocumentUseCase
{
    public function __construct(private SalesLookupService $salesLookupService)
    {
    }

    public function execute(object $authUser, array $payload, bool $requireVoidPassword): array
    {
        $voidPassword = trim((string) ($payload['void_password'] ?? ''));
        unset($payload['void_password']);

        if (!$requireVoidPassword) {
            return $payload;
        }

        if ($voidPassword === '') {
            throw new SalesDocumentException('Debe ingresar su clave para confirmar la anulacion.');
        }

        $passwordHash = $this->salesLookupService->findUserPasswordHashById((int) $authUser->id);

        if (!$passwordHash || !Hash::check($voidPassword, (string) $passwordHash)) {
            throw new SalesDocumentException('Clave invalida para anular el documento.');
        }

        return $payload;
    }
}
