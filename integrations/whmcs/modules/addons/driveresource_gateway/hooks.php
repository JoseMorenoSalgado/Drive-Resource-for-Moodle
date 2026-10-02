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

    \WHMCS\Database\Capsule::connection()->transaction(static function () use ($invoiceId): void {
        $order = \WHMCS\Database\Capsule::table('mod_driveresource_wallet_orders')
            ->where('invoice_id', $invoiceId)
            ->whereIn('status', ['pending', 'reversed'])
            ->lockForUpdate()
            ->first();
        if (!$order) {
            return;
        }

        $invoice = \WHMCS\Billing\Invoice::find($invoiceId);
        if (
            !$invoice
            || (int) $invoice->userid !== (int) $order->client_id
            || strtolower((string) $invoice->status) !== 'paid'
        ) {
            return;
        }

        require_once __DIR__ . '/lib/CommercialAccount.php';
        require_once __DIR__ . '/lib/CommercialPolicy.php';
        require_once __DIR__ . '/lib/Money.php';
        require_once __DIR__ . '/lib/WalletService.php';

        $expectedInvoiceAmount = (int) ($order->invoice_amount_microunits ?? 0);
        if ($expectedInvoiceAmount <= 0) {
            throw new RuntimeException(
                'Legacy wallet recharge order has no frozen invoice amount; recreate the recharge invoice.'
            );
        }

        $expectedCurrency = strtoupper(trim((string) $order->currency));
        $invoiceCurrency = strtoupper(trim((string) $invoice->getCurrencyCodeAttribute()));
        $actualInvoiceAmount = \WHMCS\Module\Addon\DriveresourceGateway\Money::decimalToMicrounits(
            (string) $invoice->total
        );

        if (
            $invoiceCurrency === ''
            || !hash_equals($expectedCurrency, $invoiceCurrency)
            || $actualInvoiceAmount !== $expectedInvoiceAmount
        ) {
            throw new RuntimeException(
                'Wallet recharge invoice currency or amount does not match the frozen recharge order.'
            );
        }

        $settlementVersion = \WHMCS\Module\Addon\DriveresourceGateway\CommercialPolicy::nextRechargeSettlementVersion(
            (string) $order->status,
            (int) ($order->settlement_version ?? 0)
        );
        if ($settlementVersion === null) {
            return;
        }

        $wallet = new \WHMCS\Module\Addon\DriveresourceGateway\WalletService();
        $wallet->credit(
            (int) $order->service_id,
            (int) $order->amount_microusd,
            'recharge',
            hash(
                'sha256',
                'wallet-credit|' . (int) $order->id . '|' . $invoiceId . '|v' . $settlementVersion
            ),
            'invoice:' . $invoiceId,
            [
                'wallet_order_id' => (int) $order->id,
                'invoice_id' => $invoiceId,
                'invoice_currency' => $invoiceCurrency,
                'invoice_amount_microunits' => $actualInvoiceAmount,
                'settlement_version' => $settlementVersion,
            ]
        );

        \WHMCS\Database\Capsule::table('mod_driveresource_wallet_orders')
            ->where('id', (int) $order->id)
            ->whereIn('status', ['pending', 'reversed'])
            ->update([
                'status' => 'paid',
                'settlement_version' => $settlementVersion,
                'paid_at' => time(),
                'refunded_at' => null,
                'updated_at' => time(),
            ]);
    });
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
    if (!in_array($reason, ['refunded', 'unpaid'], true)) {
        throw new RuntimeException('Invalid wallet recharge reversal reason.');
    }

    \WHMCS\Database\Capsule::connection()->transaction(static function () use (
        $invoiceId,
        $reason
    ): void {
        $order = \WHMCS\Database\Capsule::table('mod_driveresource_wallet_orders')
            ->where('invoice_id', $invoiceId)
            ->where('status', 'paid')
            ->lockForUpdate()
            ->first();
        if (!$order) {
            return;
        }

        require_once __DIR__ . '/lib/CommercialAccount.php';
        require_once __DIR__ . '/lib/CommercialPolicy.php';
        require_once __DIR__ . '/lib/WalletService.php';

        $settlementVersion = \WHMCS\Module\Addon\DriveresourceGateway\CommercialPolicy::rechargeReversalVersion(
            (string) $order->status,
            (int) ($order->settlement_version ?? 0)
        );
        if ($settlementVersion === null) {
            return;
        }

        $wallet = new \WHMCS\Module\Addon\DriveresourceGateway\WalletService();
        $wallet->debit(
            (int) $order->service_id,
            (int) $order->amount_microusd,
            'refund',
            hash(
                'sha256',
                'wallet-reversal|' . (int) $order->id . '|v' . $settlementVersion
            ),
            [
                'wallet_order_id' => (int) $order->id,
                'invoice_id' => $invoiceId,
                'reason' => $reason,
                'settlement_version' => $settlementVersion,
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

        $account = \WHMCS\Database\Capsule::table('mod_driveresource_accounts')
            ->where('service_id', (int) $order->service_id)
            ->lockForUpdate()
            ->first();
        if (!$account) {
            throw new RuntimeException('Elearning Stream commercial account was not found.');
        }

        $state = \WHMCS\Module\Addon\DriveresourceGateway\CommercialPolicy::afterRechargeReversal(
            (int) $account->balance_microusd,
            (string) $account->billing_mode,
            (string) $account->status,
            !empty($account->paid_at) ? (int) $account->paid_at : null,
            $remainingPaidRecharges
        );

        \WHMCS\Database\Capsule::table('mod_driveresource_accounts')
            ->where('service_id', (int) $order->service_id)
            ->update([
                'billing_mode' => $state['mode'],
                'paid_at' => $state['paidat'],
                'status' => $state['status'],
                'updated_at' => time(),
            ]);
    });
}

/**
 * Restore a non-legacy activation when its original invoice returns to Paid.
 *
 * An activation invoice that reached Refunded is terminal. An invoice merely
 * marked Unpaid may be paid again; each restoration gets a new settlement
 * version and therefore a distinct idempotency key.
 *
 * @param int $invoiceId WHMCS invoice id.
 * @return void
 */
function driveresource_gateway_restore_paid_activation(int $invoiceId): void
{
    if (
        $invoiceId <= 0
        || !\WHMCS\Database\Capsule::schema()->hasTable('mod_driveresource_accounts')
    ) {
        return;
    }

    \WHMCS\Database\Capsule::connection()->transaction(static function () use ($invoiceId): void {
        $account = \WHMCS\Database\Capsule::table('mod_driveresource_accounts')
            ->where('activation_invoice_id', $invoiceId)
            ->where('billing_mode', '<>', 'legacy')
            ->lockForUpdate()
            ->first();
        if (
            !$account
            || (bool) $account->activation_verified
            || !empty($account->activation_refunded_at)
        ) {
            return;
        }

        $invoice = \WHMCS\Billing\Invoice::find($invoiceId);
        if (
            !$invoice
            || (int) $invoice->clientId !== (int) $account->client_id
            || strtolower((string) $invoice->status) !== 'paid'
        ) {
            return;
        }

        $containsService = \WHMCS\Database\Capsule::table('tblinvoiceitems')
            ->where('invoiceid', $invoiceId)
            ->where('type', 'Hosting')
            ->where('relid', (int) $account->service_id)
            ->exists();
        if (!$containsService) {
            throw new RuntimeException(
                'Elearning Stream activation invoice no longer contains its service.'
            );
        }

        require_once __DIR__ . '/lib/CommercialAccount.php';
        require_once __DIR__ . '/lib/CommercialPolicy.php';
        require_once __DIR__ . '/lib/WalletService.php';

        $settlementVersion = (int) ($account->activation_settlement_version ?? 0) + 1;
        $activationCredit = max(0, (int) $account->activation_amount_microusd);
        if ($activationCredit > 0) {
            $wallet = new \WHMCS\Module\Addon\DriveresourceGateway\WalletService();
            $wallet->credit(
                (int) $account->service_id,
                $activationCredit,
                'activation',
                hash(
                    'sha256',
                    'activation-credit|' . (int) $account->service_id . '|' . $invoiceId
                        . '|v' . $settlementVersion
                ),
                'invoice:' . $invoiceId,
                [
                    'source' => 'invoice_paid_reactivation',
                    'activation_invoice_id' => $invoiceId,
                    'settlement_version' => $settlementVersion,
                ]
            );
        }

        \WHMCS\Database\Capsule::table('mod_driveresource_accounts')
            ->where('service_id', (int) $account->service_id)
            ->update([
                'activation_verified' => true,
                'activation_settlement_version' => $settlementVersion,
                'updated_at' => time(),
            ]);
    });
}

/**
 * Reverse the activation credit when its activation invoice leaves Paid.
 *
 * @param int $invoiceId WHMCS invoice id.
 * @param string $reason Either refunded or unpaid.
 * @return void
 */
function driveresource_gateway_reverse_activation(int $invoiceId, string $reason): void
{
    if (
        $invoiceId <= 0
        || !\WHMCS\Database\Capsule::schema()->hasTable('mod_driveresource_accounts')
    ) {
        return;
    }
    if (!in_array($reason, ['refunded', 'unpaid'], true)) {
        throw new RuntimeException('Invalid activation reversal reason.');
    }

    \WHMCS\Database\Capsule::connection()->transaction(static function () use (
        $invoiceId,
        $reason
    ): void {
        $account = \WHMCS\Database\Capsule::table('mod_driveresource_accounts')
            ->where('activation_invoice_id', $invoiceId)
            ->where('billing_mode', '<>', 'legacy')
            ->lockForUpdate()
            ->first();
        if (!$account) {
            return;
        }

        if ((bool) $account->activation_verified) {
            require_once __DIR__ . '/lib/CommercialAccount.php';
            require_once __DIR__ . '/lib/CommercialPolicy.php';
            require_once __DIR__ . '/lib/WalletService.php';

            $activationCredit = max(0, (int) $account->activation_amount_microusd);
            $settlementVersion = max(0, (int) ($account->activation_settlement_version ?? 0));

            if ($activationCredit > 0) {
                $wallet = new \WHMCS\Module\Addon\DriveresourceGateway\WalletService();
                $wallet->debit(
                    (int) $account->service_id,
                    $activationCredit,
                    'activation_refund',
                    hash(
                        'sha256',
                        'activation-reversal|' . (int) $account->service_id . '|' . $invoiceId
                            . '|v' . $settlementVersion
                    ),
                    [
                        'activation_invoice_id' => $invoiceId,
                        'reason' => $reason,
                        'settlement_version' => $settlementVersion,
                    ]
                );
            }

            \WHMCS\Database\Capsule::table('mod_driveresource_accounts')
                ->where('service_id', (int) $account->service_id)
                ->update([
                    'activation_verified' => false,
                    'activation_refunded_at' => $reason === 'refunded' ? time() : null,
                    'updated_at' => time(),
                ]);
            return;
        }

        if ($reason === 'refunded' && empty($account->activation_refunded_at)) {
            // An Unpaid event may have already reversed the credit. A later
            // Refunded event makes the same activation invoice terminal
            // without applying a second debit.
            \WHMCS\Database\Capsule::table('mod_driveresource_accounts')
                ->where('service_id', (int) $account->service_id)
                ->update([
                    'activation_refunded_at' => time(),
                    'updated_at' => time(),
                ]);
        }
    });
}

add_hook('InvoicePaid', 1, static function (array $vars): void {
    try {
        $invoiceId = (int) ($vars['invoiceid'] ?? 0);
        driveresource_gateway_credit_paid_recharge($invoiceId);
        driveresource_gateway_restore_paid_activation($invoiceId);
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
        $invoiceId = (int) ($vars['invoiceid'] ?? 0);
        driveresource_gateway_reverse_recharge($invoiceId, 'refunded');
        driveresource_gateway_reverse_activation($invoiceId, 'refunded');
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
        $invoiceId = (int) ($vars['invoiceid'] ?? 0);
        driveresource_gateway_reverse_recharge($invoiceId, 'unpaid');
        driveresource_gateway_reverse_activation($invoiceId, 'unpaid');
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
 * Fallback Elearning Stream dashboard for WHMCS themes that omit provisioning
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
