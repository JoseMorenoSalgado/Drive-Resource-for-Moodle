<?php
// This file is part of Moodle - http://moodle.org/
//
// Site-administrator view of mandatory Elearning Stream deletions.

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use mod_videoplayer\local\whmcs_gateway_client;

require_admin();

$url = new moodle_url('/mod/videoplayer/deletions.php');
$PAGE->set_url($url);
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('deletionmanagement', 'mod_videoplayer'));
$PAGE->set_heading(get_string('deletionmanagement', 'mod_videoplayer'));

$action = optional_param('action', '', PARAM_ALPHA);
$videoid = optional_param('videoid', '', PARAM_ALPHANUMEXT);

if ($action === 'delete') {
    require_sesskey();
    try {
        $result = (new whmcs_gateway_client())->force_delete_asset($videoid);
        if (($result['status'] ?? '') === 'deleted') {
            redirect($url, get_string('deletiondeletednow', 'mod_videoplayer'), null, \core\output\notification::NOTIFY_SUCCESS);
        }
        redirect($url, get_string('deletionnotdeleted', 'mod_videoplayer'), null, \core\output\notification::NOTIFY_WARNING);
    } catch (Throwable $exception) {
        redirect(
            $url,
            get_string('deletionactionfailed', 'mod_videoplayer', $exception->getMessage()),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('deletionmanagement', 'mod_videoplayer'));

echo html_writer::div(
    get_string('deletionmanagementintro', 'mod_videoplayer'),
    'alert alert-info'
);

try {
    $queue = (new whmcs_gateway_client())->pending_deletions();
} catch (Throwable $exception) {
    echo $OUTPUT->notification(
        get_string('deletionqueuefailed', 'mod_videoplayer', $exception->getMessage()),
        \core\output\notification::NOTIFY_ERROR
    );
    echo $OUTPUT->footer();
    exit;
}

$retentiondays = max(0, (int)($queue['retentiondays'] ?? 0));
echo html_writer::tag(
    'p',
    get_string('deletionpolicyactive', 'mod_videoplayer', $retentiondays),
    ['class' => 'mb-4']
);

$items = (array)($queue['deletions'] ?? []);
if ($items === []) {
    echo $OUTPUT->notification(
        get_string('deletionqueuenone', 'mod_videoplayer'),
        \core\output\notification::NOTIFY_SUCCESS
    );
    echo $OUTPUT->footer();
    exit;
}

$table = new html_table();
$table->head = [
    get_string('deletiontitle', 'mod_videoplayer'),
    get_string('deletionstatus', 'mod_videoplayer'),
    get_string('deletionscheduledfor', 'mod_videoplayer'),
    get_string('deletionsize', 'mod_videoplayer'),
    get_string('deletionactions', 'mod_videoplayer'),
];
$table->data = [];

foreach ($items as $item) {
    $deleteafter = max(0, (int)($item['deleteafter'] ?? 0));
    $bytes = max(0, (int)($item['bytes'] ?? 0));
    $form = html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false)]);
    $form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    $form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'delete']);
    $form .= html_writer::empty_tag('input', [
        'type' => 'hidden',
        'name' => 'videoid',
        'value' => s((string)($item['videoid'] ?? '')),
    ]);
    $form .= html_writer::tag(
        'button',
        get_string('deletiondeletenow', 'mod_videoplayer'),
        ['type' => 'submit', 'class' => 'btn btn-danger btn-sm']
    );
    $form .= html_writer::end_tag('form');

    $table->data[] = [
        s((string)($item['title'] ?? '')),
        s((string)($item['status'] ?? '')),
        $deleteafter > 0 ? userdate($deleteafter) : get_string('deletionautomaticsoon', 'mod_videoplayer'),
        display_size($bytes),
        $form,
    ];
}

echo html_writer::table($table);
echo $OUTPUT->footer();
