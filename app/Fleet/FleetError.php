<?php

namespace App\Fleet;

use RuntimeException;

class FleetError extends RuntimeException
{
    public function __construct(public string $errorCode, string $message, public int $httpStatus = 409)
    {
        parent::__construct($message);
    }
}
