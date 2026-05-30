<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\IndexReportRequest;
use App\Http\Requests\Reports\StoreReportRequest;
use App\Support\Reports\ReportRequestService;
use Illuminate\Http\Request;

class ReportsController extends Controller
{
    public function __construct(private ReportRequestService $reportRequestService)
    {
    }

    public function catalog(Request $request)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');

        return response()->json([
            'data' => $this->reportRequestService->availableCatalog(),
        ], 200);
    }

    public function index(IndexReportRequest $request)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');

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
        $companyId = (int) $request->attributes->get('resolved_company_id');

        $row = $this->reportRequestService->showRequest($companyId, $id);
        if ($row === null) {
            return response()->json(['message' => 'Report request not found'], 404);
        }

        return response()->json($row, 200);
    }

    public function store(StoreReportRequest $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->attributes->get('resolved_company_id');

        $payload = $request->validated();
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
