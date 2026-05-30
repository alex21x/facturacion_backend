<?php

namespace App\Http\Requests\Racing;

use App\Http\Requests\Api\ApiFormRequest;

class CreateInventoryAssignmentRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'assignment_type' => 'required|string|in:VEHICLE,EVENT',
            'vehicle_id' => 'nullable|integer|min:1',
            'event_id' => 'nullable|integer|min:1',
            'warehouse_id' => 'nullable|integer|min:1',
            'product_id' => 'nullable|integer|min:1',
            'inventory_ledger_id' => 'nullable|integer|min:1',
            'quantity' => 'required|numeric|not_in:0',
            'assigned_at' => 'nullable|date',
            'notes' => 'nullable|string|max:5000',
        ];
    }
}
