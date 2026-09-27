<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Module\Addon\DriveresourceGateway\GatewayMaintenance;

add_hook('DailyCronJob', 1, static function (): void {
    try {
        (new GatewayMaintenance())->run();
    } catch (Throwable $exception) {
        logModuleCall(
            'driveresource_gateway',
            'DailyCronJob',
            [],
            ['exception' => get_class($exception), 'message' => $exception->getMessage()],
            null,
            []
        );
    }
});


/**
 * Fallback Drive Resource dashboard for WHMCS themes that omit provisioning
 * module ClientArea output from product-details templates.
 *
 * WHMCS passes the current service model to this official output hook. We
 * still verify ownership and the product's provisioning module in SQL before
 * rendering. The fallback stays hidden until DOM ready and removes itself
 * when the standard module output is already present, preventing duplicates.
 */
add_hook('ClientAreaProductDetailsOutput', 5, static function ($context): string {
    try {
        $service = is_array($context) ? ($context['service'] ?? null) : $context;
        if (!is_object($service)) {
            return '';
        }

        $serviceId = (int) ($service->id ?? 0);
        $clientId = (int) ($_SESSION['uid'] ?? 0);
        if ($serviceId <= 0 || $clientId <= 0) {
            return '';
        }

        $row = \WHMCS\Database\Capsule::table('tblhosting as h')
            ->join('tblproducts as p', 'p.id', '=', 'h.packageid')
            ->where('h.id', $serviceId)
            ->where('h.userid', $clientId)
            ->select(['h.id', 'p.servertype'])
            ->first();

        if (!$row || (string) $row->servertype !== 'driveresource') {
            return '';
        }

        $serverModule = dirname(__DIR__, 2) . '/servers/driveresource';
        require_once $serverModule . '/lib/Translator.php';
        require_once $serverModule . '/lib/ClientPortal.php';

        $params = [
            'serviceid' => $serviceId,
            'model' => $service,
            'password' => '',
            'clientsdetails' => [
                'language' => (string) ($_SESSION['Language'] ?? ''),
            ],
        ];

        $portal = (new \WHMCS\Module\Server\Driveresource\ClientPortal($params))->render();
        $wrapperId = 'dr-product-hook-' . $serviceId;
        $selector = '.dr-portal[data-dr-service-id="' . $serviceId . '"]';

        return '<div id="' . $wrapperId . '" class="dr-clientarea-fallback" style="display:none">'
            . $portal
            . '</div>'
            . '<script>(function(){'
            . 'function boot(){'
            . 'var w=document.getElementById(' . json_encode($wrapperId) . ');'
            . 'if(!w){return;}'
            . 'var p=document.querySelectorAll(' . json_encode($selector) . ');'
            . 'if(p.length>1){w.remove();return;}'
            . 'w.style.display="block";'
            . '}'
            . 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",boot);}'
            . 'else{boot();}'
            . '})();</script>';
    } catch (\Throwable $exception) {
        logModuleCall(
            'driveresource_gateway',
            'ClientAreaProductDetailsOutput',
            [],
            ['error' => $exception->getMessage()],
            null,
            ['password', 'token', 'key', 'secret', 'signature']
        );

        return '';
    }
});
