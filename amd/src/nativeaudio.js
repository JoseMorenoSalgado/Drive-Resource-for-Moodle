/**
 * Protected native HTML5 audio progress integration.
 *
 * @module     mod_videoplayer/nativeaudio
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax'], function(Ajax) {
    var SAVE_INTERVAL_MS = 15000;
    var RESUME_GUARD_SECONDS = 3;

    var blockEvent = function(event) {
        event.preventDefault();
        event.stopPropagation();
        return false;
    };

    var initPlayer = function(root) {
        if (root.dataset.audioReady === '1') {
            return;
        }
        root.dataset.audioReady = '1';

        var audio = root.querySelector('.js-drive-resource-audio');
        var cmid = parseInt(root.dataset.cmid, 10) || 0;
        var initialPosition = Math.max(0, parseFloat(root.dataset.initialPosition) || 0);
        var activeSeconds = Math.max(0, parseInt(root.dataset.initialTimespent, 10) || 0);
        var disableContextMenu = root.dataset.disableContextMenu === '1';
        var trackingEnabled = root.dataset.trackingEnabled === '1';
        var lastTick = Date.now();
        var pageVisible = !document.hidden;
        var saving = false;
        var saveTimer = null;
        var queued = false;

        if (!audio || !cmid) {
            return;
        }

        audio.setAttribute('controlslist', 'nodownload');

        if (disableContextMenu) {
            [root, audio].forEach(function(node) {
                node.addEventListener('contextmenu', blockEvent, true);
                node.addEventListener('dragstart', blockEvent, true);
            });
        }

        var updateActiveTime = function() {
            var now = Date.now();
            if (!audio.paused && !audio.ended && pageVisible) {
                activeSeconds += Math.max(0, (now - lastTick) / 1000);
            }
            lastTick = now;
        };

        var save = function(force) {
            if (!trackingEnabled || !Number.isFinite(audio.duration) || audio.duration <= 0) {
                return Promise.resolve();
            }
            updateActiveTime();
            if (saving) {
                queued = queued || force;
                return Promise.resolve();
            }

            saving = true;
            var percentage = Math.max(0, Math.min(100, (audio.currentTime / audio.duration) * 100));
            var request = {
                methodname: 'mod_videoplayer_save_progress',
                args: {
                    cmid: cmid,
                    progress: Math.max(0, audio.currentTime),
                    completed: audio.ended || percentage >= 99.5,
                    completionpercentage: Math.round(percentage * 100) / 100,
                    lastpage: 0,
                    totalpages: 0,
                    timespent: Math.round(activeSeconds),
                    lastposition: Math.max(0, audio.currentTime),
                    duration: Math.max(0, audio.duration)
                }
            };

            return Ajax.call([request])[0]
                .catch(function(error) {
                    if (window.console && window.console.warn) {
                        window.console.warn('Drive Resource audio progress save failed.', error);
                    }
                })
                .then(function() {
                    saving = false;
                    if (queued) {
                        queued = false;
                        return save(true);
                    }
                    return null;
                });
        };

        audio.addEventListener('loadedmetadata', function() {
            if (initialPosition > 0 && initialPosition < audio.duration - RESUME_GUARD_SECONDS) {
                try {
                    audio.currentTime = initialPosition;
                } catch (error) {
                    // Seeking may not be immediately available on every browser.
                }
            }
        });
        audio.addEventListener('play', function() {
            lastTick = Date.now();
        });
        audio.addEventListener('pause', function() {
            save(true);
        });
        audio.addEventListener('ended', function() {
            save(true);
        });
        audio.addEventListener('seeked', function() {
            save(true);
        });
        document.addEventListener('visibilitychange', function() {
            updateActiveTime();
            pageVisible = !document.hidden;
            if (!pageVisible) {
                save(true);
            }
        });
        window.addEventListener('pagehide', function() {
            save(true);
            if (saveTimer) {
                window.clearInterval(saveTimer);
                saveTimer = null;
            }
        });

        saveTimer = window.setInterval(function() {
            if (!audio.paused && !audio.ended) {
                save(false);
            }
        }, SAVE_INTERVAL_MS);
    };

    var init = function() {
        Array.prototype.forEach.call(document.querySelectorAll('.js-native-audio'), initPlayer);
    };

    return {init: init};
});
