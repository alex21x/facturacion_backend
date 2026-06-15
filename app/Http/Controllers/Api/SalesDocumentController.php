<?php

namespace App\Http\Controllers\Api;

use App\Contracts\Sales\SalesDocumentApplicationServiceInterface;
use App\Contracts\TaxBridgeGateway;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\ConvertCommercialDocumentRequest;
use App\Http\Requests\Sales\CreateCommercialDocumentRequest;
use App\Http\Requests\Sales\PrintableCommercialDocumentRequest;
use App\Http\Requests\Sales\SunatVoidCommunicationRequest;
use App\Http\Requests\Sales\UpdateCommercialDocumentRequest;
use App\Http\Requests\Sales\VoidCommercialDocumentRequest;
use App\Http\Responses\Sales\CommercialDocumentExportResponseFactory;
use App\Http\Responses\Sales\PrintableCommercialDocumentResponse;
use App\Services\Sales\Documents\SalesDocumentException;
use App\Services\Sales\Presenters\TaxBridgeResponsePresenter;
use App\Services\Sales\TaxBridge\TaxBridgeException;
use Illuminate\Http\Request;
use Throwable;

class SalesDocumentController extends Controller
{
    public function __construct(
        private TaxBridgeGateway $taxBridgeService,
        private SalesDocumentApplicationServiceInterface $salesDocumentApplicationService,
        private CommercialDocumentExportResponseFactory $exportResponseFactory,
        private TaxBridgeResponsePresenter $taxBridgeResponsePresenter
    )
    {
    }

    public function commercialDocuments(Request $request)
    {
        $authUser  = $request->attributes->get('auth_user');
        $companyId = (int) $request->attributes->get('resolved_company_id');
        $page      = max(1, (int) $request->query('page', 1));
        $limit     = max(1, min(200, (int) ($request->query('per_page', $request->query('limit', 10)))));

        $result = $this->salesDocumentApplicationService->paginateCommercialDocuments(
            $authUser,
            $companyId,
            $request->query(),
            $page,
            $limit
        );

        return response()->json($result);
    }

    public function exportCommercialDocuments(Request $request)
    {
        $authUser   = $request->attributes->get('auth_user');
        $companyId  = (int) $request->attributes->get('resolved_company_id');
        $format     = strtolower(trim((string) $request->query('format', 'csv')));
        $detailMode = strtoupper(trim((string) $request->query('detail', 'SUMMARY')));
        $max        = max(1, min(20000, (int) $request->query('max', 5000)));

        $export = $this->salesDocumentApplicationService->exportCommercialDocumentsData(
            $authUser, $companyId, $request->query(), $detailMode, $max
        );

        if ($format === 'json') {
            return $this->exportResponseFactory->json($export);
        }

        return $this->exportResponseFactory->csv($export);
    }

    public function showCommercialDocument(Request $request, int $id)
    {
        $companyId = (int) ($request->attributes->get('resolved_company_id') ?? $request->query('company_id', 0));

        try {
            $data = $this->salesDocumentApplicationService->buildCommercialDocumentDetail($companyId, $id);
        } catch (SalesDocumentException $e) {
            return response()->json(['message' => $e->getMessage()], $e->httpStatus());
        }

        return response()->json(['data' => $data]);
    }

    public function printableCommercialDocument(PrintableCommercialDocumentRequest $request, int $id)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');
        $format    = (string) $request->validated('format');

        try {
            $html = $this->salesDocumentApplicationService->buildPrintableCommercialDocumentHtml($companyId, (int) $id, $format);
        } catch (SalesDocumentException $e) {
            return PrintableCommercialDocumentResponse::error($e->getMessage(), $e->httpStatus());
        } catch (Throwable $e) {
            return PrintableCommercialDocumentResponse::error('No se pudo generar la impresion del documento', 500);
        }

