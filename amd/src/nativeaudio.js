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
    var MAX_CONTIGUOUS_MEDIA_DELTA = 5;
    var MAX_WATCHED_RANGES = 512;

    var mergeRanges = function(ranges, duration) {
        var clean = [];
        ranges.slice(0, MAX_WATCHED_RANGES).forEach(function(range) {
            if (!Array.isArray(range) || range.length < 2) {
                return;
            }
            var start = Number(range[0]);
            var end = Number(range[1]);
            if (!Number.isFinite(start) || !Number.isFinite(end)) {
                return;
            }
            start = Math.max(0, start);
            end = Math.max(0, end);
            if (duration > 0) {
                start = Math.min(start, duration);
                end = Math.min(end, duration);
            }
            if (end > start) {
                clean.push([start, end]);
            }
        });

        clean.sort(function(a, b) {
            return a[0] - b[0];
        });

        var merged = [];
        clean.forEach(function(range) {
            var last = merged.length ? merged[merged.length - 1] : null;
            if (last && range[0] <= last[1] + 0.25) {
                last[1] = Math.max(last[1], range[1]);
            } else if (merged.length < MAX_WATCHED_RANGES) {
                merged.push([range[0], range[1]]);
            }
        });

        return merged;
    };

    var parseRanges = function(value, duration) {
        var parsed;
        try {
            parsed = JSON.parse(value || '[]');
        } catch (error) {
            parsed = [];
        }
        return Array.isArray(parsed) ? mergeRanges(parsed, duration) : [];
    };

    var watchedSeconds = function(ranges) {
        return ranges.reduce(function(total, range) {
            return total + Math.max(0, range[1] - range[0]);
        }, 0);
    };

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
        var watchedRanges = parseRanges(root.dataset.watchedRanges || '[]', 0);
        var lastMediaTime = null;

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
            var watched = watchedSeconds(watchedRanges);
            var percentage = Math.max(0, Math.min(100, (watched / audio.duration) * 100));
            var request = {
                methodname: 'mod_videoplayer_save_progress',
                args: {
                    cmid: cmid,
                    progress: watched,
                    completed: percentage >= 99.5,
                    completionpercentage: Math.round(percentage * 100) / 100,
                    lastpage: 0,
                    totalpages: 0,
                    timespent: Math.round(activeSeconds),
                    lastposition: Math.max(0, audio.currentTime),
                    duration: Math.max(0, audio.duration),
                    watchedranges: JSON.stringify(watchedRanges)
                }
            };

            return Ajax.call([request])[0]
                .then(function(response) {
                    if (response) {
                        watchedRanges = parseRanges(response.watchedranges || '[]', audio.duration);
                    }
                    return response;
                })
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
            watchedRanges = mergeRanges(watchedRanges, audio.duration);
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
            lastMediaTime = Number(audio.currentTime) || 0;
        });
        audio.addEventListener('timeupdate', function() {
            var currentTime = Number(audio.currentTime);
            if (!Number.isFinite(currentTime)) {
                return;
            }
            if (lastMediaTime === null || audio.seeking || audio.paused) {
                lastMediaTime = currentTime;
                return;
            }

            var delta = currentTime - lastMediaTime;
            if (delta > 0 && delta <= MAX_CONTIGUOUS_MEDIA_DELTA) {
                watchedRanges = mergeRanges(
                    watchedRanges.concat([[lastMediaTime, currentTime]]),
                    audio.duration
                );
            }
            lastMediaTime = currentTime;
        });
        audio.addEventListener('seeking', function() {
            lastMediaTime = null;
        });
        audio.addEventListener('pause', function() {
            save(true);
        });
        audio.addEventListener('ended', function() {
            save(true);
        });
        audio.addEventListener('seeked', function() {
            lastMediaTime = Number(audio.currentTime) || 0;
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
