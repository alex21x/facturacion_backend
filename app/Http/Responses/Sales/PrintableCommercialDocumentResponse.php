<?php

namespace App\Http\Responses\Sales;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PrintableCommercialDocumentResponse implements Responsable
{
    private function __construct(
        private bool $isHtml,
        private ?string $html,
        private ?string $message,
        private int $status
    ) {
    }

    public static function html(string $html): self
    {
        return new self(true, $html, null, 200);
    }

    public static function error(string $message, int $status): self
    {
        return new self(false, null, $message, $status);
    }

    public function toResponse($request): Response|JsonResponse
    {
        if ($this->isHtml) {
            return response((string) $this->html, $this->status)
                ->header('Content-Type', 'text/html; charset=UTF-8');
        }

        return response()->json([
            'message' => (string) $this->message,
        ], $this->status);
    }
}
