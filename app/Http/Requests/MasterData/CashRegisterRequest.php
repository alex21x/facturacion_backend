<?php

namespace App\Http\Requests\MasterData;

class CashRegisterRequest extends MasterDataFormRequest
{
    public function rules(): array
    {
        $isUpdate = $this->isUpdateRequest();

        return [
            'branch_id' => 'nullable|integer|min:1',
            'warehouse_id' => 'nullable|integer|min:1',
            'code' => $isUpdate ? 'nullable|string|max:30' : 'required|string|max:30',
            'name' => $isUpdate ? 'nullable|string|max:120' : 'required|string|max:120',
            'status' => 'nullable|integer|in:0,1',
        ];
    }
}