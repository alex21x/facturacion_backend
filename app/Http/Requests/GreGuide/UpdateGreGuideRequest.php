<?php

namespace App\Http\Requests\GreGuide;

use App\Http\Requests\Api\ApiFirstErrorFormRequest;

class UpdateGreGuideRequest extends ApiFirstErrorFormRequest
{
    public function rules(): array
    {
        return [
            'guide_type' => 'nullable|string|in:REMITENTE,TRANSPORTISTA',
            'issue_date' => 'nullable|date_format:Y-m-d',
            'transfer_date' => 'nullable|date_format:Y-m-d',
            'motivo_traslado' => 'nullable|string|max:4',
            'transport_mode_code' => 'nullable|string|in:01,02',
            'weight_kg' => 'nullable|numeric|gt:0',
            'packages_count' => 'nullable|integer|min:1|max:100000',
            'partida_ubigeo' => ['nullable', 'regex:/^\d{6}$/'],
            'punto_partida' => 'nullable|string|max:500',
            'llegada_ubigeo' => ['nullable', 'regex:/^\d{6}$/'],
            'punto_llegada' => 'nullable|string|max:500',
            'related_document_id' => 'nullable|integer|min:1',
            'notes' => 'nullable|string|max:1000',
            'destinatario' => 'nullable|array',
            'transporter' => 'nullable|array',
            'vehicle' => 'nullable|array',
            'driver' => 'nullable|array',
            'items' => 'nullable|array|min:1',
            'items.*.description' => 'required_with:items|string|max:500',
            'items.*.qty' => 'required_with:items|numeric|min:0.0001',
            'items.*.code' => 'nullable|string|max:100',
            'items.*.unit' => 'nullable|string|max:30',
        ];
    }
}
