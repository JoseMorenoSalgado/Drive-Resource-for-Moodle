<?php

namespace WHMCS\Module\Server\Driveresource;

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Setting;

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
        $retentionDays = max(0, (int) ($service->retention_days ?? 0));

        $account = Capsule::schema()->hasTable('mod_driveresource_accounts')
            ? Capsule::table('mod_driveresource_accounts')->where('service_id', $serviceId)->first()
            : null;
        $billingMode = $account ? strtolower((string) $account->billing_mode) : 'legacy';
        $quotaBytes = max(1, $account && $billingMode !== 'legacy'
            ? (int) $account->free_storage_bytes
            : (int) $service->quota_bytes);
        $freeTransferBytes = max(0, $account ? (int) $account->free_transfer_bytes : 0);
        $walletBalance = $account ? max(0, (int) $account->balance_microusd) : 0;
        $storagePercent = min(999, (int) round(($usedBytes / $quotaBytes) * 100));

        $installations = Capsule::schema()->hasTable('mod_driveresource_installations')
            ? Capsule::table('mod_driveresource_installations')
                ->where('service_id', $serviceId)
                ->where('status', '<>', 'revoked')
                ->orderBy('is_primary', 'desc')
                ->orderBy('created_at', 'asc')
                ->get()
            : [];
        $installationCount = count($installations);
        $installationLimit = 1;
        if ($account) {
            $installationLimit = in_array($billingMode, ['payg', 'legacy'], true)
                ? max(0, (int) $account->paid_installation_limit)
                : max(1, (int) $account->free_installation_limit);
        }
        $canAddInstallation = $account
            && (bool) $account->activation_verified
            && ($installationLimit === 0 || $installationCount < $installationLimit);

        $planKey = $billingMode === 'payg'
            ? 'plan_payg'
            : ($billingMode === 'free' ? 'plan_free' : 'plan_legacy');

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
            $this->translator->t('card_plan'),
            $this->e($this->translator->t($planKey)),
            $account && $billingMode !== 'legacy'
                ? $this->translator->t('free_included', [
                    'storage' => $this->formatBytes($quotaBytes),
                    'transfer' => $this->formatBytes($freeTransferBytes),
                ])
                : $this->translator->t('videos_meta')
        );
        $html .= $this->card(
            $this->translator->t('card_balance'),
            $this->e($this->formatMoney($walletBalance)),
            $this->translator->t('balance_meta')
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
            $account && $billingMode !== 'legacy'
                ? $this->formatBytes($transferBytes) . ' / ' . $this->formatBytes($freeTransferBytes)
                : $this->formatBytes($transferBytes),
            $account && $billingMode !== 'legacy'
                ? $this->translator->t('transfer_included_meta', [
                    'used' => $this->formatBytes($transferBytes),
                    'included' => $this->formatBytes($freeTransferBytes),
                ])
                : $this->translator->t('transfer_meta')
        );
        $html .= $this->card(
            $this->translator->t('card_videos'),
            (string) $videoTotal,
            $this->translator->t('videos_meta')
        );
        $html .= $this->card(
            $this->translator->t('card_retention'),
            $retentionDays === 0
                ? $this->translator->t('retention_immediate')
                : $this->translator->t('retention_days', ['days' => $retentionDays]),
            $this->translator->t('retention_meta')
        );
        $html .= '</div>';

        $gatewayUrl = $this->publicGatewayUrl();
        $html .= '<div class="panel panel-default dr-panel">';
        $html .= '<div class="panel-heading"><strong>'
            . $this->e($this->translator->t('connect_moodle')) . '</strong></div>';
        $html .= '<div class="panel-body">';

        $html .= '<div class="form-group">';
        $html .= '<label for="dr-gateway-url">'
            . $this->e($this->translator->t('moodle_url')) . '</label>';
        $html .= '<div class="input-group">';
        $html .= '<input id="dr-gateway-url" type="text" class="form-control" readonly value="'
            . $this->e($gatewayUrl) . '">';
        $html .= '<span class="input-group-btn"><button class="btn btn-default" type="button" '
            . 'onclick="drCopyField(&quot;dr-gateway-url&quot;)">'
            . $this->e($this->translator->t('save_url')) . '</button></span>';
        $html .= '</div>';
        $html .= '<p class="help-block">' . $this->e($this->translator->t('moodle_url_help')) . '</p>';
        $html .= '</div>';

        $html .= '<div class="form-group">';
        $html .= '<label>' . $this->e($this->translator->t('service_id')) . '</label>';
        $html .= '<input class="form-control" type="text" readonly value="' . $serviceId . '">';
        $html .= '</div>';

        $collectionName = trim((string) ($service->video_collection_name ?? ''));
        $html .= '<div class="form-group">';
        $html .= '<label>' . $this->e($this->translator->t('virtual_classroom')) . '</label>';
        if ($collectionName !== '') {
            $html .= '<input class="form-control" type="text" readonly value="'
                . $this->e($collectionName) . '">';
        } else {
            $html .= '<p class="form-control-static text-muted">'
                . $this->e($this->translator->t('virtual_classroom_pending'))
                . '</p>';
        }
        $html .= '</div>';

        $html .= '<div class="form-group">';
        $html .= '<label>' . $this->e($this->translator->t('service_token')) . '</label>';
        $html .= '<div class="input-group">';
        $html .= '<input id="dr-service-token" class="form-control" type="password" readonly value="'
            . $this->e($token) . '" placeholder="' . $this->e($this->translator->t('token_missing')) . '">';
        $html .= '<span class="input-group-btn">';
        $html .= '<button type="button" class="btn btn-default" onclick="drToggleToken()">'
            . $this->e($this->translator->t('show')) . '</button>';
        $html .= '<button type="button" class="btn btn-default" '
            . 'onclick="drCopyField(&quot;dr-service-token&quot;)">'
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

        if ($account && $billingMode !== 'legacy') {
            $rechargeSession = $_SESSION['elearning_stream_recharge_invoice'] ?? null;
            if (
                is_array($rechargeSession)
                && (int) ($rechargeSession['service_id'] ?? 0) === $serviceId
                && time() - (int) ($rechargeSession['created_at'] ?? 0) <= 900
            ) {
                $invoiceId = (int) ($rechargeSession['invoice_id'] ?? 0);
                if ($invoiceId > 0) {
                    $html .= '<div class="alert alert-success">'
                        . $this->e($this->translator->t('recharge_invoice_ready', ['invoice' => $invoiceId]))
                        . ' <a class="alert-link" href="viewinvoice.php?id=' . $invoiceId . '">'
                        . $this->e($this->translator->t('open_invoice')) . '</a></div>';
                }
            }

            $html .= '<div class="panel panel-default dr-panel">';
            $html .= '<div class="panel-heading"><strong>'
                . $this->e($this->translator->t('wallet_title')) . '</strong></div>';
            $html .= '<div class="panel-body">';
            $html .= '<p>' . $this->e($this->translator->t('wallet_help')) . '</p>';
            $html .= '<div class="row"><div class="col-sm-4"><div class="dr-wallet-balance">'
                . $this->e($this->formatMoney($walletBalance)) . '</div></div>';
            $html .= '<div class="col-sm-8"><form method="post" action="' . $this->formAction() . '">';
            $html .= $this->customActionFields('CreateWalletRecharge');
            $html .= '<div class="input-group"><span class="input-group-addon">USD</span>';
            $html .= '<select class="form-control" name="amount">';
            $minimumMicrousd = max(1, (int) $account->minimum_recharge_microusd);
            $minimumUsd = $minimumMicrousd / 1000000;
            $amounts = [$minimumUsd, 10, 25, 50, 100, 250];
            $amounts = array_values(array_unique(array_filter(
                $amounts,
                static fn($amount): bool => (float) $amount >= $minimumUsd
            )));
            sort($amounts, SORT_NUMERIC);
            foreach ($amounts as $amount) {
                $formatted = number_format((float) $amount, 2, '.', '');
                $displayAmount = $this->formatMoney((int) round(((float) $amount) * 1000000));
                $html .= '<option value="' . $this->e($formatted) . '">'
                    . $this->e($displayAmount) . '</option>';
            }
            $html .= '</select><span class="input-group-btn"><button type="submit" class="btn btn-primary">'
                . $this->e($this->translator->t('recharge')) . '</button></span></div>';
            $html .= '</form></div></div>';
            $html .= '</div></div>';
        }

        $secret = $_SESSION['elearning_stream_installation_secret'] ?? null;
        if (
            is_array($secret)
            && (int) ($secret['service_id'] ?? 0) === $serviceId
            && time() - (int) ($secret['created_at'] ?? 0) <= 600
        ) {
            $secretId = 'dr-installation-token-' . (int) ($secret['installation_id'] ?? 0);
            $html .= '<div class="alert alert-warning dr-secret">';
            $html .= '<strong>' . $this->e($this->translator->t('installation_secret_title')) . '</strong>';
            $html .= '<p>' . $this->e($this->translator->t('installation_secret_help')) . '</p>';
            $html .= '<div class="form-group"><label>'
                . $this->e($this->translator->t('installation_url'))
                . '</label><input class="form-control" readonly value="'
                . $this->e((string) ($secret['site_url'] ?? '')) . '"></div>';
            $html .= '<div class="form-group"><label>'
                . $this->e($this->translator->t('service_token'))
                . '</label><div class="input-group"><input id="' . $this->e($secretId)
                . '" class="form-control" type="text" readonly value="'
                . $this->e((string) ($secret['token'] ?? '')) . '">';
            $html .= '<span class="input-group-btn"><button type="button" class="btn btn-default" '
                . 'onclick="drCopyField(&quot;' . $this->e($secretId) . '&quot;)">'
                . $this->e($this->translator->t('copy')) . '</button></span></div></div></div>';
        }

        if ($account) {
            $html .= '<div class="panel panel-default dr-panel">';
            $html .= '<div class="panel-heading"><strong>'
                . $this->e($this->translator->t('installations_title')) . '</strong>'
                . '<span class="text-muted"> · '
                . $this->e($this->translator->t('installations_count', ['count' => $installationCount]))
                . '</span></div>';
            $html .= '<div class="table-responsive"><table class="table table-striped dr-table">';
            $html .= '<thead><tr><th>' . $this->e($this->translator->t('installation_name'))
                . '</th><th>' . $this->e($this->translator->t('installation_url'))
                . '</th><th>' . $this->e($this->translator->t('installation_status'))
                . '</th><th>' . $this->e($this->translator->t('installation_last_seen'))
                . '</th><th></th></tr></thead><tbody>';

            foreach ($installations as $installation) {
                $html .= '<tr><td><strong>' . $this->e((string) ($installation->label ?: 'Moodle'))
                    . '</strong>';
                if ((bool) $installation->is_primary) {
                    $html .= ' <span class="label label-default">'
                        . $this->e($this->translator->t('installation_primary')) . '</span>';
                }
                $html .= '</td><td>' . $this->e((string) $installation->site_url) . '</td>';

                $connectionState = (string) ($installation->connection_status ?? 'pending');
                $stateClass = $connectionState === 'connected'
                    ? 'success'
                    : ($connectionState === 'failed' ? 'danger' : 'warning');
                $stateLabel = $connectionState === 'connected'
                    ? $this->translator->t('connection_connected')
                    : ($connectionState === 'failed'
                        ? $this->translator->t('connection_failed')
                        : $this->translator->t('connection_pending'));

                $html .= '<td><span class="label label-' . $stateClass . '">'
                    . $this->e($stateLabel) . '</span></td>';
                $html .= '<td>' . (!empty($installation->last_seen_at)
                    ? date('Y-m-d H:i', (int) $installation->last_seen_at)
                    : $this->e($this->translator->t('never_seen'))) . '</td>';
                $html .= '<td class="text-right">';

                if (!(bool) $installation->is_primary) {
                    $html .= '<form method="post" action="' . $this->formAction()
                        . '" style="display:inline-block;margin-right:6px">'
                        . $this->customActionFields('RotateMoodleInstallationToken')
                        . '<input type="hidden" name="installationid" value="' . (int) $installation->id . '">'
                        . '<button type="submit" class="btn btn-xs btn-warning">'
                        . $this->e($this->translator->t('rotate_installation')) . '</button></form>';
                    $html .= '<form method="post" action="' . $this->formAction()
                        . '" style="display:inline-block">'
                        . $this->customActionFields('RemoveMoodleInstallation')
                        . '<input type="hidden" name="installationid" value="' . (int) $installation->id . '">'
                        . '<button type="submit" class="btn btn-xs btn-danger" onclick="return confirm(&quot;'
                        . $this->e($this->translator->t('remove_installation_confirm')) . '&quot;)">'
                        . $this->e($this->translator->t('remove_installation')) . '</button></form>';
                }

                $html .= '</td></tr>';
            }

            $html .= '</tbody></table></div>';
            $html .= '<div class="panel-body dr-installation-add">';

            if ($canAddInstallation) {
                $html .= '<p class="text-muted">'
                    . $this->e($this->translator->t('installation_add_help')) . '</p>';
                $html .= '<form method="post" action="' . $this->formAction() . '">'
                    . $this->customActionFields('AddMoodleInstallation')
                    . '<div class="row"><div class="col-sm-4"><div class="form-group"><label>'
                    . $this->e($this->translator->t('installation_label')) . '</label>'
                    . '<input class="form-control" name="label" maxlength="191"></div></div>'
                    . '<div class="col-sm-6"><div class="form-group"><label>'
                    . $this->e($this->translator->t('installation_url')) . '</label>'
                    . '<input class="form-control" type="url" name="moodleurl" required placeholder="'
                    . $this->e($this->translator->t('installation_url_input')) . '"></div></div>'
                    . '<div class="col-sm-2"><div class="form-group"><label>&nbsp;</label>'
                    . '<button type="submit" class="btn btn-primary btn-block">'
                    . $this->e($this->translator->t('installation_add')) . '</button></div></div></div></form>';
            } else if ($billingMode === 'free') {
                $html .= '<p class="text-muted" style="margin:0">'
                    . $this->e($this->translator->t('installation_limit_free')) . '</p>';
            }

            $html .= '</div></div>';
        }

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
                $displayName = trim((string) ($upload->display_name ?? ''));
                $displayName = $displayName !== '' ? $displayName : (string) $upload->filename;
                $html .= '<td><strong>' . $this->e($displayName) . '</strong>';
                if ($videoId !== '') {
                    $html .= '<br><small class="text-muted">' . $this->e($videoId) . '</small>';
                }
                $html .= '</td>';
                $html .= '<td>' . $status . '</td>';
                $html .= '<td>' . $this->e($this->formatBytes((int) $upload->accounted_bytes)) . '</td>';
                $deleteAfter = max(0, (int) ($upload->delete_after ?? 0));
                if ($refsCount > 0) {
                    $usage = '<span class="label label-info">'
                        . $this->e($this->translator->t('in_use', ['count' => $refsCount])) . '</span>';
                } else if ($deleteAfter > time()) {
                    $usage = '<span class="label label-warning">'
                        . $this->e($this->translator->t('deletion_scheduled', [
                            'date' => date('Y-m-d H:i', $deleteAfter),
                        ])) . '</span>';
                } else {
                    $usage = '<span class="label label-default">'
                        . $this->e($this->translator->t('no_references')) . '</span>';
                }
                $html .= '<td>' . $usage . '</td>';
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
            . 'function drCopyField(id){var e=document.getElementById(id);'
            . 'if(e&&e.value&&navigator.clipboard){navigator.clipboard.writeText(e.value);}}'
            . '</script>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Customer-facing gateway URL to paste into Moodle.
     *
     * The branded URL is configured in the addon. If it is not set, retain a
     * safe compatibility fallback to the physical addon path.
     *
     * @return string
     */
    private function publicGatewayUrl(): string
    {
        $configured = trim((string) Setting::getSettingValueForModule(
            'driveresource_gateway',
            'public_gateway_url'
        ));

        if ($configured !== '') {
            $parts = parse_url($configured);
            if (
                is_array($parts)
                && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
                && !empty($parts['host'])
                && empty($parts['user'])
                && empty($parts['pass'])
                && empty($parts['query'])
                && empty($parts['fragment'])
            ) {
                return rtrim($configured, '/');
            }
        }

        $systemUrl = rtrim((string) ($this->params['systemurl'] ?? ''), '/');
        return $systemUrl !== ''
            ? $systemUrl . '/modules/addons/driveresource_gateway'
            : '/modules/addons/driveresource_gateway';
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
     * Format integer micro-USD for customer display.
     *
     * @param int $microusd Amount in micro-USD.
     * @return string
     */
    private function formatMoney(int $microusd): string
    {
        return 'US$' . number_format(max(0, $microusd) / 1000000, 2, '.', ',');
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
            . '.dr-portal{margin-top:18px}.dr-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:18px}'
            . '.dr-card{border:1px solid #e5e7eb;border-radius:10px;background:#fff;padding:16px;min-height:112px}'
            . '.dr-card-label{font-size:12px;color:#6b7280;margin-bottom:7px}.dr-card-value{font-size:22px;font-weight:600;line-height:1.25}'
            . '.dr-card-meta{font-size:12px;color:#6b7280;margin-top:7px;line-height:1.45}.dr-panel{border-radius:10px;overflow:hidden}'
            . '.dr-actions{margin-top:12px}.dr-table td{vertical-align:middle!important}'
            . '.dr-wallet-balance{font-size:30px;font-weight:700;line-height:38px}.dr-secret p{margin:8px 0 14px}'
            . '.dr-installation-add{border-top:1px solid #eee}'
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
