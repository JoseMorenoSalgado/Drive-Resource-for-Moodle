<?php

namespace WHMCS\Module\Addon\DriveresourceGateway;

use RuntimeException;
use WHMCS\Database\Capsule;

/**
 * Idempotent prepaid-wallet ledger.
 *
 * Monetary values are stored as integer micro-USD (1 USD = 1,000,000) to avoid
 * floating-point rounding in usage charging and prorated storage calculations.
 */
final class WalletService
{
    /**
     * Add credit to one account.
     *
     * A qualifying recharge promotes a FREE account to PAYG. Activation credit
     * is intentionally not considered a PAYG recharge.
     *
     * @param int $serviceId WHMCS service id.
     * @param int $amountMicrousd Positive credit amount.
     * @param string $entryType Stable ledger event type.
     * @param string $idempotencyKey Globally unique operation key.
     * @param string|null $externalRef WHMCS invoice/transaction reference.
     * @param array $metadata Non-secret audit metadata.
     * @return int New balance in micro-USD.
     */
    public function credit(
        int $serviceId,
        int $amountMicrousd,
        string $entryType,
        string $idempotencyKey,
        ?string $externalRef = null,
        array $metadata = []
    ): int {
        if ($serviceId <= 0 || $amountMicrousd <= 0) {
            throw new RuntimeException('Wallet credit requires a valid account and positive amount.');
        }

        $entryType = $this->normalizeEntryType($entryType);
        $idempotencyKey = $this->normalizeIdempotencyKey($idempotencyKey);

        return Capsule::connection()->transaction(function () use (
            $serviceId,
            $amountMicrousd,
            $entryType,
            $idempotencyKey,
            $externalRef,
            $metadata
        ): int {
            $existing = Capsule::table('mod_driveresource_wallet_ledger')
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                return (int) $existing->balance_after_microusd;
            }

            $account = Capsule::table('mod_driveresource_accounts')
                ->where('service_id', $serviceId)
                ->lockForUpdate()
                ->first();
            if (!$account) {
                throw new RuntimeException('Elearning Stream commercial account was not found.');
            }

            $balance = (int) $account->balance_microusd + $amountMicrousd;
            $mode = (string) $account->billing_mode;
            $status = (string) $account->status;

            if (
                $entryType === 'recharge'
                && $amountMicrousd >= (int) $account->minimum_recharge_microusd
            ) {
                $mode = CommercialAccount::MODE_PAYG;
                if ($balance > 0 && in_array($status, [
                    CommercialAccount::STATUS_UPLOAD_RESTRICTED,
                    CommercialAccount::STATUS_GRACE_PERIOD,
                    CommercialAccount::STATUS_LOW_BALANCE,
                ], true)) {
                    $status = CommercialAccount::STATUS_ACTIVE;
                } else if ($balance <= 0) {
                    $status = CommercialAccount::STATUS_UPLOAD_RESTRICTED;
                }
            }

            $now = time();
            Capsule::table('mod_driveresource_accounts')
                ->where('service_id', $serviceId)
                ->update([
                    'balance_microusd' => $balance,
                    'billing_mode' => $mode,
                    'status' => $status,
                    'paid_at' => $mode === CommercialAccount::MODE_PAYG
                        ? ($account->paid_at ?: $now)
                        : $account->paid_at,
                    'updated_at' => $now,
                ]);

            Capsule::table('mod_driveresource_wallet_ledger')->insert([
                'service_id' => $serviceId,
                'entry_type' => $entryType,
                'amount_microusd' => $amountMicrousd,
                'balance_after_microusd' => $balance,
                'currency' => 'USD',
                'idempotency_key' => $idempotencyKey,
                'external_ref' => $externalRef !== null ? mb_substr($externalRef, 0, 191) : null,
                'metadata_json' => $metadata !== []
                    ? json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    : null,
                'created_at' => $now,
            ]);

            return $balance;
        });
    }

    /**
     * Debit an earned charge while preserving any resulting prepaid-wallet debt.
     *
     * @param int $serviceId WHMCS service id.
     * @param int $amountMicrousd Positive debit amount.
     * @param string $entryType Stable ledger event type.
     * @param string $idempotencyKey Globally unique operation key.
     * @param array $metadata Non-secret audit metadata.
     * @return int New balance in micro-USD.
     */
    public function debit(
        int $serviceId,
        int $amountMicrousd,
        string $entryType,
        string $idempotencyKey,
        array $metadata = []
    ): int {
        if ($amountMicrousd <= 0) {
            throw new RuntimeException('Wallet debit requires a positive amount.');
        }

        $entryType = $this->normalizeEntryType($entryType);
        $idempotencyKey = $this->normalizeIdempotencyKey($idempotencyKey);

        return Capsule::connection()->transaction(function () use (
            $serviceId,
            $amountMicrousd,
            $entryType,
            $idempotencyKey,
            $metadata
        ): int {
            $existing = Capsule::table('mod_driveresource_wallet_ledger')
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                return (int) $existing->balance_after_microusd;
            }

            $account = Capsule::table('mod_driveresource_accounts')
                ->where('service_id', $serviceId)
                ->lockForUpdate()
                ->first();
            if (!$account) {
                throw new RuntimeException('Elearning Stream commercial account was not found.');
            }

            // Reversals must preserve debt. If previously consumed credit is
            // refunded, the negative balance is carried forward and future
            // recharges must cover it before paid overage is available again.
            $balance = (int) $account->balance_microusd - $amountMicrousd;
            $status = $balance > 0
                ? (string) $account->status
                : CommercialAccount::STATUS_UPLOAD_RESTRICTED;
            $now = time();

            Capsule::table('mod_driveresource_accounts')
                ->where('service_id', $serviceId)
                ->update([
                    'balance_microusd' => $balance,
                    'status' => $status,
                    'updated_at' => $now,
                ]);

            Capsule::table('mod_driveresource_wallet_ledger')->insert([
                'service_id' => $serviceId,
                'entry_type' => $entryType,
                'amount_microusd' => -$amountMicrousd,
                'balance_after_microusd' => $balance,
                'currency' => 'USD',
                'idempotency_key' => $idempotencyKey,
                'external_ref' => null,
                'metadata_json' => $metadata !== []
                    ? json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    : null,
                'created_at' => $now,
            ]);

            return $balance;
        });
    }

    /**
     * @param string $value Entry type.
     * @return string
     */
    private function normalizeEntryType(string $value): string
    {
        $value = strtolower(trim($value));
        if (!preg_match('/^[a-z][a-z0-9_]{1,31}$/', $value)) {
            throw new RuntimeException('Invalid wallet ledger entry type.');
        }

        return $value;
    }

    /**
     * @param string $value Idempotency key.
     * @return string
     */
    private function normalizeIdempotencyKey(string $value): string
    {
        $value = strtolower(trim($value));
        if (!preg_match('/^[a-z0-9][a-z0-9:_-]{7,63}$/', $value)) {
            throw new RuntimeException('Invalid wallet idempotency key.');
        }

        return $value;
    }
}
