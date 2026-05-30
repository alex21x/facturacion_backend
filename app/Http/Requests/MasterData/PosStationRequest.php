<?php

namespace App\Http\Requests\MasterData;

class PosStationRequest extends MasterDataFormRequest
{
    public function rules(): array
    {
        $isUpdate = $this->isUpdateRequest();

        return [
            'cash_register_id' => $isUpdate ? 'nullable|integer|min:1' : 'required|integer|min:1',
            'code' => $isUpdate ? 'nullable|string|max:30' : 'required|string|max:30',
            'name' => $isUpdate ? 'nullable|string|max:120' : 'required|string|max:120',
            'device_id' => [$isUpdate ? 'nullable' : 'required', 'string', 'max:120', 'regex:/^CAJA-\d{3}$/i'],
            'device_name' => 'nullable|string|max:120',
            'status' => 'nullable|integer|in:0,1',
        ];
    }

    public function messages(): array
    {
        return [
            'device_id.regex' => 'Device ID debe tener el formato CAJA-001.',
        ];
    }
}