<?php

namespace App\Http\Requests\GreGuide;

use App\Http\Requests\Api\ApiFirstErrorFormRequest;

class PrefillGreGuideFromDocumentRequest extends ApiFirstErrorFormRequest
{
    public function rules(): array
    {
        return [
            'document_id' => 'nullable|integer|min:1',
            'series' => 'nullable|string|max:8',
            'number' => 'nullable|integer|min:1',
            'document_kind' => 'nullable|string|in:INVOICE,RECEIPT',
        ];
    }
}
