<?php

namespace App\Http\Requests\GreGuide;

use App\Http\Requests\Api\ApiFirstErrorFormRequest;

class StoreGreGuideRequest extends ApiFirstErrorFormRequest
{
    public function rules(): array
    {
        return [
            'branch_id' => 'nullable|integer|min:1',
            'guide_type' => 'required|string|in:REMITENTE,TRANSPORTISTA',
            'series' => 'required|string|max:8',
            'issue_date' => 'required|date_format:Y-m-d',
            'transfer_date' => 'nullable|date_format:Y-m-d',
            'motivo_traslado' => 'required|string|max:4',
            'transport_mode_code' => 'required|string|in:01,02',
            'weight_kg' => 'required|numeric|gt:0',
            'packages_count' => 'required|integer|min:1|max:100000',
            'partida_ubigeo' => ['required', 'regex:/^\d{6}$/'],
            'punto_partida' => 'required|string|max:500',
            'llegada_ubigeo' => ['required', 'regex:/^\d{6}$/'],
            'punto_llegada' => 'required|string|max:500',
            'related_document_id' => 'nullable|integer|min:1',
            'notes' => 'nullable|string|max:1000',
            'destinatario' => 'required|array',
            'transporter' => 'nullable|array',
            'vehicle' => 'nullable|array',
            'driver' => 'nullable|array',
            'items' => 'required|array|min:1',
            'items.*.description' => 'required|string|max:500',
            'items.*.qty' => 'required|numeric|min:0.0001',
            'items.*.code' => 'nullable|string|max:100',
            'items.*.unit' => 'nullable|string|max:30',
        ];
    }
}
