<?php

namespace App\Http\Requests\MasterData;

class FunctionalProfileRequest extends MasterDataFormRequest
{
    public function rules(): array
    {
        $rules = [
            'label' => $this->isUpdateRequest() ? 'nullable|string|max:120' : 'required|string|max:120',
            'status' => 'nullable|integer|in:0,1',
            'sort_order' => 'nullable|integer|min:0|max:9999',
        ];

        if (!$this->isUpdateRequest()) {
            $rules = ['code' => 'required|string|max:40'] + $rules;
        }

        return $rules;
    }
}