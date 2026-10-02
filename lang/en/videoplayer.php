<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Language strings for Elearning Stream.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
$string['backtoresource'] = 'Back to resource';
$string['bunnyassetuploaded'] = 'Video uploaded successfully';
$string['bunnyplaybackphasepending'] = 'The video is linked to Elearning Stream and ready for managed playback.';
$string['bunnyuploadauthorizing'] = 'Checking service and storage quota…';
$string['bunnyuploadbutton'] = 'Upload video';
$string['bunnyuploadchange'] = 'Change video';
$string['bunnyuploaddrophelp'] = 'or tap here to choose a file';
$string['bunnyuploaddroptitle'] = 'Drop your video here';
$string['bunnyuploadexisting'] = 'An Elearning Stream video is already linked to this activity.';
$string['bunnyuploadfailed'] = 'The video upload could not be completed.';
$string['bunnyuploadformats'] = 'MP4, MOV, WebM, MKV, AVI or MPEG';
$string['bunnyuploading'] = 'Uploading directly to video storage…';
$string['bunnyuploadintro'] = 'The video is uploaded directly from your browser to Elearning Stream. Provider credentials are never stored in Moodle.';
$string['bunnyuploadinvalidsize'] = 'The selected video has an invalid file size.';
$string['bunnyuploadinvalidtype'] = 'The selected file is not recognised as a video.';
$string['bunnyuploadlabel'] = 'Video upload';
$string['bunnyuploadoverage'] = 'Additional storage: {$a}.';
$string['bunnyuploadprocessing'] = 'Upload complete. The video is processing.';
$string['bunnyuploadprogress'] = 'Upload progress';
$string['bunnyuploadquota'] = 'Storage after upload: {$a->projected} / {$a->included}.';
$string['bunnyuploadready'] = 'Ready to upload.';
$string['bunnyuploadreauthorizing'] = 'Refreshing secure upload authorization…';
$string['bunnyuploadrequired'] = 'Upload the video before saving this Elearning Stream resource.';
$string['bunnyuploadretrying'] = 'Connection interrupted. Resuming upload…';
$string['bunnyuploadsecure'] = 'The upload is secure. Keep this page open until it reaches 100%.';
$string['bunnyuploadselected'] = 'Selected video';
$string['completionpercentage'] = 'Required completion percentage';
$string['completionpercentage_help'] = 'Percentage required to consider this resource completed when progress tracking is available. For PDF ebooks, the percentage is calculated from pages read.';
$string['completionprogressdesc'] = 'Reach at least {$a}% progress';
$string['completionprogressenabled'] = 'Require progress percentage';
$string['completionprogressgroup'] = 'Required progress';
$string['completionprogressgroup_help'] = 'When automatic completion is enabled, require the learner to reach this percentage. Managed video uses the union of media ranges actually reproduced; protected PDF uses page progress.';
$string['disablecontextmenu'] = 'Disable right click and basic copy actions';
$string['duration'] = 'Duration';
$string['enablewatermark'] = 'Show dynamic watermark';
$string['eventprogressupdated'] = 'Elearning Stream progress updated';
$string['eventresourcecompleted'] = 'Elearning Stream resource completed';
$string['fitscreen'] = 'Fit to screen';
$string['fullscreen'] = 'Fullscreen';
$string['invalidcompletionpercentage'] = 'The completion percentage must be a number between 0 and 100.';
$string['invalidlocalpdf'] = 'Only PDF files are allowed for this source.';
$string['invalidstreamurl'] = 'Enter a valid Elearning Stream URL.';
$string['lastposition'] = 'Resume position';
$string['legacyremotesourcenotsupported'] = 'This activity uses a retired remote source that is no longer part of Elearning Stream. Upload or link the video through Elearning Stream before saving the activity.';
$string['loadingpdf'] = 'Loading PDF...';
$string['localpdffile'] = 'Local PDF file';
$string['localpdffile_help'] = 'Upload one PDF file. The file is stored in Moodle private file storage and is served only through Elearning Stream access checks.';
$string['modulename'] = 'Elearning Stream';
$string['modulename_help'] = 'Use this activity to publish protected videos through Elearning Stream.';
$string['modulenameplural'] = 'Elearning Stream';
$string['nextmatch'] = 'Next match';
$string['nextpage'] = 'Next page';
$string['nomatches'] = 'No matches';
$string['noprogressrecords'] = 'There are no progress records for this resource yet.';
$string['noresources'] = 'There are no Elearning Stream resources in this course.';
$string['noresourcesavailable'] = 'There are no Elearning Stream resources available to you in this course.';
$string['openprotectedresource'] = 'Open protected resource';
$string['pdfjsrequired'] = 'The local PDF.js viewer could not be loaded. Please contact the site administrator and confirm that thirdpartylibs/pdfjs/pdf.min.mjs and thirdpartylibs/pdfjs/pdf.worker.min.mjs are installed.';
$string['pluginadministration'] = 'Elearning Stream administration';
$string['pluginname'] = 'Elearning Stream';
$string['previouspage'] = 'Previous page';
$string['privacy:metadata:videoplayer_views'] = 'Stores user progress, reading state and completion data for Elearning Streams.';
$string['privacy:metadata:videoplayer_views:completed'] = 'Whether the resource has been marked as completed.';
$string['privacy:metadata:videoplayer_views:completionpercentage'] = 'The saved completion percentage.';
$string['privacy:metadata:videoplayer_views:duration'] = 'The last detected media duration in seconds.';
$string['privacy:metadata:videoplayer_views:lastpage'] = 'The last PDF page reached by the user.';
$string['privacy:metadata:videoplayer_views:lastposition'] = 'The exact saved media resume position in seconds.';
$string['privacy:metadata:videoplayer_views:progress'] = 'The last saved progress value.';
$string['privacy:metadata:videoplayer_views:timecreated'] = 'The time when the first progress record was created.';
$string['privacy:metadata:videoplayer_views:timemodified'] = 'The time when the progress record was last updated.';
$string['privacy:metadata:videoplayer_views:timespent'] = 'The active reading time saved for the user.';
$string['privacy:metadata:videoplayer_views:totalpages'] = 'The total number of PDF pages detected by the viewer.';
$string['privacy:metadata:videoplayer_views:userid'] = 'The ID of the user who viewed the resource.';
$string['privacy:metadata:videoplayer_views:videoplayerid'] = 'The Elearning Stream activity instance ID.';
$string['privacy:metadata:videoplayer_views:watchedranges'] = 'The media time ranges actually reproduced by the learner.';
$string['progress'] = 'Progress';
$string['progresslocktimeout'] = 'Your progress could not be saved because another update is still being processed. Please try again.';
$string['progressreport'] = 'Progress report';
$string['protectedresource'] = 'Protected resource';
$string['protectedresourceunavailable'] = 'The protected resource is currently unavailable or cannot be streamed.';
$string['requiredlocalpdf'] = 'Upload one local PDF file.';
$string['resourcename'] = 'Resource name';
$string['resourcesource'] = 'Resource source';
$string['resourcetype'] = 'Resource type';
$string['resumereading'] = 'Continue from page';
$string['retry'] = 'Retry';
$string['searching'] = 'Searching…';
$string['searchpdf'] = 'Search in document';
$string['setting_defaultcompletionpercentage'] = 'Default completion percentage';
$string['setting_defaultcompletionpercentage_desc'] = 'Default percentage used when creating new Elearning Stream activities.';
$string['setting_enabletracking'] = 'Enable progress tracking';
$string['setting_enabletracking_desc'] = 'When enabled, Elearning Stream records protected video watch progress and protected PDF page progress in Moodle.';
$string['setting_playercolor'] = 'Custom player color';
$string['setting_playercolor_desc'] = 'HEX color used when the player color mode is custom. Example: #3b82f6.';
$string['setting_playercolormode'] = 'Player color mode';
$string['setting_playercolormode_custom'] = 'Use custom HEX color';
$string['setting_playercolormode_desc'] = 'Use the current Moodle theme primary color when possible, or force a custom HEX color for the integrated HTML5 player.';
$string['setting_playercolormode_theme'] = 'Use Moodle theme color';
$string['setting_whmcsgatewayheading'] = 'Elearning Stream connection';
$string['setting_whmcsgatewayheading_desc'] = 'Provider credentials remain protected in the gateway. Moodle stores only the connection URL, service ID and service-scoped token.';
$string['setting_whmcsgatewayurl'] = 'Elearning Stream connection URL';
$string['setting_whmcsgatewayurl_desc'] = 'HTTPS connection URL supplied by Elearning Cloud, for example https://stream.elearningcloud.io.';
$string['setting_whmcsserviceid'] = 'Service ID';
$string['setting_whmcsserviceid_desc'] = 'The active service associated with this Moodle installation and its storage quota.';
$string['setting_whmcsservicetoken'] = 'Service token';
$string['setting_whmcsservicetoken_desc'] = 'Private token generated for this service. This is not a video-provider credential.';
$string['setting_whmcstimeout'] = 'Gateway timeout';
$string['setting_whmcstimeout_desc'] = 'Server-to-server request timeout in seconds (5–60).';
$string['sourcebunnystream'] = 'Elearning Stream';
$string['sourcelocalpdf'] = 'Local protected PDF';
$string['streamgatewayconfigurationrequired'] = 'Elearning Stream cannot be used until the site administrator configures: {$a}.';
$string['streaminputmode'] = 'Add video';
$string['streammodeupload'] = 'Upload a new video';
$string['streammodeurl'] = 'Use an existing video';
$string['streamurl'] = 'Elearning Stream URL';
$string['streamurl_help'] = 'Paste the playback, HLS or embed URL of an existing Elearning Stream video. The full URL is not stored; only the video identifier validated by the gateway is retained.';
$string['task_cleanup_pdf_cache'] = 'Clean Elearning Stream PDF cache';
$string['task_synctransferusage'] = 'Sync Elearning Stream transfer usage';
$string['timespent'] = 'Active time';
$string['trackingdisabled'] = 'Progress tracking is disabled for this site.';
$string['unsupportedprotectedresource'] = 'This protected resource type is not currently supported.';
$string['videoerror'] = 'Video could not be loaded';
$string['videoerrorhelp'] = 'The protected stream is temporarily unavailable. Try again in a moment.';
$string['videoloading'] = 'Loading video…';
$string['videomute'] = 'Mute';
$string['videoname'] = 'Resource name';
$string['videonotsupported'] = 'Your browser cannot play this video.';
$string['videopause'] = 'Pause';
$string['videoplay'] = 'Play';
$string['videoplayer:addinstance'] = 'Add a new Elearning Stream activity';
$string['videoplayer:addinstance_help'] = 'Allows users to add a new Elearning Stream activity to a course.';
$string['videoplayer:edit'] = 'Edit Elearning Stream';
$string['videoplayer:edit_help'] = 'Allows users to edit Elearning Stream settings.';
$string['videoplayer:editreport'] = 'Edit Elearning Stream reports';
$string['videoplayer:editreport_help'] = 'Allows users to edit reports and user progress in Elearning Stream.';
$string['videoplayer:manage'] = 'Manage Elearning Stream';
$string['videoplayer:manage_help'] = 'Allows users to manage Elearning Stream configuration.';
$string['videoplayer:uploadvideo'] = 'Manage videos through Elearning Stream';
$string['videoplayer:view'] = 'View Elearning Stream';
$string['videoplayer:view_help'] = 'Allows enrolled authenticated users to view protected Elearning Stream content.';
$string['videoplayer:viewreport'] = 'View Elearning Stream reports';
$string['videoplayer:viewreport_help'] = 'Allows users to view reports related to Elearning Stream.';
$string['videoseek'] = 'Seek video';
$string['videospeed'] = 'Playback speed';
$string['videounmute'] = 'Unmute';
$string['videovolume'] = 'Volume';
$string['whmcsgatewayhttpsrequired'] = 'The media gateway must use a secure HTTPS URL.';
$string['whmcsgatewayinvalidresponse'] = 'The gateway returned an invalid upload authorisation.';
$string['whmcsgatewayinvaliduploadendpoint'] = 'The gateway returned an untrusted video upload endpoint.';
$string['whmcsgatewaynotconfigured'] = 'Elearning Stream is not configured in Moodle. Missing settings: {$a}. Configure them under Site administration > Plugins > Activity modules > Elearning Stream.';
$string['whmcsgatewayremoteerror'] = 'The Elearning Stream gateway rejected the request: {$a}';
$string['whmcsgatewayrequestfailed'] = 'The Elearning Stream gateway could not process the request.';
$string['zoomin'] = 'Zoom in';
$string['zoomout'] = 'Zoom out';
