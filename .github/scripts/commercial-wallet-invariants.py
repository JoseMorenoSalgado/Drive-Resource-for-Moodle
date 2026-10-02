#!/usr/bin/env python3
"""Static contract checks for Elearning Stream 0.6 commercial invariants."""

from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


def require(source: str, needle: str, message: str) -> None:
    if needle not in source:
        raise SystemExit(message)


def reject(source: str, needle: str, message: str) -> None:
    if needle in source:
        raise SystemExit(message)


server = read("integrations/whmcs/modules/servers/driveresource/driveresource.php")
addon = read("integrations/whmcs/modules/addons/driveresource_gateway/driveresource_gateway.php")
wallet = read("integrations/whmcs/modules/addons/driveresource_gateway/lib/WalletService.php")
policy = read("integrations/whmcs/modules/addons/driveresource_gateway/lib/CommercialPolicy.php")
money = read("integrations/whmcs/modules/addons/driveresource_gateway/lib/Money.php")
migration = read("integrations/whmcs/modules/addons/driveresource_gateway/lib/CommercialMigrationPolicy.php")
commercial = read("integrations/whmcs/modules/addons/driveresource_gateway/lib/CommercialAccount.php")
auth = read("integrations/whmcs/modules/addons/driveresource_gateway/lib/RequestAuthenticator.php")
gateway = read("integrations/whmcs/modules/addons/driveresource_gateway/lib/GatewayService.php")
maintenance = read("integrations/whmcs/modules/addons/driveresource_gateway/lib/GatewayMaintenance.php")
hooks = read("integrations/whmcs/modules/addons/driveresource_gateway/hooks.php")

# Paid activation boundary.
require(
    server,
    "return driveresource_provision_moodle_connection($params, false, true);",
    "CreateAccount must be the only provisioning route eligible to verify activation.",
)
require(
    server,
    "return driveresource_provision_moodle_connection($params, false, false);",
    "Manual connection repair must not mint activation credit.",
)
require(
    server,
    "return driveresource_provision_moodle_connection($params, true, false);",
    "Token rotation must not mint activation credit.",
)
require(
    server,
    "driveresource_require_paid_activation_invoice",
    "CreateAccount activation must prove a paid WHMCS invoice.",
)
require(
    server,
    "->where('type', 'Hosting')",
    "Activation invoice proof must contain the exact Hosting service line.",
)
require(
    server,
    "strtolower((string) $invoice->status) !== 'paid'",
    "Activation must reject invoices that are not Paid.",
)
require(
    server,
    "'activation_verified' => false",
    "Commercial accounts must start unverified until paid activation settlement succeeds.",
)
require(
    server,
    "nextActivationSettlementVersion",
    "Activation credit must use a versioned executable settlement policy.",
)
require(
    server,
    "$account->activation_amount_microusd",
    "Activation retries must credit the amount frozen on the commercial account.",
)
require(
    server,
    "'activation_invoice_id' => $activationInvoiceId",
    "Paid activation invoice id must be persisted on the commercial account.",
)
require(
    auth,
    "!(bool) $account->activation_verified",
    "Gateway authentication must reject unactivated non-legacy accounts.",
)
require(
    auth,
    "Elearning Stream commercial account migration is incomplete.",
    "Gateway authentication must fail closed when a 0.6 account row is missing.",
)
require(
    commercial,
    "Capsule::schema()->hasTable('mod_driveresource_accounts')",
    "Upload policy must not silently reinterpret a missing 0.6 account as legacy.",
)
require(
    gateway,
    "!(bool) ($quota['activationverified'] ?? false)",
    "Upload policy must reject unactivated accounts.",
)
require(
    gateway,
    "if (!(bool) $account->activation_verified)",
    "Playback policy must reject unactivated accounts.",
)

