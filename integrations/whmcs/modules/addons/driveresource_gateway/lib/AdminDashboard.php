<?php

namespace WHMCS\Module\Addon\DriveresourceGateway;

use WHMCS\Database\Capsule;

/**
 * Read-only multi-tenant operations dashboard for WHMCS administrators.
 */
final class AdminDashboard
{
    private const PAGE_SIZE = 25;

    /**
     * Render the central customer/service dashboard.
     *
     * @return void
     */
    public function render(): void
    {
        $query = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 120);
        $status = strtolower(trim((string) ($_GET['status'] ?? '')));
        if (!in_array($status, ['', 'active', 'suspended', 'terminated'], true)) {
            $status = '';
        }

        $backend = BackendRegistry::normalize((string) ($_GET['backend'] ?? ''));
        if (!isset(BackendRegistry::definitions()[$backend])) {
            $backend = '';
        }
        if (trim((string) ($_GET['backend'] ?? '')) === '') {
            $backend = '';
        }

        $page = max(1, (int) ($_GET['p'] ?? 1));
        $baseQuery = Capsule::table('mod_driveresource_services as s')
            ->leftJoin('tblhosting as h', 'h.id', '=', 's.service_id')
            ->leftJoin('tblclients as c', 'c.id', '=', 'h.userid')
            ->leftJoin('tblproducts as p', 'p.id', '=', 'h.packageid');

        if ($status !== '') {
            $baseQuery->where('s.status', $status);
        }
        if ($backend !== '') {
            $baseQuery->where('s.backend_key', $backend);
        }
        if ($query !== '') {
            $baseQuery->where(static function ($builder) use ($query): void {
                $like = '%' . $query . '%';
                $builder->where('s.site_url', 'like', $like)
                    ->orWhere('c.firstname', 'like', $like)
                    ->orWhere('c.lastname', 'like', $like)
                    ->orWhere('c.companyname', 'like', $like)
                    ->orWhere('c.email', 'like', $like)
                    ->orWhere('p.name', 'like', $like);

                if (ctype_digit($query)) {
                    $builder->orWhere('s.service_id', (int) $query);
                }
            });
        }

        $total = (int) (clone $baseQuery)->count('s.service_id');
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min($page, $pages);

        $rows = $baseQuery
            ->select([
                's.service_id',
                's.site_url',
                's.status',
                's.backend_key',
                's.backend_profile',
                's.quota_bytes',
                's.used_bytes',
                's.reserved_bytes',
                's.overage_allowed',
                's.updated_at',
                'h.userid',
                'h.domainstatus',
                'p.name as product_name',
                'c.firstname',
                'c.lastname',
                'c.companyname',
                'c.email',
            ])
            ->orderBy('s.service_id', 'desc')
            ->offset(($page - 1) * self::PAGE_SIZE)
            ->limit(self::PAGE_SIZE)
            ->get();

        $serviceIds = [];
        foreach ($rows as $row) {
            $serviceIds[] = (int) $row->service_id;
        }

        $assetCounts = [];
        if ($serviceIds !== []) {
            $counts = Capsule::table('mod_driveresource_uploads')
                ->select(['service_id', Capsule::raw('COUNT(*) AS total')])
                ->whereIn('service_id', $serviceIds)
                ->where('status', '<>', 'deleted')
                ->groupBy('service_id')
                ->get();
            foreach ($counts as $count) {
                $assetCounts[(int) $count->service_id] = (int) $count->total;
            }
        }

