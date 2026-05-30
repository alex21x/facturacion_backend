<?php

namespace App\Http\Requests\MasterData;

class DocumentKindRequest extends MasterDataFormRequest
{
    public function rules(): array
    {
        $isUpdate = $this->isUpdateRequest();

        return [
            'code' => [$isUpdate ? 'nullable' : 'required', 'string', 'max:30', 'regex:/^[A-Z0-9_]+$/'],
            'label' => $isUpdate ? 'nullable|string|max:120' : 'required|string|max:120',
            'sort_order' => 'nullable|integer|min:0|max:9999',
            'is_enabled' => 'nullable|boolean',
        ];
    }
}