# Upgrade safety and compatibility.
require(
    addon,
    "if (version_compare($installed, '0.6.0', '<'))",
    "Gateway upgrade must explicitly run the 0.6.0 migration.",
)
require(
    migration,
    "CommercialAccount::MODE_LEGACY",
    "Pre-0.6 services must be backfilled as legacy accounts.",
)
require(
    migration,
    "'activation_amount_microusd' => 0",
    "Legacy migration must not fabricate activation wallet credit.",
)
require(
    addon,
    "driveresource_gateway_ensure_commercial_account_schema();\n}",
    "Gateway 0.6 migration repair pass must run on every upgrade invocation.",
)
require(
    addon,
    "$missingInstallations",
    "Gateway upgrade must assert that provisioned legacy services receive a primary Moodle installation.",
)
require(
    addon,
    "$invalidLegacyActivation",
    "Gateway upgrade must reject fabricated activation credit on legacy accounts.",
)
require(
    addon,
    "driveresource_gateway_backfill_activation_invoice_links",
    "Gateway upgrade must repair provable activation invoices from earlier 0.6 RCs.",
)
require(
    addon,
    "$unprovenActivations",
    "Gateway upgrade must fail closed on activated non-legacy accounts without paid invoice proof.",
)
require(
    addon,
    "activation_settlement_version",
    "Commercial schema must persist activation settlement versions.",
)
require(
    addon,
    "activation_refunded_at",
    "Commercial schema must persist terminal activation refunds.",
)
require(
    addon,
    "CommercialMigrationPolicy::legacyAccountValues",
    "Gateway migration must use the executable legacy account mapping policy.",
)
require(
    addon,
    "CommercialMigrationPolicy::primaryInstallationValues",
    "Gateway migration must use the executable primary-installation mapping policy.",
)
require(migration, "MODE_LEGACY", "Legacy migration policy must preserve legacy commercial mode.")
require(
    migration,
    "'activation_amount_microusd' => 0",
    "Legacy migration policy must never fabricate activation credit.",
)
require(
    migration,
    "'activation_invoice_id' => null",
    "Legacy migration policy must not fabricate paid activation invoice proof.",
)
require(
    migration,
    "'activation_settlement_version' => 0",
    "Legacy migration policy must not fabricate activation settlement history.",
)
require(
    migration,
    "'balance_microusd' => 0",
    "Legacy migration policy must never fabricate wallet balance.",
)
for table in (
    "mod_driveresource_accounts",
    "mod_driveresource_installations",
    "mod_driveresource_wallet_ledger",
    "mod_driveresource_wallet_orders",
    "mod_driveresource_usage_daily",
):
    require(addon, table, f"Commercial schema is missing {table}.")
require(
    addon,
    "invoice_amount_microunits",
    "Recharge orders must freeze the exact invoice amount for later payment validation.",
)
require(
    addon,
    "settlement_version",
    "Recharge orders must persist the settlement cycle version.",
)
require(
    server,
    "'settlement_version' => 0",
    "New recharge orders must start before their first payment settlement.",
)
require(
    server,
    "Currency::convertBetween",
    "Wallet recharge invoices must convert USD wallet value into the client's WHMCS currency.",
)
require(
    server,
    "'invoice_amount_microunits' => $invoiceMoney['microunits']",
    "Recharge orders must persist the converted invoice amount.",
)
require(
    hooks,
    "getCurrencyCodeAttribute()",
    "InvoicePaid must verify the invoice currency before wallet credit.",
)
require(
    hooks,
    "Money::decimalToMicrounits",
    "InvoicePaid must verify the exact frozen invoice total.",
)
require(
    hooks,
    "->whereIn('status', ['pending', 'reversed'])\n            ->lockForUpdate()",
    "InvoicePaid must serialize first-time and re-opened recharge settlements.",
)
require(
    hooks,
    "CommercialPolicy::afterRechargeReversal",
    "Refund/unpaid transitions must use the executable commercial policy.",
)

# Wallet idempotency and invoice lifecycle.
require(wallet, "->where('idempotency_key', $idempotencyKey)", "Wallet operations must be idempotent.")
require(wallet, "validatedExistingBalance", "Repeated wallet operations must validate idempotency-key ownership.")
require(wallet, "Wallet idempotency key collision detected.", "Idempotency key collisions must fail closed.")
require(wallet, "CommercialPolicy::afterCredit", "Wallet credits must use the executable commercial policy.")
require(wallet, "CommercialPolicy::afterDebit", "Wallet debits must use the executable commercial policy.")
require(wallet, "->lockForUpdate()", "Wallet account/ledger mutation must be serialized.")
credit_start = wallet.index("public function credit(")
credit_account_lock = wallet.index("mod_driveresource_accounts", credit_start)
credit_ledger_lookup = wallet.index("mod_driveresource_wallet_ledger", credit_start)
if credit_account_lock > credit_ledger_lookup:
    raise SystemExit("Wallet credit must lock the account before checking the idempotency ledger.")
