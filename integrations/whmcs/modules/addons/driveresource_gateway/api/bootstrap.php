<?php

use WHMCS\Module\Addon\Driveresource_gateway\GatewayException;
use WHMCS\Module\Addon\Driveresource_gateway\GatewayService;
use WHMCS\Module\Addon\Driveresource_gateway\JsonResponse;
use WHMCS\Module\Addon\Driveresource_gateway\RequestAuthenticator;

define('CLIENTAREA', true);
require_once dirname(__DIR__, 4) . '/init.php';

/**
 * Execute one authenticated gateway endpoint.
 *
 * @param callable $handler Receives GatewayService and authenticated context.
 * @return never
 */
function driveresource_gateway_endpoint(callable $handler): never
{
    try {
        $rawBody = file_get_contents('php://input');
        if (!is_string($rawBody)) {
            throw new GatewayException('Unable to read request body.', 400);
        }

        $context = (new RequestAuthenticator())->authenticate($rawBody);
        $result = $handler(new GatewayService(), $context);
        JsonResponse::send(['ok' => true] + $result, 200);
    } catch (GatewayException $exception) {
        JsonResponse::send(['ok' => false, 'message' => $exception->getMessage()], $exception->httpStatus());
    } catch (Throwable $exception) {
        logModuleCall(
            'driveresource_gateway',
            'api',
            ['path' => $_SERVER['REQUEST_URI'] ?? ''],
            ['exception' => get_class($exception)],
            null,
            []
        );
        JsonResponse::send(['ok' => false, 'message' => 'Media gateway internal error.'], 500);
    }
}
