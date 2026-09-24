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


/**
 * Show installation health directly on the addon configuration page.
 *
 * This makes stale/misplaced module files and unassigned products visible
 * without requiring database access or guessing from the client theme.
 */
add_hook('AddonConfig', 5, static function (array $vars): array {
    try {
        $root = dirname(__DIR__, 3);
        $serverModule = $root . '/modules/servers/driveresource/driveresource.php';
        $products = (int) \WHMCS\Database\Capsule::table('tblproducts')
            ->where('servertype', 'driveresource')
            ->count();

        $wrongType = (int) \WHMCS\Database\Capsule::table('tblproducts')
            ->where('servertype', 'driveresource')
            ->where('type', '<>', 'other')
            ->count();

        $provisioned = 0;
        $assigned = 0;
        if (\WHMCS\Database\Capsule::schema()->hasTable('mod_driveresource_services')) {
            $provisioned = (int) \WHMCS\Database\Capsule::table('mod_driveresource_services')->count();
        }

        $assigned = (int) \WHMCS\Database\Capsule::table('tblhosting as h')
            ->join('tblproducts as p', 'p.id', '=', 'h.packageid')
            ->where('p.servertype', 'driveresource')
            ->count();

        $pending = max(0, $assigned - $provisioned);
        $serverStatus = is_file($serverModule)
            ? '<span class="label label-success">OK</span>'
            : '<span class="label label-danger">Missing</span>';

        $health = '<div style="line-height:1.8">'
            . '<strong>Loaded companion version:</strong> 0.4.3<br>'
            . '<strong>Server module:</strong> ' . $serverStatus . '<br>'
            . '<strong>Products using driveresource:</strong> ' . $products . '<br>'
            . '<strong>Assigned services:</strong> ' . $assigned . '<br>'
            . '<strong>Provisioned services:</strong> ' . $provisioned . '<br>'
            . '<strong>Assigned but not provisioned:</strong> ' . $pending . '<br>'
            . '<strong>Products with type other:</strong> ' . max(0, $products - $wrongType)
            . ' / ' . $products;

        if ($wrongType > 0) {
            $health .= '<br><span class="text-warning"><strong>Warning:</strong> '
                . $wrongType . ' Drive Resource product(s) are not Product Type "Other". '
                . 'Generic hosting/domain/username cards may appear in the client area.</span>';
        }

        return [
            'Drive Resource installation health' => $health . '</div>',
        ];
    } catch (\Throwable $exception) {
        return [
            'Drive Resource installation health' =>
                '<span class="text-danger">Diagnostic failed: '
                . htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8')
                . '</span>',
        ];
    }
});
