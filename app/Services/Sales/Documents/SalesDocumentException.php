<?php

namespace App\Services\Sales\Documents;

use RuntimeException;

class SalesDocumentException extends RuntimeException
{
    public function __construct(string $message, private int $httpStatus = 422)
    {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}
