<?php

namespace App\Http\Requests\Restaurant;

use App\Http\Requests\Api\ApiFormRequest;

class UpsertRecipeRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'notes' => 'nullable|string|max:300',
            'lines' => 'required|array|min:1',
            'lines.*.ingredient_product_id' => 'required|integer|min:1',
            'lines.*.qty_required_base' => 'required|numeric|min:0.00000001',
            'lines.*.wastage_percent' => 'nullable|numeric|min:0|max:100',
        ];
    }
}