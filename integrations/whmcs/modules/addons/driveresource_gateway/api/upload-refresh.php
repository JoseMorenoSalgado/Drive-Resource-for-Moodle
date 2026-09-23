<?php

require_once __DIR__ . '/bootstrap.php';

driveresource_gateway_endpoint(static function ($gateway, array $context): array {
    return $gateway->refreshUploadAuthorization($context['service'], $context['payload']);
});
