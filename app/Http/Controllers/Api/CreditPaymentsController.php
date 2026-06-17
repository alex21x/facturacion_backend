<?php

namespace App\Http\Controllers\Api;

use App\Application\UseCases\Finance\BuildCreditPaymentTicketUseCase;
use App\Application\UseCases\Finance\DeleteCustomerCreditPaymentUseCase;
use App\Application\UseCases\Finance\DeleteSupplierCreditPaymentUseCase;
use App\Application\UseCases\Finance\GetCustomerCreditPaymentsDetailUseCase;
use App\Application\UseCases\Finance\GetSupplierCreditPaymentsDetailUseCase;
use App\Application\UseCases\Finance\PaginateCustomerCreditDocumentsUseCase;
use App\Application\UseCases\Finance\PaginateSupplierCreditDocumentsUseCase;
use App\Application\UseCases\Finance\UpsertCustomerCreditPaymentUseCase;
use App\Application\UseCases\Finance\UpsertSupplierCreditPaymentUseCase;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class CreditPaymentsController extends Controller
{
    public function __construct(
        private PaginateCustomerCreditDocumentsUseCase $paginateCustomerCreditDocumentsUseCase,
        private PaginateSupplierCreditDocumentsUseCase $paginateSupplierCreditDocumentsUseCase,
        private GetCustomerCreditPaymentsDetailUseCase $getCustomerCreditPaymentsDetailUseCase,
        private GetSupplierCreditPaymentsDetailUseCase $getSupplierCreditPaymentsDetailUseCase,
        private UpsertCustomerCreditPaymentUseCase $upsertCustomerCreditPaymentUseCase,
        private UpsertSupplierCreditPaymentUseCase $upsertSupplierCreditPaymentUseCase,
        private DeleteCustomerCreditPaymentUseCase $deleteCustomerCreditPaymentUseCase,
        private DeleteSupplierCreditPaymentUseCase $deleteSupplierCreditPaymentUseCase,
        private BuildCreditPaymentTicketUseCase $buildCreditPaymentTicketUseCase
    ) {
    }

    public function customerDocuments(Request $request)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');
        $authUser = $request->attributes->get('auth_user');

        $page = max(1, (int) $request->query('page', 1));
        $perPage = max(1, min(100, (int) $request->query('per_page', 20)));
        $branchIdRaw = $request->query('branch_id', $authUser->branch_id ?? null);
        $branchId = ($branchIdRaw !== null && $branchIdRaw !== '') ? (int) $branchIdRaw : null;
        $status = strtoupper(trim((string) $request->query('payment_status', '')));
        $status = in_array($status, ['PENDING', 'CANCELED'], true) ? $status : null;

        $result = $this->paginateCustomerCreditDocumentsUseCase->execute(
            $companyId,
            $branchId,
            $page,
            $perPage,
            $request->query('search'),
            $status
        );

        return response()->json($result);
    }

    public function supplierDocuments(Request $request)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');
        $authUser = $request->attributes->get('auth_user');

        $page = max(1, (int) $request->query('page', 1));
        $perPage = max(1, min(100, (int) $request->query('per_page', 20)));
        $branchIdRaw = $request->query('branch_id', $authUser->branch_id ?? null);
        $branchId = ($branchIdRaw !== null && $branchIdRaw !== '') ? (int) $branchIdRaw : null;
        $status = strtoupper(trim((string) $request->query('payment_status', '')));
        $status = in_array($status, ['PENDING', 'CANCELED'], true) ? $status : null;

        $result = $this->paginateSupplierCreditDocumentsUseCase->execute(
            $companyId,
            $branchId,
            $page,
            $perPage,
            $request->query('search'),
            $status
        );

        return response()->json($result);
    }

    public function listCustomerPayments(Request $request, int $id)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');

        try {
            return response()->json($this->getCustomerCreditPaymentsDetailUseCase->execute($companyId, $id));
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], $this->resolveHttpStatus($e->getCode()));
        }
    }

    public function createCustomerPayment(Request $request, int $id)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');
        $authUser = $request->attributes->get('auth_user');

        $payload = $request->validate([
            'payment_method_id' => ['nullable', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'status' => ['nullable', 'in:PENDING,PAID,CANCELED'],
            'paid_at' => ['nullable', 'date'],
            'due_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:300'],
        ]);

        try {
            return response()->json($this->upsertCustomerCreditPaymentUseCase->execute($authUser, $companyId, $id, $payload));
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], $this->resolveHttpStatus($e->getCode()));
        }
    }

    public function updateCustomerPayment(Request $request, int $id, int $paymentId)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');
        $authUser = $request->attributes->get('auth_user');

        $payload = $request->validate([
            'payment_method_id' => ['nullable', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'status' => ['nullable', 'in:PENDING,PAID,CANCELED'],
            'paid_at' => ['nullable', 'date'],
            'due_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:300'],
        ]);

        try {
            return response()->json($this->upsertCustomerCreditPaymentUseCase->execute($authUser, $companyId, $id, $payload, $paymentId));
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], $this->resolveHttpStatus($e->getCode()));
        }
    }

    public function deleteCustomerPayment(Request $request, int $id, int $paymentId)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');

        try {
            return response()->json($this->deleteCustomerCreditPaymentUseCase->execute($companyId, $id, $paymentId));
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], $this->resolveHttpStatus($e->getCode()));
        }
    }

    public function customerPaymentTicket(Request $request, int $id, int $paymentId)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');

        try {
            $payload = $this->getCustomerCreditPaymentsDetailUseCase->execute($companyId, $id);
            $payment = collect($payload['payments'] ?? [])->firstWhere('id', $paymentId);
            if (!$payment) {
                return response()->json(['message' => 'Pago no encontrado'], 404);
            }
            $html = $this->buildCreditPaymentTicketUseCase->execute('Cobro de Cliente', $payload['document'], $payment);
            return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], $this->resolveHttpStatus($e->getCode()));
        }
    }

    public function listSupplierPayments(Request $request, int $id)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');

        try {
            return response()->json($this->getSupplierCreditPaymentsDetailUseCase->execute($companyId, $id));
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], $this->resolveHttpStatus($e->getCode()));
        }
    }

    public function createSupplierPayment(Request $request, int $id)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');
        $authUser = $request->attributes->get('auth_user');

        $payload = $request->validate([
            'payment_method_id' => ['nullable', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'status' => ['nullable', 'in:PENDING,PAID,CANCELED'],
            'paid_at' => ['nullable', 'date'],
            'due_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:300'],
        ]);

        try {
            return response()->json($this->upsertSupplierCreditPaymentUseCase->execute($authUser, $companyId, $id, $payload));
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], $this->resolveHttpStatus($e->getCode()));
        }
    }

    public function updateSupplierPayment(Request $request, int $id, int $paymentId)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');
        $authUser = $request->attributes->get('auth_user');

        $payload = $request->validate([
            'payment_method_id' => ['nullable', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'status' => ['nullable', 'in:PENDING,PAID,CANCELED'],
            'paid_at' => ['nullable', 'date'],
            'due_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:300'],
        ]);

        try {
            return response()->json($this->upsertSupplierCreditPaymentUseCase->execute($authUser, $companyId, $id, $payload, $paymentId));
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], $this->resolveHttpStatus($e->getCode()));
        }
    }

    public function deleteSupplierPayment(Request $request, int $id, int $paymentId)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');

        try {
            return response()->json($this->deleteSupplierCreditPaymentUseCase->execute($companyId, $id, $paymentId));
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], $this->resolveHttpStatus($e->getCode()));
        }
    }

    public function supplierPaymentTicket(Request $request, int $id, int $paymentId)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');

        try {
            $payload = $this->getSupplierCreditPaymentsDetailUseCase->execute($companyId, $id);
            $payment = collect($payload['payments'] ?? [])->firstWhere('id', $paymentId);
            if (!$payment) {
                return response()->json(['message' => 'Pago no encontrado'], 404);
            }
            $html = $this->buildCreditPaymentTicketUseCase->execute('Pago a Proveedor', $payload['document'], $payment);
            return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], $this->resolveHttpStatus($e->getCode()));
        }
    }

    private function resolveHttpStatus(mixed $rawCode): int
    {
        $code = (int) $rawCode;
        return ($code >= 400 && $code <= 599) ? $code : 422;
    }
}
