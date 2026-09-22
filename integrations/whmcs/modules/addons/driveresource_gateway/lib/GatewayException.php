<?php

namespace WHMCS\Module\Addon\Driveresource_gateway;

use RuntimeException;

/**
 * Safe gateway exception with an HTTP status.
 */
final class GatewayException extends RuntimeException
{
    private int $httpStatus;

    public function __construct(string $message, int $httpStatus = 400)
    {
        parent::__construct($message);
        $this->httpStatus = max(400, min(599, $httpStatus));
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}
