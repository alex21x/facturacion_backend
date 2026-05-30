<?php

namespace App\Http\Requests\MasterData;

class UnitsRequest extends MasterDataFormRequest
{
    public function rules(): array
    {
        return [
            'units' => 'required|array|min:1',
            'units.*.id' => 'required|integer|min:1',
            'units.*.is_enabled' => 'required|boolean',
        ];
    }
}