<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\BulkImportCustomersRequest;
use App\Http\Requests\Sales\CreateCustomerRequest;
use App\Http\Requests\Sales\CreateCustomerVehicleRequest;
use App\Http\Requests\Sales\UpdateCustomerRequest;
use App\Http\Requests\Sales\UpdateCustomerVehicleRequest;
use App\Services\Sales\CustomerManagementService;
use App\Services\Sales\CustomerVehicleService;
use App\Services\Sales\SalesLookupService;
use Illuminate\Http\Request;

class SalesCustomerController extends Controller
{
    public function __construct(
        private SalesController $salesController,
        private CustomerManagementService $customerManagementService,
        private CustomerVehicleService $customerVehicleService,
        private SalesLookupService $salesLookupService
    )
    {
    }

    public function customerTypes(Request $request)
    {
        $rows = $this->customerVehicleService->listCustomerTypes();

        return response()->json(['data' => $rows]);
    }

    public function customers(Request $request)
    {
        return $this->salesController->customers($request);
    }

    public function customerVehicles(Request $request, int $id)
    {
        return $this->salesController->customerVehicles($request, $id);
    }

    public function customerAutocomplete(Request $request)
    {
        return $this->salesController->customerAutocomplete($request);
    }

    public function resolveCustomerByDocument(Request $request)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');

        $document = preg_replace('/\D+/', '', (string) $request->query('document', ''));
        if (!is_string($document)) {
            $document = '';
        }

        if ($document === '' || !in_array(strlen($document), [8, 11], true)) {
            return response()->json([
                'message' => 'Debe enviar un DNI (8) o RUC (11) valido.',
            ], 422);
        }

        $result = $this->customerManagementService->resolveCustomerByDocument($companyId, $document);

        return response()->json($result['body'], (int) $result['status']);
    }

    public function createCustomer(CreateCustomerRequest $request)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');

        $this->salesLookupService->ensureCustomersPhoneColumn();

        $result = $this->customerManagementService->createCustomer($companyId, $request->validated());

        return response()->json($result['body'], (int) $result['status']);
    }

    public function bulkImportCustomers(BulkImportCustomersRequest $request)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');

        $this->salesLookupService->ensureCustomersPhoneColumn();

        $result = $this->customerManagementService->bulkImportCustomers($companyId, $request->validated()['rows']);

        return response()->json($result);
    }

    public function updateCustomer(UpdateCustomerRequest $request, int $id)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');

        $this->salesLookupService->ensureCustomersPhoneColumn();

        $result = $this->customerManagementService->updateCustomer($companyId, $id, $request->validated());

        return response()->json($result['body'], (int) $result['status']);
    }

    public function createCustomerVehicle(CreateCustomerVehicleRequest $request, int $id)
    {
        return $this->salesController->createCustomerVehicle($request, $id);
    }

    public function updateCustomerVehicle(UpdateCustomerVehicleRequest $request, int $id, int $vehicleId)
    {
        return $this->salesController->updateCustomerVehicle($request, $id, $vehicleId);
    }

    public function deleteCustomerVehicle(Request $request, int $id, int $vehicleId)
    {
        return $this->salesController->deleteCustomerVehicle($request, $id, $vehicleId);
    }
}
