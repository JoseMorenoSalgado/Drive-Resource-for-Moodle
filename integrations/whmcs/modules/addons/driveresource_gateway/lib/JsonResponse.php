<?php

namespace WHMCS\Module\Addon\Driveresource_gateway;

/**
 * JSON API response helper.
 */
final class JsonResponse
{
    /**
     * Send a JSON response and stop execution.
     *
     * @param array $payload Response payload.
     * @param int $status HTTP status.
     * @return never
     */
    public static function send(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }
}
