<?php

namespace WHMCS\Module\Addon\DriveresourceGateway;

use RuntimeException;

/**
 * Pure commercial wallet state transitions.
 *
 * This class deliberately has no database dependency so the same rules used
 * by production wallet mutations can be executed in CI without bootstrapping
 * WHMCS.
 */
final class CommercialPolicy
{
    /**
     * Calculate state after a positive wallet credit.
     *
     * @param int $balanceMicrousd Current balance.
     * @param string $mode Current commercial mode.
     * @param string $status Current account status.
     * @param int|null $paidAt First PAYG timestamp.
     * @param int $amountMicrousd Positive credit amount.
     * @param string $entryType Ledger entry type.
     * @param int $minimumRechargeMicrousd PAYG promotion threshold.
     * @param int $now Current timestamp.
     * @return array{balance:int,mode:string,status:string,paidat:int|null}
     */
    public static function afterCredit(
        int $balanceMicrousd,
        string $mode,
        string $status,
        ?int $paidAt,
        int $amountMicrousd,
        string $entryType,
        int $minimumRechargeMicrousd,
        int $now
    ): array {
        if ($amountMicrousd <= 0) {
            throw new RuntimeException('Commercial credit must be positive.');
        }

        $balance = $balanceMicrousd + $amountMicrousd;
        if ($entryType === 'recharge' && $amountMicrousd >= $minimumRechargeMicrousd) {
            $mode = CommercialAccount::MODE_PAYG;

            if ($balance <= 0) {
                $status = CommercialAccount::STATUS_UPLOAD_RESTRICTED;
            } else if (in_array($status, [
                CommercialAccount::STATUS_UPLOAD_RESTRICTED,
                CommercialAccount::STATUS_GRACE_PERIOD,
                CommercialAccount::STATUS_LOW_BALANCE,
            ], true)) {
                $status = CommercialAccount::STATUS_ACTIVE;
            }

            if (empty($paidAt)) {
                $paidAt = $now;
            }
        }

        return [
            'balance' => $balance,
            'mode' => $mode,
            'status' => $status,
            'paidat' => $paidAt,
        ];
    }

    /**
     * Calculate state after a wallet debit.
     *
     * @param int $balanceMicrousd Current balance.
     * @param string $status Current account status.
     * @param int $amountMicrousd Positive debit amount.
     * @return array{balance:int,status:string}
     */
    public static function afterDebit(
        int $balanceMicrousd,
        string $status,
        int $amountMicrousd
    ): array {
        if ($amountMicrousd <= 0) {
            throw new RuntimeException('Commercial debit must be positive.');
        }

        $balance = $balanceMicrousd - $amountMicrousd;
        if ($balance <= 0) {
            $status = CommercialAccount::STATUS_UPLOAD_RESTRICTED;
        }

        return [
            'balance' => $balance,
            'status' => $status,
        ];
    }

    /**
     * Resolve the next payment settlement version for one recharge order.
     *
     * Pending orders settle for the first time. Orders reversed by an Unpaid
     * transition may settle again if WHMCS later marks the same invoice Paid.
     * Refunded orders are terminal and require a new recharge order.
     *
     * @param string $orderStatus Current wallet-order status.
     * @param int $settlementVersion Current settlement version.
     * @return int|null Next version, or null when payment must not apply.
     */
    public static function nextRechargeSettlementVersion(
        string $orderStatus,
        int $settlementVersion
    ): ?int {
        if ($settlementVersion < 0) {
            throw new RuntimeException('Recharge settlement version cannot be negative.');
        }

        if (!in_array($orderStatus, ['pending', 'reversed'], true)) {
            return null;
        }

        return $settlementVersion + 1;
    }

    /**
     * Resolve the version that a refund/unpaid transition must reverse.
     *
     * @param string $orderStatus Current wallet-order status.
     * @param int $settlementVersion Current settlement version.
     * @return int|null Version to reverse, or null when no paid settlement exists.
     */
    public static function rechargeReversalVersion(
        string $orderStatus,
        int $settlementVersion
    ): ?int {
        if ($settlementVersion < 0) {
            throw new RuntimeException('Recharge settlement version cannot be negative.');
        }

        return $orderStatus === 'paid' ? $settlementVersion : null;
    }

    /**
     * Remove PAYG entitlement after the final paid recharge is reversed.
     *
     * FREE access remains active when no debt is outstanding. A negative
     * wallet remains restricted until a later recharge covers the debt.
     *
     * @param int $balanceMicrousd Current balance after reversal.
     * @param string $mode Current commercial mode.
     * @param string $status Current account status.
     * @param int|null $paidAt First PAYG timestamp.
     * @param int $remainingPaidRecharges Number of still-paid recharge orders.
     * @return array{mode:string,status:string,paidat:int|null}
     */
    public static function afterRechargeReversal(
        int $balanceMicrousd,
        string $mode,
        string $status,
        ?int $paidAt,
        int $remainingPaidRecharges
    ): array {
        if ($remainingPaidRecharges < 0) {
            throw new RuntimeException('Paid recharge count cannot be negative.');
        }

        if ($remainingPaidRecharges === 0 && $mode === CommercialAccount::MODE_PAYG) {
            $mode = CommercialAccount::MODE_FREE;
            $paidAt = null;
            $status = $balanceMicrousd < 0
                ? CommercialAccount::STATUS_UPLOAD_RESTRICTED
                : CommercialAccount::STATUS_ACTIVE;
        }

        return [
            'mode' => $mode,
            'status' => $status,
            'paidat' => $paidAt,
        ];
    }
}
