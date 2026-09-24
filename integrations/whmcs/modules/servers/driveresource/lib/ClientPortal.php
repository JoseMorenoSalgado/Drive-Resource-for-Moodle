<?php

namespace WHMCS\Module\Server\Driveresource;

use WHMCS\Database\Capsule;

/**
 * Client-facing Elearning Stream service dashboard.
 */
final class ClientPortal
{
    /** @var int Videos displayed per client-area page. */
    private const VIDEO_PAGE_SIZE = 25;

    /** @var array Standard WHMCS module parameters. */
    private array $params;

    /** @var Translator Module-local translator. */
    private Translator $translator;

    /**
     * @param array $params WHMCS module parameters.
     */
    public function __construct(array $params)
    {
        $this->params = $params;
        $this->translator = Translator::fromParams($params);
    }

    /**
     * Render the self-service dashboard.
     *
     * @return string
     */
    public function render(): string
    {
        $serviceId = (int) ($this->params['serviceid'] ?? 0);
        $service = Capsule::table('mod_driveresource_services')
            ->where('service_id', $serviceId)
            ->first();

        if (!$service) {
            return '<div class="alert alert-warning">'
                . $this->e($this->translator->t('service_not_provisioned'))
                . '</div>';
        }

        $token = $this->serviceToken();
        $period = gmdate('Y-m');
        $transferBytes = (string) ($service->transfer_period ?? '') === $period
            ? max(0, (int) ($service->transfer_bytes ?? 0))
            : 0;
        $usedBytes = max(0, (int) $service->used_bytes);
        $reservedBytes = max(0, (int) $service->reserved_bytes);
        $quotaBytes = max(1, (int) $service->quota_bytes);
        $storagePercent = min(999, (int) round(($usedBytes / $quotaBytes) * 100));

        $videoPage = max(1, (int) ($_GET['drpage'] ?? 1));
        $videoTotal = (int) Capsule::table('mod_driveresource_uploads')
            ->where('service_id', $serviceId)
            ->where('status', '<>', 'deleted')
            ->count();
        $videoPages = max(1, (int) ceil($videoTotal / self::VIDEO_PAGE_SIZE));
        $videoPage = min($videoPage, $videoPages);

        $uploads = Capsule::table('mod_driveresource_uploads')
            ->where('service_id', $serviceId)
            ->where('status', '<>', 'deleted')
            ->orderBy('created_at', 'desc')
            ->offset(($videoPage - 1) * self::VIDEO_PAGE_SIZE)
            ->limit(self::VIDEO_PAGE_SIZE)
            ->get();

        $refCounts = [];
        $pageVideoIds = [];
        foreach ($uploads as $upload) {
            if (!empty($upload->video_id)) {
                $pageVideoIds[] = (string) $upload->video_id;
            }
        }
        if ($pageVideoIds !== []) {
            $refs = Capsule::table('mod_driveresource_asset_refs')
                ->select(['video_id', Capsule::raw('COUNT(*) AS total')])
                ->where('service_id', $serviceId)
                ->whereIn('video_id', array_values(array_unique($pageVideoIds)))
                ->where('active', true)
                ->groupBy('video_id')
                ->get();
            foreach ($refs as $ref) {
                $refCounts[(string) $ref->video_id] = (int) $ref->total;
            }
        }

        $connection = strtolower((string) ($service->connection_status ?? 'pending'));
        $connectionLabel = $this->translator->t('connection_pending');
        $connectionClass = 'warning';
        if ($connection === 'connected') {
            $connectionLabel = $this->translator->t('connection_connected');
            $connectionClass = 'success';
        } else if ($connection === 'failed') {
            $connectionLabel = $this->translator->t('connection_failed');
            $connectionClass = 'danger';
        }

        $checked = !empty($service->connection_checked_at)
            ? date('Y-m-d H:i', (int) $service->connection_checked_at)
            : $this->translator->t('connection_unvalidated');
        $connectionMessage = trim((string) ($service->connection_message ?? ''));
        if ($connectionMessage !== '' && $this->translator->has($connectionMessage)) {
            $connectionMessage = $this->translator->t($connectionMessage);
        }

        $html = $this->styles();
        $html .= '<div class="dr-portal" data-dr-service-id="' . $serviceId . '">';
        $html .= '<div class="dr-grid">';
        $html .= $this->card(
            $this->translator->t('card_connection'),
            '<span class="label label-' . $connectionClass . '">' . $connectionLabel . '</span>',
            $checked . ($connectionMessage !== '' ? '<br>' . $this->e($connectionMessage) : '')
        );
        $html .= $this->card(
            $this->translator->t('card_storage'),
            $this->formatBytes($usedBytes) . ' / ' . $this->formatBytes($quotaBytes),
            $this->translator->t('storage_used', ['percent' => $storagePercent])
                . ($reservedBytes > 0
                    ? ' · ' . $this->translator->t(
                        'storage_reserved',
                        ['size' => $this->formatBytes($reservedBytes)]
                    )
                    : '')
        );
        $html .= $this->card(
            $this->translator->t('card_transfer', ['period' => $period]),
            $this->formatBytes($transferBytes),
            $this->translator->t('transfer_meta')
        );
        $html .= $this->card(
            $this->translator->t('card_videos'),
            (string) $videoTotal,
            $this->translator->t('videos_meta')
        );
        $html .= '</div>';

        $html .= '<div class="panel panel-default dr-panel">';
        $html .= '<div class="panel-heading"><strong>'
            . $this->e($this->translator->t('connect_moodle')) . '</strong></div>';
        $html .= '<div class="panel-body">';

        $html .= '<form method="post" action="' . $this->formAction() . '" class="dr-form">';
        $html .= $this->customActionFields('UpdateMoodleUrl');
        $html .= '<div class="form-group">';
        $html .= '<label for="dr-moodle-url">'
            . $this->e($this->translator->t('moodle_url')) . '</label>';
        $html .= '<div class="input-group">';
        $html .= '<input id="dr-moodle-url" name="moodleurl" type="url" class="form-control" required '
            . 'placeholder="https://campus.ejemplo.com" value="' . $this->e((string) $service->site_url) . '">';
        $html .= '<span class="input-group-btn"><button class="btn btn-primary" type="submit">'
            . $this->e($this->translator->t('save_url')) . '</button></span>';
        $html .= '</div>';
        $html .= '<p class="help-block">' . $this->e($this->translator->t('moodle_url_help')) . '</p>';
        $html .= '</div></form>';

        $html .= '<div class="form-group">';
        $html .= '<label>' . $this->e($this->translator->t('service_id')) . '</label>';
        $html .= '<input class="form-control" type="text" readonly value="' . $serviceId . '">';
        $html .= '</div>';

        $html .= '<div class="form-group">';
        $html .= '<label>' . $this->e($this->translator->t('service_token')) . '</label>';
        $html .= '<div class="input-group">';
        $html .= '<input id="dr-service-token" class="form-control" type="password" readonly value="'
            . $this->e($token) . '" placeholder="' . $this->e($this->translator->t('token_missing')) . '">';
        $html .= '<span class="input-group-btn">';
        $html .= '<button type="button" class="btn btn-default" onclick="drToggleToken()">'
            . $this->e($this->translator->t('show')) . '</button>';
        $html .= '<button type="button" class="btn btn-default" onclick="drCopyToken()">'
            . $this->e($this->translator->t('copy')) . '</button>';
        $html .= '</span></div></div>';

        $html .= '<div class="dr-actions">';
        $html .= $this->actionForm(
            $token === '' ? 'ProvisionMoodleConnection' : 'RotateMoodleToken',
            $token === ''
                ? $this->translator->t('generate_token')
                : $this->translator->t('generate_new_key'),
            $token === '' ? 'btn btn-primary' : 'btn btn-warning',
            $token !== '' ? $this->translator->t('rotate_confirm') : ''
        );
        $html .= $this->actionForm(
            'ValidateMoodleConnection',
            $this->translator->t('validate_connection'),
            'btn btn-success'
        );
        $html .= '</div>';
        $html .= '</div></div>';

        $html .= '<div class="panel panel-default dr-panel">';
        $html .= '<div class="panel-heading"><strong>'
            . $this->e($this->translator->t('videos_title')) . '</strong>'
            . '<span class="text-muted"> · '
            . $this->e($this->translator->t('videos_registered', ['count' => $videoTotal]))
            . '</span></div>';
        $html .= '<div class="table-responsive"><table class="table table-striped table-hover dr-table">';
        $html .= '<thead><tr><th>' . $this->e($this->translator->t('column_video')) . '</th><th>'
            . $this->e($this->translator->t('column_status')) . '</th><th>'
            . $this->e($this->translator->t('column_size')) . '</th><th>'
            . $this->e($this->translator->t('column_usage')) . '</th><th>'
            . $this->e($this->translator->t('column_date')) . '</th><th></th></tr></thead><tbody>';

        if (count($uploads) === 0) {
            $html .= '<tr><td colspan="6" class="text-center text-muted" style="padding:28px">'
                . $this->e($this->translator->t('no_videos')) . '</td></tr>';
        } else {
            foreach ($uploads as $upload) {
                $videoId = (string) ($upload->video_id ?? '');
                $refsCount = $videoId !== '' ? (int) ($refCounts[$videoId] ?? 0) : 0;
                $status = $this->statusLabel((string) $upload->status);
                $html .= '<tr>';
                $html .= '<td><strong>' . $this->e((string) $upload->filename) . '</strong>';
                if ($videoId !== '') {
                    $html .= '<br><small class="text-muted">' . $this->e($videoId) . '</small>';
                }
                $html .= '</td>';
                $html .= '<td>' . $status . '</td>';
                $html .= '<td>' . $this->e($this->formatBytes((int) $upload->accounted_bytes)) . '</td>';
                $html .= '<td>' . ($refsCount > 0
                    ? '<span class="label label-info">'
                        . $this->e($this->translator->t('in_use', ['count' => $refsCount])) . '</span>'
                    : '<span class="label label-default">'
                        . $this->e($this->translator->t('no_references')) . '</span>') . '</td>';
                $html .= '<td>' . date('Y-m-d H:i', (int) $upload->created_at) . '</td>';
                $html .= '<td class="text-right">';
                if ($refsCount === 0 && $videoId !== '') {
                    $html .= '<form method="post" action="' . $this->formAction() . '" style="display:inline">';
                    $html .= $this->customActionFields('DeleteVideo');
                    $html .= '<input type="hidden" name="uploadid" value="' . $this->e((string) $upload->upload_id) . '">';
                    $html .= '<button type="submit" class="btn btn-xs btn-danger" '
                        . 'onclick="return confirm(&quot;'
                        . $this->e($this->translator->t('delete_confirm'))
                        . '&quot;)">' . $this->e($this->translator->t('delete')) . '</button>';
                    $html .= '</form>';
                } else {
                    $html .= '<button class="btn btn-xs btn-default" type="button" disabled>'
                        . $this->e($this->translator->t('protected')) . '</button>';
                }
                $html .= '</td></tr>';
            }
        }

        $html .= '</tbody></table></div>';
        $html .= $this->videoPagination($videoPage, $videoPages);
        $html .= '</div>';
        $html .= '<script>'
            . 'function drToggleToken(){var e=document.getElementById("dr-service-token");'
            . 'if(e){e.type=e.type==="password"?"text":"password";}}'
            . 'function drCopyToken(){var e=document.getElementById("dr-service-token");'
            . 'if(e&&e.value&&navigator.clipboard){navigator.clipboard.writeText(e.value);}}'
            . '</script>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Retrieve the current service token from WHMCS protected properties.
     *
     * @return string
     */
    private function serviceToken(): string
    {
        $token = trim((string) ($this->params['password'] ?? ''));
        if ($this->isServiceToken($token)) {
            return $token;
        }

        if (!isset($this->params['model'])) {
            return '';
        }

        try {
            $token = trim((string) $this->params['model']->serviceProperties->get('Password'));
            return $this->isServiceToken($token) ? $token : '';
        } catch (\Throwable $exception) {
            return '';
        }
    }

    /**
     * Whether one value matches the generated Drive Resource token format.
     *
     * @param string $token Candidate token.
     * @return bool
     */
    private function isServiceToken(string $token): bool
    {
        return (bool) preg_match('/^[a-f0-9]{64}$/', $token);
    }

    /**
     * Standard custom-function form fields.
     *
     * WHMCS validates client ownership when dispatching provisioning custom
     * functions from the product-details route.
     *
     * @param string $action Function name without module prefix.
     * @return string
     */
    private function customActionFields(string $action): string
    {
        $csrf = function_exists('generate_token') ? (string) generate_token('plain') : '';

        return ($csrf !== ''
                ? '<input type="hidden" name="token" value="' . $this->e($csrf) . '">'
                : '')
            . '<input type="hidden" name="id" value="' . (int) ($this->params['serviceid'] ?? 0) . '">'
            . '<input type="hidden" name="modop" value="custom">'
            . '<input type="hidden" name="a" value="' . $this->e($action) . '">';
    }

    /**
     * Render one custom action form.
     *
     * @param string $action Action.
     * @param string $label Button text.
     * @param string $class Button classes.
     * @param string $confirmation Optional confirmation.
     * @return string
     */
    private function actionForm(
        string $action,
        string $label,
        string $class,
        string $confirmation = ''
    ): string {
        $confirm = $confirmation !== ''
            ? ' onclick="return confirm(&quot;' . $this->e($confirmation) . '&quot;)"'
            : '';

        return '<form method="post" action="' . $this->formAction() . '" style="display:inline-block;margin-right:8px">'
            . $this->customActionFields($action)
            . '<button type="submit" class="' . $this->e($class) . '"' . $confirm . '>'
            . $this->e($label) . '</button></form>';
    }

    /**
     * Product details URL.
     *
     * @return string
     */
    private function formAction(): string
    {
        return 'clientarea.php?action=productdetails&id=' . (int) ($this->params['serviceid'] ?? 0);
    }

    /**
     * Summary card.
     *
     * @param string $label Label.
     * @param string $value Value HTML.
     * @param string $meta Supporting text.
     * @return string
     */
    private function card(string $label, string $value, string $meta): string
    {
        return '<div class="dr-card"><div class="dr-card-label">' . $this->e($label) . '</div>'
            . '<div class="dr-card-value">' . $value . '</div>'
            . '<div class="dr-card-meta">' . $meta . '</div></div>';
    }

    /**
     * Render video pagination links within this WHMCS service.
     *
     * @param int $page Current page.
     * @param int $pages Total pages.
     * @return string
     */
    private function videoPagination(int $page, int $pages): string
    {
        if ($pages <= 1) {
            return '';
        }

        $html = '<div style="padding:0 15px 15px"><ul class="pagination pagination-sm" style="margin:0">';
        $start = max(1, $page - 3);
        $end = min($pages, $page + 3);
        for ($current = $start; $current <= $end; $current++) {
            $url = $this->formAction() . '&drpage=' . $current;
            $html .= '<li' . ($current === $page ? ' class="active"' : '') . '>'
                . '<a href="' . $this->e($url) . '">' . $current . '</a></li>';
        }

        return $html . '</ul></div>';
    }

    /**
     * Provider status badge.
     *
     * @param string $status Status.
     * @return string
     */
    private function statusLabel(string $status): string
    {
        $map = [
            'bound' => ['success', 'status_ready'],
            'ready' => ['success', 'status_ready'],
            'processing' => ['info', 'status_processing'],
            'authorized' => ['warning', 'status_uploading'],
            'reserved' => ['warning', 'status_reserved'],
            'failed' => ['danger', 'status_failed'],
            'expired' => ['default', 'status_expired'],
        ];
        [$class, $labelkey] = $map[$status] ?? ['default', ''];
        $label = $labelkey !== '' ? $this->translator->t($labelkey) : ucfirst($status);

        return '<span class="label label-' . $class . '">' . $this->e($label) . '</span>';
    }

    /**
     * Human-readable decimal byte amount.
     *
     * @param int $bytes Bytes.
     * @return string
     */
    private function formatBytes(int $bytes): string
    {
        $bytes = max(0, $bytes);
        if ($bytes >= 1000000000000) {
            return number_format($bytes / 1000000000000, 2) . ' TB';
        }
        if ($bytes >= 1000000000) {
            return number_format($bytes / 1000000000, 2) . ' GB';
        }
        if ($bytes >= 1000000) {
            return number_format($bytes / 1000000, 2) . ' MB';
        }

        return number_format($bytes / 1000, 2) . ' KB';
    }

    /**
     * Scoped UI styles.
     *
     * @return string
     */
    private function styles(): string
    {
        return '<style>'
            . '.dr-portal{margin-top:18px}.dr-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:18px}'
            . '.dr-card{border:1px solid #e5e7eb;border-radius:10px;background:#fff;padding:16px;min-height:112px}'
            . '.dr-card-label{font-size:12px;color:#6b7280;margin-bottom:7px}.dr-card-value{font-size:22px;font-weight:600;line-height:1.25}'
            . '.dr-card-meta{font-size:12px;color:#6b7280;margin-top:7px;line-height:1.45}.dr-panel{border-radius:10px;overflow:hidden}'
            . '.dr-actions{margin-top:12px}.dr-table td{vertical-align:middle!important}'
            . '@media(max-width:991px){.dr-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}'
            . '@media(max-width:575px){.dr-grid{grid-template-columns:1fr}.dr-card-value{font-size:20px}}'
            . '</style>';
    }

    /**
     * HTML escape.
     *
     * @param string $value Value.
     * @return string
     */
    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }


}
