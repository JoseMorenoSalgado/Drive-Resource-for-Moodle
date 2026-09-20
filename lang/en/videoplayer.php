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
 * English language strings for Drive Resource.
 *
 * @package    mod_videoplayer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['backtoresource'] = 'Back to resource';
$string['completionpercentage'] = 'Required completion percentage';
$string['completionpercentage_help'] = 'Percentage required to consider this resource completed. PDF progress is calculated from pages actually viewed and video progress from unique playback ranges actually watched.';
$string['disablecontextmenu'] = 'Disable right click and basic copy actions';
$string['disabledownload'] = 'Disable download';
$string['disabledownload_help'] = 'Hide download actions and serve the resource inline through the protected proxy. This discourages direct downloading but cannot prevent screen capture or advanced browser extraction.';
$string['displaymode'] = 'PDF display mode';
$string['displaymode_help'] = 'PDF resources use the stable local PDF.js viewer.';
$string['displaymodeebook'] = 'Legacy protected ebook viewer';
$string['displaymodestandard'] = 'Standard PDF.js viewer';
$string['driveurl'] = 'Google Drive URL';
$string['driveurl_help'] = 'Paste a shareable Google Drive or Google Docs URL. Supported resources include videos, PDFs, images, documents, spreadsheets and presentations.';
$string['enablegamification'] = 'Enable reading gamification';
$string['enablegamification_help'] = 'Award personal milestones and points as learners progress through the protected resource. Competitive leaderboards should remain optional for younger learners.';
$string['enablewatermark'] = 'Show dynamic watermark';
$string['eventprogressupdated'] = 'Drive Resource progress updated';
$string['eventresourcecompleted'] = 'Drive Resource completed';
$string['eventrewardawarded'] = 'Drive Resource reward awarded';
$string['fittoscreen'] = 'Fit to screen';
$string['fullscreen'] = 'Fullscreen';
$string['gamification'] = 'Gamification';
$string['invalidcompletionpercentage'] = 'The completion percentage must be a number between 0 and 100.';
$string['invaliddriveurl'] = 'Enter a valid Google Drive or Google Docs shareable URL.';
$string['invalidlocalpdf'] = 'Only PDF files are allowed for this source.';
$string['invalidpointsperpage'] = 'Points per page must be a number between 0 and 100.';
$string['invalidurl'] = 'The provided URL is not valid. Please use a proper Google Drive shareable link.';
$string['lastposition'] = 'Last position';
$string['loadingpdf'] = 'Loading PDF...';
$string['localpdffile'] = 'Local PDF file';
$string['localpdffile_help'] = 'Upload one PDF file. The file is stored in Moodle private file storage and is served only through Drive Resource access checks.';
$string['mod_videoplayer:addinstance'] = 'Add a new Drive Resource';
$string['mod_videoplayer:addinstance_help'] = 'Allows users to add a new Drive Resource activity to a course.';
$string['mod_videoplayer:edit'] = 'Edit Drive Resource';
$string['mod_videoplayer:edit_help'] = 'Allows users to edit the Drive Resource settings.';
$string['mod_videoplayer:editreport'] = 'Edit Drive Resource reports';
$string['mod_videoplayer:editreport_help'] = 'Allows users to edit reports and user progress in Drive Resources.';
$string['mod_videoplayer:manage'] = 'Manage Drive Resource';
$string['mod_videoplayer:manage_help'] = 'Allows users to manage Drive Resource configuration.';
$string['mod_videoplayer:view'] = 'View Drive Resource';
$string['mod_videoplayer:view_help'] = 'Allows enrolled authenticated users to view protected Drive Resource activity content.';
$string['mod_videoplayer:viewreport'] = 'View Drive Resource reports';
$string['mod_videoplayer:viewreport_help'] = 'Allows users to view reports related to Drive Resources.';
$string['modulename'] = 'Drive Resource';
$string['modulename_help'] = 'Use this activity to publish protected resources from Google Drive or Moodle local storage, including videos, PDFs, images, documents, spreadsheets and presentations.';
$string['modulenameplural'] = 'Drive Resources';
$string['nextpage'] = 'Next page';
$string['noprogressrecords'] = 'There are no progress records for this resource yet.';
$string['noresources'] = 'There are no Drive Resources in this course.';
$string['noresourcesavailable'] = 'There are no Drive Resources available to you in this course.';
$string['pageposition'] = 'Page {$a->page} of {$a->total}';
$string['pagesviewed'] = '{$a} pages viewed';
$string['pdfjsrequired'] = 'The local PDF.js viewer could not be loaded. Please contact the site administrator and confirm that thirdpartylibs/pdfjs/pdf.min.mjs and thirdpartylibs/pdfjs/pdf.worker.min.mjs are installed.';
$string['pluginadministration'] = 'Drive Resource administration';
$string['pluginname'] = 'Drive Resource';
$string['plyrmissing'] = 'The local Plyr player could not be loaded. The native HTML5 player will be used.';
$string['points'] = 'Points';
$string['pointsperpage'] = 'Points per first page action';
$string['previouspage'] = 'Previous page';
$string['privacy:metadata:videoplayer_rewards'] = 'Stores personal gamification rewards earned in Drive Resources.';
$string['privacy:metadata:videoplayer_rewards:points'] = 'The points awarded for this reward.';
$string['privacy:metadata:videoplayer_rewards:rewardkey'] = 'The unique reward key.';
$string['privacy:metadata:videoplayer_rewards:rewardtype'] = 'The type of reward earned.';
$string['privacy:metadata:videoplayer_rewards:timecreated'] = 'The time when the reward was earned.';
$string['privacy:metadata:videoplayer_rewards:userid'] = 'The ID of the user who earned the reward.';
$string['privacy:metadata:videoplayer_rewards:videoplayerid'] = 'The Drive Resource activity instance ID.';
$string['privacy:metadata:videoplayer_views'] = 'Stores user progress, reading state and completion data for Drive Resources.';
$string['privacy:metadata:videoplayer_views:completed'] = 'Whether the resource has been marked as completed.';
$string['privacy:metadata:videoplayer_views:completionpercentage'] = 'The saved completion percentage.';
$string['privacy:metadata:videoplayer_views:lastpage'] = 'The last PDF page reached by the user.';
$string['privacy:metadata:videoplayer_views:lastsecond'] = 'The last video playback second saved for resume behavior.';
$string['privacy:metadata:videoplayer_views:points'] = 'The total gamification points stored for the user in this resource.';
$string['privacy:metadata:videoplayer_views:progress'] = 'The last saved progress value.';
$string['privacy:metadata:videoplayer_views:timecreated'] = 'The time when the first progress record was created.';
$string['privacy:metadata:videoplayer_views:timemodified'] = 'The time when the progress record was last updated.';
$string['privacy:metadata:videoplayer_views:timespent'] = 'The active reading time saved for the user.';
$string['privacy:metadata:videoplayer_views:totalpages'] = 'The total number of PDF pages detected by the viewer.';
$string['privacy:metadata:videoplayer_views:totalseconds'] = 'The total video duration saved for progress calculation.';
$string['privacy:metadata:videoplayer_views:userid'] = 'The ID of the user who viewed the resource.';
$string['privacy:metadata:videoplayer_views:videoplayerid'] = 'The Drive Resource activity instance ID.';
$string['privacy:metadata:videoplayer_views:visitedpages'] = 'The set of PDF pages observed by the user for exact completion tracking.';
$string['privacy:metadata:videoplayer_views:watchedranges'] = 'The normalized video playback ranges actually watched by the user.';
$string['progress'] = 'Progress';
$string['progressreport'] = 'Progress report';
$string['protectedmodedisabled'] = 'Protected mode is disabled by the site administrator.';
$string['protectedresource'] = 'Protected resource';
$string['protectedresourceunavailable'] = 'The protected resource is currently unavailable or cannot be streamed.';
$string['requiredlocalpdf'] = 'Upload one local PDF file.';
$string['resourcename'] = 'Resource name';
$string['resourcesource'] = 'Resource source';
$string['resourcetype'] = 'Resource type';
$string['resourcetype_help'] = 'Select the resource type explicitly for standard Google Drive /file/d/ links. Automatic mode can identify Google Docs, Sheets, Slides and URLs with a visible extension; otherwise it falls back to Video for backward compatibility with the original Video Player plugin.';
$string['resumereading'] = 'Continue from page';
$string['rewardcompleted'] = 'Resource completed';
$string['rewardfirstpage'] = 'Reading started';
$string['rewardpercent'] = '{$a}% milestone reached';
$string['searchpdf'] = 'Search';
$string['searchpdfnotfound'] = 'No matching text was found.';
$string['searchpdfplaceholder'] = 'Search text in this document';
$string['searchpdfresultpage'] = 'Result on page {$a}';
$string['secondposition'] = 'Second {$a->second} of {$a->total}';
$string['secondswatched'] = '{$a} seconds watched';
$string['setting_defaultcompletionpercentage'] = 'Default completion percentage';
$string['setting_defaultcompletionpercentage_desc'] = 'Default percentage used when creating new Drive Resource activities.';
$string['setting_defaultrequiredseconds'] = 'Default required time';
$string['setting_defaultrequiredseconds_desc'] = 'Default active time in seconds required to consider a resource sufficiently viewed when presence-based tracking is used.';
$string['setting_enabletracking'] = 'Enable progress tracking';
$string['setting_enabletracking_desc'] = 'When enabled, Drive Resource records exact supported-resource progress and active interaction state.';
$string['setting_pdfcacheenabled'] = 'Enable PDF cache';
$string['setting_pdfcacheenabled_desc'] = 'Stores protected PDF files in Moodle local cache after the first full request so subsequent views load faster. Files are still served only through protected.php after access checks.';
$string['setting_pdfcachettl'] = 'PDF cache lifetime';
$string['setting_pdfcachettl_desc'] = 'Lifetime in seconds for cached PDF files. Default is 2592000 seconds, equivalent to 30 days.';
$string['setting_playercolor'] = 'Custom player color';
$string['setting_playercolor_desc'] = 'HEX color used when the player color mode is custom. Example: #3b82f6.';
$string['setting_playercolormode'] = 'Player color mode';
$string['setting_playercolormode_custom'] = 'Use custom HEX color';
$string['setting_playercolormode_desc'] = 'Use the current Moodle theme primary color when possible, or force a custom HEX color for the Plyr player.';
$string['setting_playercolormode_theme'] = 'Use Moodle theme color';
$string['setting_protectedmode'] = 'Protected mode';
$string['setting_protectedmode_desc'] = 'When enabled, supported resources are delivered only through Moodle-owned protected viewers without Google Drive preview UI.';
$string['setting_showresourcetype'] = 'Show resource type';
$string['setting_showresourcetype_desc'] = 'Show the detected or selected resource type above the embedded resource.';
$string['sourcegoogledrive'] = 'Google Drive';
$string['sourcelocalpdf'] = 'Local protected PDF';
$string['task_cleanup_pdf_cache'] = 'Clean Drive Resource PDF cache';
$string['timespent'] = 'Active time';
$string['typeauto'] = 'Automatic (video fallback)';
$string['typedocument'] = 'Document';
$string['typefile'] = 'File';
$string['typeimage'] = 'Image';
$string['typepdf'] = 'PDF';
$string['typepresentation'] = 'Presentation';
$string['typespreadsheet'] = 'Spreadsheet';
$string['typevideo'] = 'Video';
$string['unsupportedprotectedresource'] = 'This protected resource type is not currently supported.';
$string['videojsmissing'] = 'Local Video.js is not installed. The native HTML5 player will be used.';
$string['videoname'] = 'Resource name';
$string['videonotsupported'] = 'Your browser cannot play this video.';
$string['videoplayer:addinstance'] = 'Add a new Drive Resource';
$string['videourl'] = 'Google Drive URL';
$string['videourl_help'] = 'Paste a shareable Google Drive or Google Docs URL.';
$string['zoomin'] = 'Zoom in';
$string['zoomout'] = 'Zoom out';