        $summary = $this->summary();
        $this->renderSummary($summary);
        $this->renderFilters($query, $status, $backend);
        $this->renderTable($rows, $assetCounts);
        $this->renderPagination($page, $pages, $query, $status, $backend);
        $this->renderRoadmap();
    }

    /**
     * Global multi-tenant summary.
     *
     * @return array{services:int,active:int,clients:int,used:int,quota:int,overage:int,videos:int}
     */
    private function summary(): array
    {
        $services = (int) Capsule::table('mod_driveresource_services')->count();
        $active = (int) Capsule::table('mod_driveresource_services')
            ->where('status', 'active')
            ->count();
        $used = (int) Capsule::table('mod_driveresource_services')->sum('used_bytes');
        $quota = (int) Capsule::table('mod_driveresource_services')->sum('quota_bytes');
        $overage = (int) Capsule::table('mod_driveresource_services')
            ->whereRaw('used_bytes > quota_bytes')
            ->count();
        $videos = (int) Capsule::table('mod_driveresource_uploads')
            ->where('status', '<>', 'deleted')
            ->count();
        $clients = (int) Capsule::table('mod_driveresource_services as s')
            ->join('tblhosting as h', 'h.id', '=', 's.service_id')
            ->distinct()
            ->count('h.userid');

        return compact('services', 'active', 'clients', 'used', 'quota', 'overage', 'videos');
    }

    /**
     * @param array $summary Summary values.
     * @return void
     */
    private function renderSummary(array $summary): void
    {
        echo '<div class="row">';
        $this->metric('Customers', (string) $summary['clients']);
        $this->metric('Provisioned services', (string) $summary['services']);
        $this->metric('Active services', (string) $summary['active']);
        $this->metric('Tracked assets', (string) $summary['videos']);
        $this->metric('Storage used', $this->gb((int) $summary['used']) . ' GB');
        $this->metric('Included capacity', $this->gb((int) $summary['quota']) . ' GB');
        $this->metric('Services in overage', (string) $summary['overage']);
        echo '</div>';
    }

    /**
     * @param string $label Metric label.
     * @param string $value Metric value.
     * @return void
     */
    private function metric(string $label, string $value): void
    {
        echo '<div class="col-md-3 col-sm-6">';
        echo '<div class="panel panel-default"><div class="panel-body">';
        echo '<div style="font-size:12px;color:#6b7280">' . $this->e($label) . '</div>';
        echo '<div style="font-size:24px;font-weight:600">' . $this->e($value) . '</div>';
        echo '</div></div></div>';
    }

    /**
     * @return void
     */
    private function renderFilters(string $query, string $status, string $backend): void
    {
        echo '<form method="get" class="form-inline" style="margin:0 0 16px">';
        echo '<input type="hidden" name="module" value="driveresource_gateway">';
        echo '<div class="form-group" style="margin-right:8px">';
        echo '<input class="form-control" type="search" name="q" value="' . $this->e($query)
            . '" placeholder="Client, email, Moodle URL or service ID">';
        echo '</div>';

        echo '<div class="form-group" style="margin-right:8px"><select class="form-control" name="status">';
        foreach (['' => 'All statuses', 'active' => 'Active', 'suspended' => 'Suspended', 'terminated' => 'Terminated'] as $key => $label) {
            echo '<option value="' . $this->e($key) . '"' . ($status === $key ? ' selected' : '') . '>'
                . $this->e($label) . '</option>';
        }
        echo '</select></div>';

        echo '<div class="form-group" style="margin-right:8px"><select class="form-control" name="backend">';
        echo '<option value="">All backends</option>';
        foreach (BackendRegistry::definitions() as $key => $definition) {
            echo '<option value="' . $this->e($key) . '"' . ($backend === $key ? ' selected' : '') . '>'
                . $this->e($definition['label']) . '</option>';
        }
        echo '</select></div>';

        echo '<button type="submit" class="btn btn-primary">Filter</button> ';
        echo '<a class="btn btn-default" href="addonmodules.php?module=driveresource_gateway">Reset</a>';
        echo '</form>';
    }

    /**
     * @param iterable $rows Service rows.
     * @param array<int,int> $assetCounts Video counts keyed by service id.
     * @return void
     */
    private function renderTable(iterable $rows, array $assetCounts): void
    {
        echo '<div class="panel panel-default">';
        echo '<div class="panel-heading"><strong>Drive Resource customers</strong></div>';
        echo '<div class="table-responsive"><table class="table table-striped table-hover" style="margin-bottom:0">';
        echo '<thead><tr>';
        foreach (['Service', 'Customer', 'Moodle site', 'Product', 'Backend', 'Storage', 'Assets', 'Status', 'Actions'] as $heading) {
            echo '<th>' . $this->e($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';

        $hasRows = false;
        foreach ($rows as $row) {
            $hasRows = true;
            $serviceId = (int) $row->service_id;
            $used = (int) $row->used_bytes;
            $quota = max(1, (int) $row->quota_bytes);
            $reserved = (int) $row->reserved_bytes;
            $percentage = min(999, (int) round(($used / $quota) * 100));
            $overage = max(0, $used - $quota);
            $customer = trim((string) $row->companyname);
            if ($customer === '') {
                $customer = trim((string) $row->firstname . ' ' . (string) $row->lastname);
            }
            if ($customer === '') {
                $customer = 'WHMCS client #' . (int) $row->userid;
            }

            echo '<tr>';
            echo '<td><strong>#' . $serviceId . '</strong></td>';
            echo '<td>' . $this->e($customer) . '<br><small>' . $this->e((string) $row->email) . '</small></td>';
            echo '<td><span title="' . $this->e((string) $row->site_url) . '">'
                . $this->e($this->truncate((string) $row->site_url, 44)) . '</span></td>';
            echo '<td>' . $this->e((string) ($row->product_name ?: '—')) . '</td>';
            echo '<td>' . $this->e(BackendRegistry::label((string) $row->backend_key))
                . '<br><small>Profile: ' . $this->e((string) $row->backend_profile) . '</small></td>';
            echo '<td>' . $this->e($this->gb($used)) . ' / ' . $this->e($this->gb($quota)) . ' GB'
                . '<br><small>' . $percentage . '%';
            if ($reserved > 0) {
                echo ' · ' . $this->e($this->gb($reserved)) . ' GB reserved';
            }
            if ($overage > 0) {
                echo ' · ' . $this->e($this->gb($overage)) . ' GB overage';
            }
            echo '</small></td>';
            echo '<td>' . (int) ($assetCounts[$serviceId] ?? 0) . '</td>';
            echo '<td>' . $this->statusBadge((string) $row->status) . '</td>';
            echo '<td><a class="btn btn-xs btn-default" href="clientsservices.php?id='
                . $serviceId . '">Open service</a></td>';
            echo '</tr>';
        }

        if (!$hasRows) {
            echo '<tr><td colspan="9" class="text-center text-muted" style="padding:30px">No services match the current filters.</td></tr>';
        }

        echo '</tbody></table></div></div>';
    }

    /**
     * @return void
     */
    private function renderPagination(int $page, int $pages, string $query, string $status, string $backend): void
    {
        if ($pages <= 1) {
            return;
        }

        echo '<nav><ul class="pagination">';
        $start = max(1, $page - 3);
        $end = min($pages, $page + 3);
        for ($current = $start; $current <= $end; $current++) {
            $params = http_build_query([
                'module' => 'driveresource_gateway',
                'q' => $query,
                'status' => $status,
                'backend' => $backend,
                'p' => $current,
            ]);
            echo '<li' . ($current === $page ? ' class="active"' : '') . '><a href="addonmodules.php?'
                . $this->e($params) . '">' . $current . '</a></li>';
        }
        echo '</ul></nav>';
    }

    /**
     * Render planned backend extensibility without exposing an unusable product.
     *
     * @return void
     */
    private function renderRoadmap(): void
    {
        echo '<div class="panel panel-info"><div class="panel-heading"><strong>Storage backends</strong></div>';
        echo '<div class="panel-body">';
        echo '<p><strong>Active:</strong> Elearning Stream — managed video, direct upload and protected playback.</p>';
        echo '<p><strong>Architecture reserved:</strong> S3-compatible Object Storage — future PDFs, documents, images, audio and/or video objects using the same WHMCS tenant, quota and billing model.</p>';
        echo '<p class="text-muted" style="margin-bottom:0">S3-compatible storage is intentionally not provisionable until its multipart upload, signed delivery, metering and lifecycle adapter passes the same security gates.</p>';
        echo '</div></div>';
    }

    /**
     * @param string $status Service status.
     * @return string
     */
    private function statusBadge(string $status): string
    {
        $class = 'label-default';
        if ($status === 'active') {
            $class = 'label-success';
        } else if ($status === 'suspended') {
            $class = 'label-warning';
        } else if ($status === 'terminated') {
            $class = 'label-danger';
        }

        return '<span class="label ' . $class . '">' . $this->e(ucfirst($status)) . '</span>';
    }

    /**
     * @param int $bytes Bytes.
     * @return string
     */
    private function gb(int $bytes): string
    {
        return number_format(max(0, $bytes) / 1000000000, 2);
    }

    /**
     * @param string $value Value.
     * @return string
     */
    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param string $value Value.
     * @param int $length Max characters.
     * @return string
     */
    private function truncate(string $value, int $length): string
    {
        return mb_strlen($value) <= $length ? $value : mb_substr($value, 0, $length - 1) . '…';
    }
}
