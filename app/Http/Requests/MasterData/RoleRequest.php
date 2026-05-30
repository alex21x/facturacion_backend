<?php

namespace App\Http\Requests\MasterData;

class RoleRequest extends MasterDataFormRequest
{
    public function rules(): array
    {
        $isUpdate = $this->isUpdateRequest();

        $rules = [
            'name' => $isUpdate ? 'nullable|string|max:120' : 'required|string|max:120',
            'status' => 'nullable|integer|in:0,1',
            'functional_profile' => 'nullable|string|max:40',
            'permissions' => $isUpdate ? 'nullable|array|min:1' : 'required|array|min:1',
            'permissions.*.module_code' => 'required|string|max:40',
            'permissions.*.can_view' => 'required|boolean',
            'permissions.*.can_create' => 'required|boolean',
            'permissions.*.can_update' => 'required|boolean',
            'permissions.*.can_delete' => 'required|boolean',
            'permissions.*.can_export' => 'required|boolean',
            'permissions.*.can_approve' => 'required|boolean',
        ];

        if (!$isUpdate) {
            $rules = ['code' => 'required|string|max:40'] + $rules;
        }

        return $rules;
    }
}