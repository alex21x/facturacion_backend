<?php

namespace App\Http\Requests\MasterData;

class LotRequest extends MasterDataFormRequest
{
    public function rules(): array
    {
        return [
            'product_id' => 'required|integer|min:1',
            'warehouse_id' => 'required|integer|min:1',
            'lot_code' => 'required|string|max:60',
            'manufacture_at' => 'nullable|date',
            'expires_at' => 'nullable|date',
            'unit_cost' => 'nullable|numeric|min:0',
            'supplier_reference' => 'nullable|string|max:120',
            'status' => 'nullable|integer|in:0,1',
        ];
    }
}