/**
 * Generic active-view progress tracker for protected image/file resources.
 *
 * Video, audio and PDF have dedicated trackers and must not initialise this
 * module, preventing duplicate writes and double-counted time.
 *
 * @module     mod_videoplayer/progress
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax'], function(Ajax) {
    var DEFAULT_INTERVAL = 30000;
    var DEFAULT_REQUIRED_SECONDS = 300;

    var init = function(options) {
        options = options || {};
        var cmid = parseInt(options.cmid, 10) || 0;
        var requiredSeconds = Math.max(1, parseInt(options.requiredSeconds, 10) || DEFAULT_REQUIRED_SECONDS);
        var interval = Math.max(10000, parseInt(options.interval, 10) || DEFAULT_INTERVAL);
        var activeSeconds = Math.max(0, parseFloat(options.initialTimeSpent || options.initialProgress) || 0);
        var completed = Boolean(options.completed);
        var lastTick = Date.now();
        var pageVisible = !document.hidden;
        var saving = false;
        var saveQueued = false;
        var saveTimer = null;

        if (!cmid) {
            return;
        }

        var updateTime = function() {
            var now = Date.now();
            if (pageVisible) {
                activeSeconds += Math.max(0, (now - lastTick) / 1000);
            }
            lastTick = now;
        };

        var save = function() {
            updateTime();
            if (saving) {
                saveQueued = true;
                return Promise.resolve();
            }
            saving = true;
            var percentage = Math.max(0, Math.min(100, (activeSeconds / requiredSeconds) * 100));
            var request = {
                methodname: 'mod_videoplayer_save_progress',
                args: {
                    cmid: cmid,
                    progress: activeSeconds,
                    completed: completed || percentage >= 100,
                    completionpercentage: Math.round(percentage * 100) / 100,
                    lastpage: 0,
                    totalpages: 0,
                    timespent: Math.round(activeSeconds),
                    lastposition: 0,
                    duration: 0
                }
            };

            return Ajax.call([request])[0]
                .then(function(response) {
                    completed = Boolean(response && response.completed);
                    return response;
                })
                .catch(function(error) {
                    if (window.console && window.console.warn) {
                        window.console.warn('Drive Resource progress save failed.', error);
                    }
                })
                .then(function() {
                    saving = false;
                    if (saveQueued) {
                        saveQueued = false;
                        return save();
                    }
                    return null;
                });
        };

        document.addEventListener('visibilitychange', function() {
            updateTime();
            pageVisible = !document.hidden;
            if (!pageVisible) {
                save();
            }
        });
        window.addEventListener('pagehide', function() {
            save();
            if (saveTimer) {
                window.clearInterval(saveTimer);
                saveTimer = null;
            }
        });
        saveTimer = window.setInterval(save, interval);
    };

    return {init: init};
});
