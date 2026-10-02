<?php

/**
 * Executable business-rule tests for the Elearning Stream commercial wallet.
 *
 * This script intentionally runs without a WHMCS bootstrap. It exercises the
 * pure money/state-transition code used by production WalletService.
 */

$root = dirname(__DIR__, 2);
$lib = $root . '/integrations/whmcs/modules/addons/driveresource_gateway/lib';

require_once $lib . '/CommercialAccount.php';
require_once $lib . '/CommercialPolicy.php';
require_once $lib . '/Money.php';

use WHMCS\Module\Addon\DriveresourceGateway\CommercialAccount;
use WHMCS\Module\Addon\DriveresourceGateway\CommercialPolicy;
use WHMCS\Module\Addon\DriveresourceGateway\Money;

/**
 * @param bool $condition Assertion.
 * @param string $message Failure message.
 * @return void
 */
function assert_wallet(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

assert_wallet(Money::decimalToMicrounits('10.00') === 10000000, 'US$10 decimal parsing');
assert_wallet(Money::decimalToMicrounits('367.50') === 367500000, 'Converted invoice parsing');
assert_wallet(Money::microunitsToDecimal(10000000) === '10.00', 'US$10 decimal formatting');

$now = 1700000000;

assert_wallet(
    CommercialPolicy::nextActivationSettlementVersion(false, null, 0) === 1,
    'First paid activation must create settlement version 1'
);
assert_wallet(
    CommercialPolicy::nextActivationSettlementVersion(true, null, 1) === null,
    'Verified activation must not mint duplicate credit'
);
assert_wallet(
    CommercialPolicy::nextActivationSettlementVersion(false, null, 1) === 2,
    'Paid after activation Unpaid must create a new settlement version'
);
assert_wallet(
    CommercialPolicy::nextActivationSettlementVersion(false, $now, 1) === null,
    'Refunded activation must be terminal'
);

assert_wallet(
    CommercialPolicy::nextRechargeSettlementVersion('pending', 0) === 1,
    'Initial Paid transition must create settlement version 1'
);
assert_wallet(
    CommercialPolicy::rechargeReversalVersion('paid', 1) === 1,
    'Unpaid/refund must reverse the currently paid settlement version'
);
assert_wallet(
    CommercialPolicy::nextRechargeSettlementVersion('reversed', 1) === 2,
    'Paid after Unpaid must create a new settlement version'
);
assert_wallet(
    CommercialPolicy::rechargeReversalVersion('paid', 2) === 2,
    'Second reversal must target the second settlement version'
);
assert_wallet(
    CommercialPolicy::nextRechargeSettlementVersion('refunded', 2) === null,
    'Refunded recharge orders are terminal and must require a new invoice'
);
assert_wallet(
    CommercialPolicy::nextRechargeSettlementVersion('paid', 2) === null,
    'Duplicate InvoicePaid must not create another settlement'
);

$activation = CommercialPolicy::afterCredit(
    0,
    CommercialAccount::MODE_FREE,
    CommercialAccount::STATUS_ACTIVE,
    null,
    1000000,
    'activation',
    10000000,
    $now
);
assert_wallet($activation['balance'] === 1000000, 'Activation must credit exactly US$1');
assert_wallet($activation['mode'] === CommercialAccount::MODE_FREE, 'Activation must stay FREE');
assert_wallet($activation['status'] === CommercialAccount::STATUS_ACTIVE, 'Activated FREE account must be active');
assert_wallet($activation['paidat'] === null, 'Activation must not create PAYG paid_at');

$payg = CommercialPolicy::afterCredit(
    $activation['balance'],
    $activation['mode'],
    $activation['status'],
    $activation['paidat'],
    10000000,
    'recharge',
    10000000,
    $now
);
assert_wallet($payg['balance'] === 11000000, 'US$10 recharge must preserve activation credit');
assert_wallet($payg['mode'] === CommercialAccount::MODE_PAYG, 'Qualifying recharge must enable PAYG');
assert_wallet($payg['status'] === CommercialAccount::STATUS_ACTIVE, 'Funded PAYG account must be active');
assert_wallet($payg['paidat'] === $now, 'First qualifying recharge must set paid_at');

$debtRecharge = CommercialPolicy::afterCredit(
    -12000000,
    CommercialAccount::MODE_FREE,
    CommercialAccount::STATUS_UPLOAD_RESTRICTED,
    null,
    10000000,
    'recharge',
    10000000,
    $now
);
assert_wallet($debtRecharge['balance'] === -2000000, 'Recharge must preserve uncovered debt');
assert_wallet($debtRecharge['mode'] === CommercialAccount::MODE_PAYG, 'Qualifying recharge records PAYG entitlement');
assert_wallet(
    $debtRecharge['status'] === CommercialAccount::STATUS_UPLOAD_RESTRICTED,
    'PAYG must remain restricted while the wallet is negative'
);

$debit = CommercialPolicy::afterDebit(
    11000000,
    CommercialAccount::STATUS_ACTIVE,
    12000000
);
assert_wallet($debit['balance'] === -1000000, 'Debit must carry debt below zero');
assert_wallet(
    $debit['status'] === CommercialAccount::STATUS_UPLOAD_RESTRICTED,
    'Negative balance must restrict paid overage'
);

$refundFree = CommercialPolicy::afterRechargeReversal(
    1000000,
    CommercialAccount::MODE_PAYG,
    CommercialAccount::STATUS_ACTIVE,
    $now,
    0
);
assert_wallet($refundFree['mode'] === CommercialAccount::MODE_FREE, 'Final refund must remove PAYG entitlement');
assert_wallet($refundFree['status'] === CommercialAccount::STATUS_ACTIVE, 'Debt-free refund must return to active FREE');
assert_wallet($refundFree['paidat'] === null, 'Final refund must clear paid_at');

$refundDebt = CommercialPolicy::afterRechargeReversal(
    -1000000,
    CommercialAccount::MODE_PAYG,
    CommercialAccount::STATUS_UPLOAD_RESTRICTED,
    $now,
    0
);
assert_wallet($refundDebt['mode'] === CommercialAccount::MODE_FREE, 'Refund with debt must still remove PAYG');
assert_wallet(
    $refundDebt['status'] === CommercialAccount::STATUS_UPLOAD_RESTRICTED,
    'Refund debt must remain restricted'
);

$stillPayg = CommercialPolicy::afterRechargeReversal(
    5000000,
    CommercialAccount::MODE_PAYG,
    CommercialAccount::STATUS_ACTIVE,
    $now,
    1
);
assert_wallet($stillPayg['mode'] === CommercialAccount::MODE_PAYG, 'Remaining paid recharge must preserve PAYG');
assert_wallet($stillPayg['paidat'] === $now, 'Remaining paid recharge must preserve paid_at');

echo "Elearning Stream commercial wallet behavior: PASS\n";
