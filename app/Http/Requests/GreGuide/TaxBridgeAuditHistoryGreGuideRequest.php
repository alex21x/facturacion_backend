<?php

namespace App\Http\Requests\GreGuide;

use App\Http\Requests\Api\ApiFirstErrorFormRequest;

class TaxBridgeAuditHistoryGreGuideRequest extends ApiFirstErrorFormRequest
{
    public function rules(): array
    {
        return [
            'company_id' => 'nullable|integer|min:1',
            'limit' => 'nullable|integer|min:1|max:500',
        ];
    }
}
