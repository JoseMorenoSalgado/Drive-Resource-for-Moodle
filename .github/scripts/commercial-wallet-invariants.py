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
    "'activation_verified' => $activationeligible",
    "Commercial account creation must derive activation from the CreateAccount boundary.",
)
require(
    server,
    "$activationeligible\n        && (bool) $account->activation_verified",
    "Activation credit must be gated by the CreateAccount boundary.",
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
    addon,
    "'billing_mode' => 'legacy'",
    "Pre-0.6 services must be backfilled as legacy accounts.",
)
require(
    addon,
    "'activation_amount_microusd' => 0",
    "Legacy migration must not fabricate activation wallet credit.",
)
for table in (
    "mod_driveresource_accounts",
    "mod_driveresource_installations",
    "mod_driveresource_wallet_ledger",
    "mod_driveresource_wallet_orders",
    "mod_driveresource_usage_daily",
):
    require(addon, table, f"Commercial schema is missing {table}.")

# Wallet idempotency and invoice lifecycle.
require(wallet, "->where('idempotency_key', $idempotencyKey)", "Wallet operations must be idempotent.")
require(wallet, "->lockForUpdate()", "Wallet account/ledger mutation must be serialized.")
require(hooks, "->where('status', 'pending')", "InvoicePaid must only credit pending recharge orders.")
require(hooks, "hash('sha256', 'wallet-credit|'", "Recharge credit key must be deterministic.")
require(hooks, "hash('sha256', 'wallet-reversal|'", "Recharge reversal key must be deterministic.")
require(hooks, "add_hook('InvoicePaid'", "InvoicePaid wallet hook is missing.")
require(hooks, "add_hook('InvoiceRefunded'", "InvoiceRefunded wallet hook is missing.")
require(hooks, "add_hook('InvoiceUnpaid'", "InvoiceUnpaid wallet hook is missing.")

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

print("Elearning Stream commercial/wallet invariants: PASS")
