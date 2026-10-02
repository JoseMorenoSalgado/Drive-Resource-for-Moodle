<?php

/**
 * Executable legacy migration scenarios for Elearning Stream Gateway 0.6.
 */

$root = dirname(__DIR__, 2);
$lib = $root . '/integrations/whmcs/modules/addons/driveresource_gateway/lib';

require_once $lib . '/CommercialAccount.php';
require_once $lib . '/CommercialMigrationPolicy.php';

use WHMCS\Module\Addon\DriveresourceGateway\CommercialAccount;
use WHMCS\Module\Addon\DriveresourceGateway\CommercialMigrationPolicy;

/**
 * @param bool $condition Assertion.
 * @param string $message Failure message.
 * @return void
 */
function assert_migration(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$now = 1700000000;
$created = 1600000000;

$active = (object) [
    'service_id' => 42,
    'quota_bytes' => 9876543210,
    'status' => 'active',
    'site_url' => 'https://campus.example.test',
    'site_hash' => hash('sha256', 'https://campus.example.test'),
    'token_hash' => hash('sha256', str_repeat('a', 64)),
    'connection_status' => 'connected',
    'connection_checked_at' => 1699999000,
    'connection_message' => 'connection_verified',
    'created_at' => $created,
];

$account = CommercialMigrationPolicy::legacyAccountValues($active, 77, $now);
assert_migration($account['service_id'] === 42, 'Legacy account must preserve service id');
assert_migration($account['client_id'] === 77, 'Legacy account must preserve client ownership');
assert_migration($account['billing_mode'] === CommercialAccount::MODE_LEGACY, 'Legacy mode must be preserved');
assert_migration($account['activation_verified'] === true, 'Legacy service must remain operational');
assert_migration($account['activation_amount_microusd'] === 0, 'Migration must not fabricate activation credit');
assert_migration($account['activation_invoice_id'] === null, 'Legacy account must stay outside paid activation invoice lifecycle');
assert_migration($account['activation_settlement_version'] === 0, 'Legacy account must not fabricate activation settlement history');
assert_migration($account['activation_refunded_at'] === null, 'Legacy account must not fabricate activation refund state');
assert_migration($account['balance_microusd'] === 0, 'Migration must not fabricate wallet balance');
assert_migration($account['free_storage_bytes'] === 9876543210, 'Legacy quota must be preserved');
assert_migration($account['status'] === CommercialAccount::STATUS_ACTIVE, 'Active legacy service must stay active');
assert_migration($account['created_at'] === $created, 'Original creation timestamp must be preserved');

$installation = CommercialMigrationPolicy::primaryInstallationValues($active, $now);
assert_migration($installation !== null, 'Provisioned legacy service must create a primary installation');
assert_migration($installation['service_id'] === 42, 'Primary installation must preserve service id');
assert_migration($installation['site_url'] === $active->site_url, 'Primary installation must preserve site URL');
assert_migration($installation['site_hash'] === $active->site_hash, 'Primary installation must preserve site hash');
assert_migration($installation['token_hash'] === $active->token_hash, 'Primary installation must preserve token hash');
assert_migration($installation['is_primary'] === true, 'Migrated installation must be primary');
assert_migration($installation['status'] === 'active', 'Active service installation must stay active');
assert_migration($installation['connection_status'] === 'connected', 'Connection status must be preserved');

$suspended = clone $active;
$suspended->service_id = 43;
$suspended->status = 'suspended';
$suspendedAccount = CommercialMigrationPolicy::legacyAccountValues($suspended, 78, $now);
$suspendedInstall = CommercialMigrationPolicy::primaryInstallationValues($suspended, $now);
assert_migration(
    $suspendedAccount['status'] === CommercialAccount::STATUS_SUSPENDED,
    'Suspended legacy account must stay suspended'
);
assert_migration(
    $suspendedInstall !== null && $suspendedInstall['status'] === 'suspended',
    'Suspended primary installation must stay suspended'
);

$incomplete = clone $active;
$incomplete->service_id = 44;
$incomplete->token_hash = '';
$incompleteAccount = CommercialMigrationPolicy::legacyAccountValues($incomplete, null, $now);
$incompleteInstall = CommercialMigrationPolicy::primaryInstallationValues($incomplete, $now);
assert_migration($incompleteAccount['billing_mode'] === CommercialAccount::MODE_LEGACY, 'Incomplete service still needs an account row');
assert_migration($incompleteAccount['client_id'] === null, 'Missing client id must remain null');
assert_migration($incompleteInstall === null, 'Incomplete binding must not create a broken Moodle installation');

echo "Elearning Stream Gateway 0.5.9 -> 0.6.0 migration behavior: PASS\n";
