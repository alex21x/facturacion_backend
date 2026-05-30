<?php

namespace App\Http\Requests\MasterData;

class PriceTierRequest extends MasterDataFormRequest
{
    public function rules(): array
    {
        $isUpdate = $this->isUpdateRequest();

        return [
            'code' => $isUpdate ? 'nullable|string|max:30' : 'required|string|max:30',
            'name' => $isUpdate ? 'nullable|string|max:120' : 'required|string|max:120',
            'min_qty' => $isUpdate ? 'nullable|numeric|gt:0' : 'required|numeric|gt:0',
            'max_qty' => 'nullable|numeric|gt:0',
            'priority' => 'nullable|integer|min:1',
            'status' => 'nullable|integer|in:0,1',
        ];
    }
}