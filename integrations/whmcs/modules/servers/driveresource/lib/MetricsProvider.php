<?php

namespace WHMCS\Module\Server\Driveresource;

use WHMCS\Database\Capsule;
use WHMCS\UsageBilling\Contracts\Metrics\MetricInterface;
use WHMCS\UsageBilling\Contracts\Metrics\ProviderInterface;
use WHMCS\UsageBilling\Metrics\Metric;
use WHMCS\UsageBilling\Metrics\Units\GigaBytes;
use WHMCS\UsageBilling\Metrics\Usage;

/**
 * WHMCS Usage Billing metrics for Drive Resource.
 */
final class MetricsProvider implements ProviderInterface
{
    /** @var array */
    private array $moduleParams;

    /**
     * @param array $moduleParams Standard WHMCS module params.
     */
    public function __construct(array $moduleParams)
    {
        $this->moduleParams = $moduleParams;
    }

    /**
     * Available billable metrics.
     *
     * @return MetricInterface[]
     */
    public function metrics()
    {
        return [
            new Metric(
                'video_storage_gb',
                'Video Storage',
                MetricInterface::TYPE_SNAPSHOT,
                new GigaBytes('GB')
            ),
            new Metric(
                'video_transfer_gb',
                'Video Transfer',
                MetricInterface::TYPE_PERIOD_MONTH,
                new GigaBytes('GB')
            ),
        ];
    }

    /**
     * Collect usage for all Drive Resource tenants.
     *
     * @return array
     */
    public function usage()
    {
        $rows = Capsule::table('mod_driveresource_services')->get();
        $usage = [];
        foreach ($rows as $row) {
            $usage['dr-' . (int) $row->service_id] = $this->withUsage(
                (int) $row->used_bytes,
                $this->currentTransferBytes($row)
            );
        }

        return $usage;
    }

    /**
     * Collect usage for one service.
     *
     * @param string $tenant WHMCS tenant/username.
     * @return MetricInterface[]
     */
    public function tenantUsage($tenant)
    {
        $tenant = (string) $tenant;
        $serviceId = 0;
        if (preg_match('/^dr-(\d+)$/', $tenant, $matches)) {
            $serviceId = (int) $matches[1];
        }

        if ($serviceId <= 0) {
            return $this->withUsage(0, 0);
        }

        $row = Capsule::table('mod_driveresource_services')
            ->where('service_id', $serviceId)
            ->first();

        return $this->withUsage(
            $row ? (int) $row->used_bytes : 0,
            $row ? $this->currentTransferBytes($row) : 0
        );
    }

    /**
     * Attach storage and current-month transfer usage.
     *
     * @param int $storageBytes Storage bytes.
     * @param int $transferBytes Transfer bytes for the current UTC month.
     * @return MetricInterface[]
     */
    private function withUsage(int $storageBytes, int $transferBytes): array
    {
        $metrics = $this->metrics();

        return [
            $metrics[0]->withUsage(new Usage(max(0, $storageBytes) / 1000000000)),
            $metrics[1]->withUsage(new Usage(max(0, $transferBytes) / 1000000000)),
        ];
    }

    /**
     * Return transfer only when the stored counter belongs to this month.
     *
     * @param object $row Service row.
     * @return int
     */
    private function currentTransferBytes(object $row): int
    {
        return (string) ($row->transfer_period ?? '') === gmdate('Y-m')
            ? max(0, (int) ($row->transfer_bytes ?? 0))
            : 0;
    }
}
