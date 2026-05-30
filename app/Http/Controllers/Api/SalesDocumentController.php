<?php

namespace App\Http\Controllers\Api;

use App\Application\UseCases\Sales\PrepareUpdateCommercialDocumentUseCase;
use App\Application\UseCases\Sales\UpdateCommercialDocumentDraftUseCase;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\ConvertCommercialDocumentRequest;
use App\Http\Requests\Sales\CreateCommercialDocumentRequest;
use App\Http\Requests\Sales\SunatVoidCommunicationRequest;
use App\Http\Requests\Sales\UpdateCommercialDocumentRequest;
use App\Http\Requests\Sales\VoidCommercialDocumentRequest;
use App\Services\Sales\Documents\SalesDocumentException;
use Illuminate\Http\Request;

class SalesDocumentController extends Controller
{
    public function __construct(
        private SalesController $salesController,
        private PrepareUpdateCommercialDocumentUseCase $prepareUpdateCommercialDocumentUseCase,
        private UpdateCommercialDocumentDraftUseCase $updateCommercialDocumentDraftUseCase
    )
    {
    }

    public function commercialDocuments(Request $request)
    {
        return $this->salesController->commercialDocuments($request);
    }

    public function exportCommercialDocuments(Request $request)
    {
        return $this->salesController->exportCommercialDocuments($request);
    }

    public function showCommercialDocument(Request $request, int $id)
    {
        return $this->salesController->showCommercialDocument($request, $id);
    }

    public function printableCommercialDocument(Request $request, int $id)
    {
        return $this->salesController->printableCommercialDocument($request, $id);
    }

    public function printableCommercialDocumentPdf(Request $request, int $id)
    {
        return $this->salesController->printableCommercialDocumentPdf($request, $id);
    }

    public function previewTaxBridgePayload(Request $request, int $id)
    {
        return $this->salesController->previewTaxBridgePayload($request, $id);
    }

    public function taxBridgeDebug(Request $request, int $id)
    {
        return $this->salesController->taxBridgeDebug($request, $id);
    }

    public function downloadSunatXml(Request $request, int $id)
    {
        return $this->salesController->downloadSunatXml($request, $id);
    }

    public function downloadSunatCdr(Request $request, int $id)
    {
        return $this->salesController->downloadSunatCdr($request, $id);
    }

    public function createCommercialDocument(CreateCommercialDocumentRequest $request)
    {
        return $this->salesController->createCommercialDocument($request);
    }

    public function convertCommercialDocument(ConvertCommercialDocumentRequest $request, int $id)
    {
        return $this->salesController->convertCommercialDocument($request, $id);
    }

    public function updateCommercialDocument(UpdateCommercialDocumentRequest $request, int $id)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->attributes->get('resolved_company_id');
        $documentId = (int) $id;
        $payload = $request->validated();

        try {
            $preparedPayload = $this->prepareUpdateCommercialDocumentUseCase->execute($payload);

            $result = $this->updateCommercialDocumentDraftUseCase->execute(
                $authUser,
                $companyId,
                $documentId,
                $preparedPayload
            );
        } catch (SalesDocumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], $e->httpStatus());
        }

        return response()->json([
            'message' => 'Documento comercial actualizado',
            'data' => $result,
        ]);
    }

    public function voidCommercialDocument(VoidCommercialDocumentRequest $request, int $id)
    {
        return $this->salesController->voidCommercialDocument($request, $id);
    }

    public function retryTaxBridgeSend(Request $request, int $id)
    {
        return $this->salesController->retryTaxBridgeSend($request, $id);
    }

    public function sunatVoidCommunication(SunatVoidCommunicationRequest $request, int $id)
    {
        return $this->salesController->sunatVoidCommunication($request, $id);
    }
}
