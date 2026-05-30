<?php

namespace App\Http\Requests\MasterData;

class SeriesRequest extends MasterDataFormRequest
{
    public function rules(): array
    {
        $isUpdate = $this->isUpdateRequest();

        return [
            'branch_id' => 'nullable|integer|min:1',
            'warehouse_id' => 'nullable|integer|min:1',
            'document_kind' => $this->documentKindRule(!$isUpdate),
            'series' => $isUpdate ? 'nullable|string|max:10' : 'required|string|max:10',
            'current_number' => 'nullable|integer|min:0',
            'number_padding' => 'nullable|integer|min:4|max:12',
            'reset_policy' => 'nullable|string|in:NONE,YEARLY,MONTHLY',
            'is_enabled' => 'nullable|boolean',
        ];
    }
}