        return PrintableCommercialDocumentResponse::html($html);
    }

    public function printableCommercialDocumentPdf(Request $request, int $id)
    {
        $companyId      = (int) $request->attributes->get('resolved_company_id');
        $format         = in_array($request->query('format'), ['ticket', 'a4'], true) ? (string) $request->query('format') : 'a4';
        $isPublicPdfLink = (bool) $request->attributes->get('is_public_pdf_link', false);

        try {
            $result = $this->salesDocumentApplicationService->buildCommercialDocumentPdfBinary(
                $companyId, $id, $format, $isPublicPdfLink
            );
        } catch (SalesDocumentException $e) {
            return response()->json(['message' => $e->getMessage()], $e->httpStatus());
        }

        return response($result['binary'], 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $result['filename'] . '"',
            'Cache-Control'       => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma'              => 'no-cache',
            'Expires'             => '0',
        ]);
    }

    public function commercialDocumentShareLink(Request $request, int $id)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');
        $format    = strtolower(trim((string) $request->query('format', 'a4')));

        $isHttpsContext = $request->isSecure()
            || strtolower(trim((string) $request->header('x-forwarded-proto', ''))) === 'https';

        $result = $this->salesDocumentApplicationService->generateCommercialDocumentShareLink(
            $companyId, $id, $format, $isHttpsContext
        );

        return response()->json(['data' => $result]);
    }

    public function sendCommercialDocumentShareEmail(Request $request, int $id)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');
        if ($companyId <= 0) {
            return response()->json(['message' => 'No se pudo resolver la empresa para el envío.'], 422);
        }

        $validated = $request->validate([
            'to_email' => ['required', 'email', 'max:190'],
            'subject'  => ['nullable', 'string', 'max:200'],
            'message'  => ['nullable', 'string', 'max:4000'],
            'format'   => ['nullable', 'in:a4,ticket'],
        ]);

        $isHttpsContext = $request->isSecure()
            || strtolower(trim((string) $request->header('x-forwarded-proto', ''))) === 'https';

        try {
            $result = $this->salesDocumentApplicationService->sendCommercialDocumentShareEmail(
                $companyId, $id, $validated, $isHttpsContext
            );
        } catch (SalesDocumentException $e) {
            return response()->json(['message' => $e->getMessage()], $e->httpStatus());
        } catch (Throwable $e) {
            return response()->json(['message' => 'No se pudo enviar el correo de compartido.', 'error' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Correo enviado correctamente.', 'data' => $result]);
    }

    public function publicPrintableCommercialDocumentPdf(Request $request, int $id)
    {
        $companyId = (int) $request->query('company_id', 0);
        if ($companyId <= 0) {
            return response()->json(['message' => 'company_id inválido para enlace público.'], 422);
        }

        $request->attributes->set('resolved_company_id', $companyId);
        $request->attributes->set('is_public_pdf_link', true);

        return $this->printableCommercialDocumentPdf($request, $id);
    }

    public function previewTaxBridgePayload(Request $request, int $id)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');

        try {
            $preview = $this->taxBridgeService->preview($companyId, $id);

            return response()->json([
                'message'     => 'Tax bridge payload preview generated successfully',
                'document_id' => $id,
                'bridge_mode' => $preview['bridge_mode'],
                'endpoint'    => $preview['endpoint'],
                'method'      => $preview['method'],
                'content_type' => $preview['content_type'],
                'form_key'    => $preview['form_key'],
                'payload'     => $preview['payload'],
                'debug'       => $preview,
            ], 200);
        } catch (TaxBridgeException $e) {
            return response()->json(['message' => $e->getMessage()], $e->httpStatus());
        }
    }

    public function taxBridgeDebug(Request $request, int $id)
    {
        $authUser  = $request->attributes->get('auth_user');
        $companyId = (int) $request->attributes->get('resolved_company_id');

        try {
            $result = $this->salesDocumentApplicationService->getTaxBridgeDebug($authUser, $companyId, $id);
        } catch (SalesDocumentException $e) {
            return response()->json(['message' => $e->getMessage()], $e->httpStatus());
        }

        return response()->json([
            'message'     => 'Tax bridge debug loaded',
            'document_id' => $result['document_id'],
            'debug'       => $result['debug'],
        ]);
    }

    public function downloadSunatXml(Request $request, int $id)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');

        try {
            $result = $this->taxBridgeService->downloadDocument($companyId, $id, 'dowload_xml');

            return response($result['body'], 200)
                ->header('Content-Type', $result['content_type'])
                ->header('Content-Disposition', 'attachment; filename="' . addslashes($result['filename']) . '"')
                ->header('X-Bridge-Endpoint', $result['endpoint'])
                ->header('X-Bridge-Method', 'GET')
                ->header('X-Bridge-Http-Status', (string) ($result['http_status'] ?? 200))
                ->header('X-Bridge-Content-Type', (string) ($result['bridge_content_type'] ?? ''));
        } catch (TaxBridgeException $e) {
            return response()->json(['message' => $e->getMessage()], $e->httpStatus());
        } catch (Throwable $e) {
            return response()->json(['message' => 'Error al descargar XML: ' . $e->getMessage()], 500);
        }
    }

    public function downloadSunatCdr(Request $request, int $id)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');

        try {
            $result = $this->taxBridgeService->downloadDocument($companyId, $id, 'dowload_cdr');

            return response($result['body'], 200)
                ->header('Content-Type', $result['content_type'])
                ->header('Content-Disposition', 'attachment; filename="' . addslashes($result['filename']) . '"')
                ->header('X-Bridge-Endpoint', $result['endpoint'])
                ->header('X-Bridge-Method', 'GET')
                ->header('X-Bridge-Http-Status', (string) ($result['http_status'] ?? 200))
                ->header('X-Bridge-Content-Type', (string) ($result['bridge_content_type'] ?? ''));
        } catch (TaxBridgeException $e) {
            return response()->json(['message' => $e->getMessage()], $e->httpStatus());
        } catch (Throwable $e) {
            return response()->json(['message' => 'Error al descargar CDR: ' . $e->getMessage()], 500);
        }
    }

    public function createCommercialDocument(CreateCommercialDocumentRequest $request)
    {
        $authUser = $request->attributes->get('auth_user');
        $payload = $request->validated();
        $companyId = (int) $request->attributes->get('resolved_company_id');
        $branchIdRaw = $request->query('branch_id', $request->input('branch_id', $authUser->branch_id ?? null));
        $branchId = ($branchIdRaw !== null && $branchIdRaw !== '') ? (int) $branchIdRaw : null;

        try {
            $result = $this->salesDocumentApplicationService->createCommercialDocument(
                $authUser,
                $companyId,
                $payload,
                $branchId
            );
        } catch (SalesDocumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], $e->httpStatus());
        }

        return response()->json([
            'message' => 'Commercial document created',
            'data' => $result,
        ], 201);
    }

    public function convertCommercialDocument(ConvertCommercialDocumentRequest $request, int $id)
    {
        $authUser = $request->attributes->get('auth_user');
        $payload = $request->validated();
        $companyId = (int) $request->attributes->get('resolved_company_id');

        try {
            $result = $this->salesDocumentApplicationService->convertCommercialDocument(
                $authUser,
                $companyId,
                (int) $id,
                $payload
            );
        } catch (SalesDocumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], $e->httpStatus());
        }

        return response()->json([
            'message' => 'Commercial document created',
            'data' => $result,
        ], 201);
    }

    public function updateCommercialDocument(UpdateCommercialDocumentRequest $request, int $id)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->attributes->get('resolved_company_id');
        $documentId = (int) $id;
        $payload = $request->validated();

        try {
            $result = $this->salesDocumentApplicationService->updateCommercialDocument(
                $authUser,
                $companyId,
                $documentId,
                $payload
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
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->attributes->get('resolved_company_id');
        $documentId = (int) $id;
        $payload = $request->validated();

        try {
            $result = $this->salesDocumentApplicationService->voidCommercialDocument(
                $authUser,
                $companyId,
                $documentId,
                $payload
            );
        } catch (SalesDocumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], $e->httpStatus());
        }

        return response()->json([
            'message' => 'Documento comercial anulado',
            'data' => $result,
        ]);
    }

    public function retryTaxBridgeSend(Request $request, int $id)
    {
        $companyId = (int) $request->attributes->get('resolved_company_id');

        try {
            $result = $this->taxBridgeService->retry($companyId, $id);
            $diagnostic = $this->taxBridgeService->summarizeBridgeDiagnostic($result['response'] ?? null);
            $presented = $this->taxBridgeResponsePresenter->presentRetryResult($result, $id, $diagnostic);

            return response()->json($presented['payload'], $presented['status']);
        } catch (TaxBridgeException $e) {
            $debug = $this->taxBridgeService->getLastDispatchDebug($companyId, $id);

            return response()->json([
                'message' => $e->getMessage(),
                'debug' => $debug,
            ], $e->httpStatus());
        }
    }

    public function sunatVoidCommunication(SunatVoidCommunicationRequest $request, int $id)
    {
        $authUser = $request->attributes->get('auth_user');
        $companyId = (int) $request->attributes->get('resolved_company_id');

        $payload = $request->validated();

        try {
            $result = $this->taxBridgeService->sendVoidCommunication($companyId, $id, $payload['reason'] ?? null);
            $diagnostic = $this->taxBridgeService->summarizeBridgeDiagnostic($result['response'] ?? null);

            if (($result['status'] ?? '') === 'ACCEPTED') {
                $this->salesDocumentApplicationService->applyAcceptedSunatVoid(
                    $authUser,
                    $companyId,
                    $id,
                    isset($payload['reason']) ? (string) $payload['reason'] : null,
                    isset($payload['notes']) ? (string) $payload['notes'] : null
                );
            }

            $payload = $this->taxBridgeResponsePresenter->presentSunatVoidResult($result, $id, $diagnostic);

            return response()->json($payload, 200);
        } catch (SalesDocumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], $e->httpStatus());
        } catch (TaxBridgeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], $e->httpStatus());
        }
    }
}
