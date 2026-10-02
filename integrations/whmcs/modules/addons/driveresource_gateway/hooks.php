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
 * Credit a prepaid wallet only after WHMCS marks its dedicated invoice Paid.
 *
 * @param int $invoiceId WHMCS invoice id.
 * @return void
 */
function driveresource_gateway_credit_paid_recharge(int $invoiceId): void
{
    if (
        $invoiceId <= 0
        || !\WHMCS\Database\Capsule::schema()->hasTable('mod_driveresource_wallet_orders')
    ) {
        return;
    }

    $order = \WHMCS\Database\Capsule::table('mod_driveresource_wallet_orders')
        ->where('invoice_id', $invoiceId)
        ->where('status', 'pending')
        ->first();
    if (!$order) {
        return;
    }

    $invoice = \WHMCS\Database\Capsule::table('tblinvoices')
        ->where('id', $invoiceId)
        ->first();
    if (
        !$invoice
        || (int) $invoice->userid !== (int) $order->client_id
        || strtolower((string) $invoice->status) !== 'paid'
        || (string) $order->currency !== 'USD'
    ) {
        return;
    }

    require_once __DIR__ . '/lib/CommercialAccount.php';
    require_once __DIR__ . '/lib/WalletService.php';

    $wallet = new \WHMCS\Module\Addon\DriveresourceGateway\WalletService();
    $wallet->credit(
        (int) $order->service_id,
        (int) $order->amount_microusd,
        'recharge',
        hash('sha256', 'wallet-credit|' . (int) $order->id . '|' . $invoiceId),
        'invoice:' . $invoiceId,
        [
            'wallet_order_id' => (int) $order->id,
            'invoice_id' => $invoiceId,
        ]
    );

    \WHMCS\Database\Capsule::table('mod_driveresource_wallet_orders')
        ->where('id', (int) $order->id)
        ->where('status', 'pending')
        ->update([
            'status' => 'paid',
            'paid_at' => time(),
            'updated_at' => time(),
        ]);
}

/**
 * Reverse a wallet recharge when its WHMCS invoice is fully refunded or
 * explicitly moved back out of Paid state.
 *
 * The reversal key is shared by refund/unpaid hooks, preventing a double debit
 * if WHMCS emits both events for the same financial reversal.
 *
 * @param int $invoiceId WHMCS invoice id.
 * @param string $reason Stable reversal reason.
 * @return void
 */
function driveresource_gateway_reverse_recharge(int $invoiceId, string $reason): void
{
    if (
        $invoiceId <= 0
        || !\WHMCS\Database\Capsule::schema()->hasTable('mod_driveresource_wallet_orders')
    ) {
        return;
    }

    $order = \WHMCS\Database\Capsule::table('mod_driveresource_wallet_orders')
        ->where('invoice_id', $invoiceId)
        ->where('status', 'paid')
        ->first();
    if (!$order) {
        return;
    }

    require_once __DIR__ . '/lib/CommercialAccount.php';
    require_once __DIR__ . '/lib/WalletService.php';

    $wallet = new \WHMCS\Module\Addon\DriveresourceGateway\WalletService();
    $wallet->debit(
        (int) $order->service_id,
        (int) $order->amount_microusd,
        'refund',
        hash('sha256', 'wallet-reversal|' . (int) $order->id),
        [
            'wallet_order_id' => (int) $order->id,
            'invoice_id' => $invoiceId,
            'reason' => $reason,
        ]
    );

    \WHMCS\Database\Capsule::table('mod_driveresource_wallet_orders')
        ->where('id', (int) $order->id)
        ->where('status', 'paid')
        ->update([
            'status' => $reason === 'refunded' ? 'refunded' : 'reversed',
            'refunded_at' => time(),
            'updated_at' => time(),
        ]);

    $remainingPaidRecharges = (int) \WHMCS\Database\Capsule::table(
        'mod_driveresource_wallet_orders'
    )
        ->where('service_id', (int) $order->service_id)
        ->where('status', 'paid')
        ->count();

    if ($remainingPaidRecharges === 0) {
        \WHMCS\Database\Capsule::table('mod_driveresource_accounts')
            ->where('service_id', (int) $order->service_id)
            ->where('billing_mode', 'payg')
            ->update([
                'billing_mode' => 'free',
                'paid_at' => null,
                'status' => 'upload_restricted',
                'updated_at' => time(),
            ]);
    }
}

add_hook('InvoicePaid', 1, static function (array $vars): void {
    try {
        driveresource_gateway_credit_paid_recharge((int) ($vars['invoiceid'] ?? 0));
    } catch (Throwable $exception) {
        logModuleCall(
            'driveresource_gateway',
            'InvoicePaidWalletRecharge',
            ['invoiceid' => (int) ($vars['invoiceid'] ?? 0)],
            ['exception' => get_class($exception), 'message' => $exception->getMessage()],
            null,
            []
        );
    }
});

add_hook('InvoiceRefunded', 1, static function (array $vars): void {
    try {
        driveresource_gateway_reverse_recharge(
            (int) ($vars['invoiceid'] ?? 0),
            'refunded'
        );
    } catch (Throwable $exception) {
        logModuleCall(
            'driveresource_gateway',
            'InvoiceRefundedWalletRecharge',
            ['invoiceid' => (int) ($vars['invoiceid'] ?? 0)],
            ['exception' => get_class($exception), 'message' => $exception->getMessage()],
            null,
            []
        );
    }
});

add_hook('InvoiceUnpaid', 1, static function (array $vars): void {
    try {
        driveresource_gateway_reverse_recharge(
            (int) ($vars['invoiceid'] ?? 0),
            'unpaid'
        );
    } catch (Throwable $exception) {
        logModuleCall(
            'driveresource_gateway',
            'InvoiceUnpaidWalletRecharge',
            ['invoiceid' => (int) ($vars['invoiceid'] ?? 0)],
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
