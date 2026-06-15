<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Sales\SalesLookupApplicationService;
use Illuminate\Http\Request;

class SalesLookupController extends Controller
{
    public function __construct(
        private SalesLookupApplicationService $salesLookupApplicationService
    ) {
    }

    public function bootstrap(Request $request)
    {
        $result = $this->salesLookupApplicationService->bootstrap(
            $request,
            fn (Request $incomingRequest) => $this->salesLookupApplicationService->buildLookupsPayload($incomingRequest)
        );

        if (isset($result['lookups']['error'])) {
            return $result['lookups']['error'];
        }

        return response()->json($result);
    }

    public function lookups(Request $request)
    {
        $result = $this->salesLookupApplicationService->buildLookupsPayload($request);

        if (isset($result['error'])) {
            return $result['error'];
        }

        return response()->json($result);
    }

    public function priceTiers(Request $request)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');

        return response()->json(['data' => $this->salesLookupApplicationService->listPriceTiers($companyId)]);
    }

    public function referenceDocuments(Request $request)
    {
        $result = $this->salesLookupApplicationService->resolveReferenceDocuments($request);
        return response()->json($result['body'], (int) $result['status']);
    }

    public function seriesNumbers(Request $request)
    {
        $result = $this->salesLookupApplicationService->resolveSeriesNumbers($request);
        return response()->json($result['body'], (int) $result['status']);
    }

    public function topProducts(Request $request)
    {
        $result = $this->salesLookupApplicationService->resolveTopProducts($request);
        return response()->json($result['body'], (int) $result['status']);
    }
}
