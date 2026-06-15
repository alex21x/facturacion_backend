<?php

namespace App\Http\Responses\Sales;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CommercialDocumentExportResponseFactory
{
    public function json(array $export): JsonResponse
    {
        return response()->json([
            'data' => $export['json_rows'],
            'meta' => [
                'count' => $export['count'],
                'max' => $export['max'],
                'detail' => $export['mode'],
            ],
        ]);
    }

    public function csv(array $export): StreamedResponse
    {
        return response()->streamDownload(function () use ($export) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $export['headers'], ';');

            foreach ($export['rows'] as $row) {
                fputcsv($out, $row, ';');
            }

            fclose($out);
        }, $export['filename'], [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
