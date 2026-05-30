<?php

namespace App\Http\Requests\MasterData;

class UserRequest extends MasterDataFormRequest
{
    public function rules(): array
    {
        $isUpdate = $this->isUpdateRequest();

        $rules = [
            'branch_id' => 'nullable|integer|min:1',
            'preferred_warehouse_id' => 'nullable|integer|min:1',
            'preferred_cash_register_id' => 'nullable|integer|min:1',
            'password' => $isUpdate ? 'nullable|string|min:6|max:120' : 'required|string|min:6|max:120',
            'first_name' => $isUpdate ? 'nullable|string|max:80' : 'required|string|max:80',
            'last_name' => 'nullable|string|max:80',
            'email' => 'nullable|email|max:120',
            'phone' => 'nullable|string|max:40',
            'status' => 'nullable|integer|in:0,1',
            'role_id' => $isUpdate ? 'nullable|integer|min:1' : 'required|integer|min:1',
        ];

        if (!$isUpdate) {
            $rules = ['username' => 'required|string|max:80'] + $rules;
        }

        return $rules;
    }
}