<?php

namespace App\Http\Requests\MasterData;

class PaymentMethodRequest extends MasterDataFormRequest
{
    public function rules(): array
    {
        $isUpdate = $this->isUpdateRequest();

        return [
            'code' => $isUpdate ? 'nullable|string|max:20' : 'required|string|max:20',
            'name' => $isUpdate ? 'nullable|string|max:100' : 'required|string|max:100',
            'status' => 'nullable|integer|in:0,1',
        ];
    }
}