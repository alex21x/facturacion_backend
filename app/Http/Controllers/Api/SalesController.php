<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\CreateCustomerVehicleRequest;
use App\Http\Requests\Sales\UpdateCustomerVehicleRequest;
use App\Services\Sales\SalesCustomerApplicationService;
use App\Services\Sales\SalesLookupApplicationService;
use Illuminate\Http\Request;

class SalesController extends Controller
{
    public function __construct(
        private SalesCustomerApplicationService $salesCustomerApplicationService,
        private SalesLookupApplicationService $salesLookupApplicationService
    ) {
    }

    public function bootstrap(Request $request)
    {
        $lookupsResponse = $this->lookups($request);
        if ($lookupsResponse->getStatusCode() >= 400) {
            return $lookupsResponse;
        }

        $lookupsPayload = $lookupsResponse->getData(true);

        return response()->json([
            'lookups' => $lookupsPayload,
            'documents' => null,
        ]);
    }

    public function lookups(Request $request)
    {
        $result = $this->salesLookupApplicationService->buildLookupsPayload($request);

        if (isset($result['error'])) {
            return $result['error'];
        }

        return response()->json($result);
    }

    public function customerAutocomplete(Request $request)
    {
        return response()->json(['data' => $this->salesCustomerApplicationService->listCustomers($request, true)]);
    }

    public function customers(Request $request)
    {
        return response()->json(['data' => $this->salesCustomerApplicationService->listCustomers($request, false)]);
    }

    public function customerVehicles(Request $request, int $id)
    {
        return response()->json(['data' => $this->salesCustomerApplicationService->listCustomerVehicles($request, $id)]);
    }

    public function createCustomerVehicle(CreateCustomerVehicleRequest $request, int $id)
    {
        $result = $this->salesCustomerApplicationService->createCustomerVehicle($request, $id);

        return response()->json($result['body'], (int) $result['status']);
    }

    public function updateCustomerVehicle(UpdateCustomerVehicleRequest $request, int $id, int $vehicleId)
    {
        $result = $this->salesCustomerApplicationService->updateCustomerVehicle($request, $id, $vehicleId);

        return response()->json($result['body'], (int) $result['status']);
    }

    public function deleteCustomerVehicle(Request $request, int $id, int $vehicleId)
    {
        $result = $this->salesCustomerApplicationService->deleteCustomerVehicle($request, $id, $vehicleId);

        return response()->json($result['body'], (int) $result['status']);
    }
}