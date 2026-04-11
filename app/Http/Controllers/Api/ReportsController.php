<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Reports\ReportRequestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ReportsController extends Controller
{
    public function __construct(private ReportRequestService $reportRequestService)
    {
    }

    public function catalog(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        return response()->json([
            'data' => $this->reportRequestService->availableCatalog(),
        ], 200);
    }

    public function index(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'nullable|string|in:PENDING,PROCESSING,COMPLETED,FAILED',
            'report_code' => 'nullable|string|max:80',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:5|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $this->reportRequestService->listRequests(
            $companyId,
            $request->query('status'),
            $request->query('report_code'),
            (int) $request->query('page', 1),
            (int) $request->query('per_page', 20)
        );

        return response()->json($data, 200);
    }

    public function show(Request $request, int $id)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->query('company_id', $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        $row = $this->reportRequestService->showRequest($companyId, $id);
        if ($row === null) {
            return response()->json(['message' => 'Report request not found'], 404);
        }

        return response()->json($row, 200);
    }

    public function store(Request $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) ($request->input('company_id') ?? $authUser->company_id);

        if ((int) $authUser->company_id !== $companyId) {
            return response()->json(['message' => 'Invalid company scope'], 403);
        }

        $validator = Validator::make($request->all(), [
            'report_code' => 'required|string|max:80',
            'branch_id' => 'nullable|integer',
            'filters' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $payload = $validator->validated();
        $reportCode = strtoupper(trim((string) $payload['report_code']));

        if (!$this->reportRequestService->isSupportedCode($reportCode)) {
            return response()->json(['message' => 'Unsupported report_code'], 422);
        }

        $requestId = $this->reportRequestService->createRequest(
            $companyId,
            isset($payload['branch_id']) ? (int) $payload['branch_id'] : null,
            (int) $authUser->id,
            $reportCode,
            is_array($payload['filters'] ?? null) ? $payload['filters'] : []
        );

        return response()->json([
            'message' => 'Reporte encolado para procesamiento',
            'request_id' => $requestId,
            'status' => 'PENDING',
        ], 202);
    }
}
