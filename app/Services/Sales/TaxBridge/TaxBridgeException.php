<?php

namespace App\Services\Sales\TaxBridge;

use RuntimeException;

class TaxBridgeException extends RuntimeException
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
