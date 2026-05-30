<?php

namespace App\Http\Requests\MasterData;

use App\Http\Requests\Api\ApiFormRequest;
use App\Services\Sales\SalesLookupService;

abstract class MasterDataFormRequest extends ApiFormRequest
{
    protected function isUpdateRequest(): bool
    {
        return $this->route('id') !== null || $this->route('code') !== null;
    }

    protected function documentKindRule(bool $required): string
    {
        $codes = collect(app(SalesLookupService::class)->listDocumentKindsCatalog())
            ->map(function ($row) {
                if (is_array($row)) {
                    return (string) ($row['code'] ?? '');
                }

                return (string) ($row->code ?? '');
            })
            ->filter()
            ->values()
            ->all();

        return ($required ? 'required' : 'nullable') . '|string|in:' . implode(',', $codes);
    }
}