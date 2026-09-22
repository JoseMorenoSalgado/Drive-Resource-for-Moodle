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
            $usage['dr-' . (int) $row->service_id] = $this->withStorage((int) $row->used_bytes);
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
        $serviceId = (int) ($this->moduleParams['serviceid'] ?? 0);
        $row = Capsule::table('mod_driveresource_services')
            ->where('service_id', $serviceId)
            ->first();

        return $this->withStorage($row ? (int) $row->used_bytes : 0);
    }

    /**
     * Attach usage to the metric.
     *
     * @param int $bytes Storage bytes.
     * @return MetricInterface[]
     */
    private function withStorage(int $bytes): array
    {
        $gb = max(0, $bytes) / 1073741824;

        return [
            $this->metrics()[0]->withUsage(new Usage($gb)),
        ];
    }
}