debit_start = wallet.index("public function debit(")
debit_account_lock = wallet.index("mod_driveresource_accounts", debit_start)
debit_ledger_lookup = wallet.index("mod_driveresource_wallet_ledger", debit_start)
if debit_account_lock > debit_ledger_lookup:
    raise SystemExit("Wallet debit must lock the account before checking the idempotency ledger.")
require(
    hooks,
    "->whereIn('status', ['pending', 'reversed'])",
    "InvoicePaid must accept only a first settlement or a previously reversed Unpaid settlement.",
)
require(hooks, "nextRechargeSettlementVersion", "InvoicePaid must version each wallet settlement cycle.")
require(hooks, "rechargeReversalVersion", "Refund/unpaid must reverse the current settlement version.")
require(hooks, "'wallet-credit|'", "Recharge credit key must be deterministic.")
require(hooks, "'wallet-reversal|'", "Recharge reversal key must be deterministic.")
require(hooks, "'|v' . $settlementVersion", "Wallet settlement idempotency keys must include their cycle version.")
require(hooks, "add_hook('InvoicePaid'", "InvoicePaid wallet hook is missing.")
require(hooks, "add_hook('InvoiceRefunded'", "InvoiceRefunded wallet hook is missing.")
require(hooks, "add_hook('InvoiceUnpaid'", "InvoiceUnpaid wallet hook is missing.")
require(hooks, "driveresource_gateway_restore_paid_activation", "InvoicePaid must restore an Unpaid activation safely.")
require(hooks, "driveresource_gateway_reverse_activation", "Refund/unpaid must reverse activation credit.")
require(hooks, "'activation-credit|'", "Activation credit keys must be deterministic and versioned.")
require(hooks, "'activation-reversal|'", "Activation reversal keys must be deterministic and versioned.")

# PAYG debt must remain visible instead of silently dropping uncovered provider cost.
require(
    maintenance,
    "$nextBalance = $balance - $charge;",
    "Storage settlement must carry insufficient prepaid balance as debt.",
)
require(
    gateway,
    "$nextBalance = $balance - $chargeMicrousd;",
    "Transfer settlement must carry insufficient prepaid balance as debt.",
)
require(
    maintenance,
    "'debt_after_microusd' => max(0, -$nextBalance)",
    "Storage ledger metadata must expose resulting debt.",
)
require(
    gateway,
    "'debt_after_microusd' => max(0, -$nextBalance)",
    "Transfer ledger metadata must expose resulting debt.",
)
reject(
    maintenance,
    "min($balance, $charge)",
    "Storage charges must not silently discard uncovered PAYG cost.",
)
reject(
    gateway,
    "min($balance, $chargeMicrousd)",
    "Transfer charges must not silently discard uncovered PAYG cost.",
)

# FREE/PAYG limits.
require(commercial, "MODE_FREE = 'free'", "FREE mode contract is missing.")
require(commercial, "MODE_PAYG = 'payg'", "PAYG mode contract is missing.")
require(commercial, "MODE_LEGACY = 'legacy'", "Legacy compatibility mode is missing.")
require(server, "minimum_recharge_microusd", "Wallet recharge minimum is not enforced.")
require(server, "free_installation_limit", "FREE installation limit is not enforced.")


# Executable domain policy must cover activation, PAYG, debt and refund state.
for needle, message in (
    ("afterCredit", "Commercial credit transition is missing."),
    ("afterDebit", "Commercial debit transition is missing."),
    ("afterRechargeReversal", "Recharge reversal transition is missing."),
    ("nextRechargeSettlementVersion", "Recharge payment cycle transition is missing."),
    ("rechargeReversalVersion", "Recharge reversal cycle transition is missing."),
    ("nextActivationSettlementVersion", "Activation settlement transition is missing."),
):
    require(policy, needle, message)
require(money, "decimalToMicrounits", "Exact invoice decimal parsing is missing.")
require(money, "microunitsToDecimal", "Exact invoice decimal formatting is missing.")

print("Elearning Stream commercial/wallet invariants: PASS")
