<?php

namespace App\Http\Requests\Restaurant;

use App\Http\Requests\Api\ApiFormRequest;

class UpdateComandaStatusRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'status' => 'required|string|in:PENDING,IN_PREP,READY,SERVED,CANCELLED',
            'table_label' => 'nullable|string|max:80',
        ];
    }
}