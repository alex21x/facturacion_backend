<?php

namespace App\Http\Requests\Inventory;

use App\Http\Requests\Api\ApiFormRequest;

class UploadProductImageRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'image' => 'required|image|mimes:jpeg,jpg,png,webp|max:10240',
        ];
    }
}
