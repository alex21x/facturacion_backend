<?php

namespace App\Http\Requests\MasterData;

class DocumentKindsRequest extends MasterDataFormRequest
{
    public function rules(): array
    {
        return [
            'kinds' => 'required|array|min:1',
            'kinds.*.original_code' => 'nullable|string|max:30',
            'kinds.*.code' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9_]+$/'],
            'kinds.*.label' => 'nullable|string|max:120',
            'kinds.*.is_enabled' => 'required|boolean',
        ];
    }
}