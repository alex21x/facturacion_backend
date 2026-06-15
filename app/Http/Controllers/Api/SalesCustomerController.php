<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\BulkImportCustomersRequest;
use App\Http\Requests\Sales\CreateCustomerRequest;
use App\Http\Requests\Sales\CreateCustomerVehicleRequest;
use App\Http\Requests\Sales\UpdateCustomerRequest;
use App\Http\Requests\Sales\UpdateCustomerVehicleRequest;
use App\Services\Sales\SalesCustomerApplicationService;
use Illuminate\Http\Request;

class SalesCustomerController extends Controller
{
    public function __construct(
        private SalesCustomerApplicationService $salesCustomerApplicationService
    ) {
    }

    public function customerTypes(Request $request)
    {
        return response()->json(['data' => $this->salesCustomerApplicationService->listCustomerTypes()]);
    }

    public function customers(Request $request)
    {
        return response()->json(['data' => $this->salesCustomerApplicationService->listCustomers($request, false)]);
    }

    public function customerVehicles(Request $request, int $id)
    {
        return response()->json(['data' => $this->salesCustomerApplicationService->listCustomerVehicles($request, $id)]);
    }

    public function customerAutocomplete(Request $request)
    {
        return response()->json(['data' => $this->salesCustomerApplicationService->listCustomers($request, true)]);
    }

    public function resolveCustomerByDocument(Request $request)
    {
        $result = $this->salesCustomerApplicationService->resolveCustomerByDocument($request);
        return response()->json($result['body'], (int) $result['status']);
    }

    public function createCustomer(CreateCustomerRequest $request)
    {
        $result = $this->salesCustomerApplicationService->createCustomer($request);
        return response()->json($result['body'], (int) $result['status']);
    }

    public function bulkImportCustomers(BulkImportCustomersRequest $request)
    {
        return response()->json($this->salesCustomerApplicationService->bulkImportCustomers($request));
    }

    public function updateCustomer(UpdateCustomerRequest $request, int $id)
    {
        $result = $this->salesCustomerApplicationService->updateCustomer($request, $id);
        return response()->json($result['body'], (int) $result['status']);
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
