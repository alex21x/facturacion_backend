<?php

namespace App\Http\Requests\SunatExceptions;

use App\Http\Requests\Api\ApiFirstErrorFormRequest;

class ManualConfirmSunatExceptionRequest extends ApiFirstErrorFormRequest
{
    public function rules(): array
    {
        return [
            'resolution' => 'required|string|in:ACCEPTED,REJECTED',
            'evidence_type' => 'required|string|in:TICKET,CDR,OBSERVATION,WHATSAPP,EMAIL,OTHER',
            'evidence_ref' => 'nullable|string|max:500',
            'evidence_note' => 'nullable|string|max:1000',
        ];
    }
}