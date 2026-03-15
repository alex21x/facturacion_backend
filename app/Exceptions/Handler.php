<?php

namespace App\Exceptions;

use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that are not reported.
     *
     * @var array
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
     *
     * @var array
     */
    protected $dontFlash = [
        'password',
        'password_confirmation',
    ];

    /**
     * Report or log an exception.
     *
     * @param  \Throwable  $exception
     * @return void
     *
     * @throws \Throwable
     */
    public function report(Throwable $exception): void
    {
        parent::report($exception);
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Throwable  $exception
     * @return \Symfony\Component\HttpFoundation\Response
     *
     * @throws \Throwable
     */
    public function render($request, Throwable $exception): Response
    {
        $response = parent::render($request, $exception);

        if ($request->is('api/*')) {
            $contentType = (string) $response->headers->get('Content-Type', '');
            if (stripos($contentType, 'application/json') !== false) {
                return $response;
            }

            $status = method_exists($response, 'getStatusCode') ? $response->getStatusCode() : 500;
            $message = $status >= 500
                ? (config('app.debug') ? $exception->getMessage() : 'Server Error')
                : ($response->statusText() ?: 'Request failed');

            return response()->json([
                'message' => $message,
            ], $status);
        }

        return $response;
    }
}
