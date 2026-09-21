// This file is part of Moodle - http://moodle.org/

/**
 * Protected native HTML5 video integration with precise playback tracking.
 *
 * @module     mod_videoplayer/html5video
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax', 'core/notification'], function(Ajax, Notification) {
    var SAVE_INTERVAL = 10000;
    var HEARTBEAT_INTERVAL = 30000;
    var MAX_CONTIGUOUS_MEDIA_DELTA = 5;
    var MAX_RANGES = 5000;
    var SEEK_RECOVERY_DELAY = 180;
    var SEEK_TOLERANCE = 1.25;
    var MAX_SEEK_RECOVERY_ATTEMPTS = 2;

    var blockBrowserMediaActions = function(event) {
        event.preventDefault();
        event.stopPropagation();
        return false;
    };

    var isAppleMobile = function() {
        var platform = navigator.platform || '';
        var userAgent = navigator.userAgent || '';
        var touchMac = platform === 'MacIntel' && navigator.maxTouchPoints > 1;
        return /iPad|iPhone|iPod/.test(userAgent) || touchMac;
    };

    var shouldPreloadVideo = function() {
        var connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
        if (!connection) {
            return true;
        }
        if (connection.saveData) {
            return false;
        }

        return ['slow-2g', '2g'].indexOf(connection.effectiveType || '') === -1;
    };

    var markOrientation = function(node) {
        var wrapper = node.closest('.mod-videoplayer-native-frame');
        if (!wrapper || !node.videoWidth || !node.videoHeight) {
            return;
        }

        wrapper.classList.remove('is-portrait-video', 'is-landscape-video', 'is-square-video');
        if (node.videoHeight > node.videoWidth) {
            wrapper.classList.add('is-portrait-video');
        } else if (node.videoWidth > node.videoHeight) {
            wrapper.classList.add('is-landscape-video');
        } else {
            wrapper.classList.add('is-square-video');
        }
    };

    var parseRanges = function(value, duration) {
        var parsed;
        try {
            parsed = JSON.parse(value || '[]');
        } catch (error) {
            parsed = [];
        }
        if (!Array.isArray(parsed)) {
            return [];
        }

        return mergeRanges(parsed.slice(0, MAX_RANGES), duration);
    };

    var mergeRanges = function(ranges, duration) {
        var clean = [];
        ranges.forEach(function(range) {
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
            if (last && range[0] <= last[1] + 0.5) {
                last[1] = Math.max(last[1], range[1]);
            } else if (merged.length < MAX_RANGES) {
                merged.push([range[0], range[1]]);
            }
        });
        return merged;
    };

    var watchedSeconds = function(ranges) {
        return ranges.reduce(function(total, range) {
            return total + Math.max(0, range[1] - range[0]);
        }, 0);
    };

    var addRange = function(state, start, end) {
        if (!Number.isFinite(start) || !Number.isFinite(end) || end <= start) {
            return;
        }
        state.ranges = mergeRanges(state.ranges.concat([[start, end]]), state.duration);
    };

    var registerSeekRecovery = function(node) {
        var state = {
            target: 0,
            attempts: 0,
            restoring: false,
            timer: null
        };

        var clearTimer = function() {
            if (state.timer !== null) {
                window.clearTimeout(state.timer);
                state.timer = null;
            }
        };

        var reset = function() {
            clearTimer();
            state.target = 0;
            state.attempts = 0;
            state.restoring = false;
        };

        var targetReached = function() {
            return state.target <= 0 || Math.abs(node.currentTime - state.target) <= SEEK_TOLERANCE;
        };

        var restore = function() {
            clearTimer();
            if (
                state.target <= 1.5 ||
                targetReached() ||
                state.attempts >= MAX_SEEK_RECOVERY_ATTEMPTS ||
                node.readyState < 1
            ) {
                return;
            }

            var duration = Number.isFinite(node.duration) ? node.duration : 0;
            var target = duration > 0 ? Math.min(state.target, Math.max(0, duration - 0.25)) : state.target;
            state.restoring = true;
            state.attempts += 1;

            try {
                node.currentTime = target;
            } catch (error) {
                state.restoring = false;
                return;
            }

            window.setTimeout(function() {
                state.restoring = false;
                if (targetReached()) {
                    reset();
                }
            }, SEEK_RECOVERY_DELAY);
        };

        var scheduleRestore = function() {
            if (state.target <= 1.5 || targetReached() || state.attempts >= MAX_SEEK_RECOVERY_ATTEMPTS) {
                return;
            }
            clearTimer();
            state.timer = window.setTimeout(restore, SEEK_RECOVERY_DELAY);
        };

        node.addEventListener('seeking', function() {
            if (state.restoring) {
                return;
            }

            var target = Number(node.currentTime) || 0;
            if (target <= 1.5) {
                reset();
                return;
            }

            state.target = target;
            state.attempts = 0;
        });
        node.addEventListener('seeked', scheduleRestore);
        node.addEventListener('loadedmetadata', scheduleRestore);
        node.addEventListener('canplay', scheduleRestore);
        node.addEventListener('timeupdate', function() {
            if (state.target > 0 && targetReached()) {
                reset();
            }
        });
        node.addEventListener('emptied', scheduleRestore);
    };

    var prepareVideo = function(node) {
        node.setAttribute('controlsList', 'nodownload');
        node.setAttribute('draggable', 'false');
        node.setAttribute('playsinline', '');
        node.setAttribute('webkit-playsinline', '');
        node.setAttribute('x-webkit-airplay', 'allow');
        node.preload = shouldPreloadVideo() ? 'auto' : 'metadata';

        if (isAppleMobile()) {
            node.removeAttribute('disablepictureinpicture');
            try {
                node.disablePictureInPicture = false;
            } catch (error) {
                // Older Safari versions may expose a read-only implementation.
            }
        } else {
            node.disablePictureInPicture = true;
        }

        if (node.getAttribute('data-disable-context-menu') === '1') {
            node.addEventListener('contextmenu', blockBrowserMediaActions, true);
            node.addEventListener('dragstart', blockBrowserMediaActions, true);
            node.addEventListener('selectstart', blockBrowserMediaActions, true);
        }
        node.addEventListener('loadedmetadata', function() {
            markOrientation(node);
        });
        registerSeekRecovery(node);
    };

    var initProgressTracker = function(node) {
        if (node.dataset.progressTrackerReady === '1') {
            return;
        }
        node.dataset.progressTrackerReady = '1';

        var container = node.closest('.mod-videoplayer-container') || document;
        var progressNode = container.querySelector('[data-region="video-progress"]');
        var state = {
            cmid: parseInt(node.getAttribute('data-cmid'), 10) || 0,
            duration: Math.max(0, parseFloat(node.getAttribute('data-total-seconds')) || 0),
            resumeSecond: Math.max(0, parseFloat(node.getAttribute('data-initial-second')) || 0),
            timeSpent: Math.max(0, parseInt(node.getAttribute('data-initial-time-spent'), 10) || 0),
            completion: Math.max(0, parseFloat(node.getAttribute('data-initial-completion')) || 0),
            completed: node.getAttribute('data-initial-completed') === '1',
            ranges: [],
            lastMediaTime: null,
            activeStartedAt: null,
            lastSave: 0,
            saveInFlight: null,
            saveQueued: false,
            restored: false
        };
        state.ranges = parseRanges(node.getAttribute('data-watched-ranges'), state.duration);

        var accrueActiveTime = function() {
            if (state.activeStartedAt === null) {
                return;
            }
            var now = Date.now();
            state.timeSpent += Math.max(0, Math.round((now - state.activeStartedAt) / 1000));
            state.activeStartedAt = now;
        };

        var currentPercent = function() {
            if (!state.duration) {
                return state.completion;
            }
            var percent = watchedSeconds(state.ranges) / state.duration * 100;
            return Math.min(100, Math.max(state.completion, Math.round(percent * 100) / 100));
        };

        var absorbServerRanges = function(value) {
            state.ranges = mergeRanges(state.ranges.concat(parseRanges(value, state.duration)), state.duration);
        };

        var saveProgress = function(force) {
            if (!state.cmid || !Number.isFinite(node.currentTime)) {
                return Promise.resolve();
            }

            var now = Date.now();
            if (!force && now - state.lastSave < SAVE_INTERVAL) {
                return Promise.resolve();
            }

            if (!node.paused && !document.hidden) {
                accrueActiveTime();
            }
            state.lastSave = now;

            if (state.saveInFlight) {
                state.saveQueued = state.saveQueued || force;
                return state.saveInFlight;
            }

            var uniqueSeconds = watchedSeconds(state.ranges);
            var percent = currentPercent();
            var request = {
                methodname: 'mod_videoplayer_save_progress',
                args: {
                    cmid: state.cmid,
                    progress: uniqueSeconds,
                    completed: state.completed || percent >= 100,
                    completionpercentage: percent,
                    lastpage: 0,
                    totalpages: 0,
                    visitedpages: '',
                    lastsecond: Math.max(0, node.currentTime || 0),
                    totalseconds: Math.max(0, state.duration || node.duration || 0),
                    watchedranges: JSON.stringify(state.ranges),
                    timespent: Math.max(0, Math.round(state.timeSpent))
                }
            };

            state.saveInFlight = Ajax.call([request])[0].then(function(response) {
                if (response) {
                    state.completed = Boolean(response.completed);
                    state.completion = Math.max(state.completion, parseFloat(response.completionpercentage) || 0);
                    state.timeSpent = Math.max(state.timeSpent, parseInt(response.timespent, 10) || 0);
                    state.duration = Math.max(state.duration, parseFloat(response.totalseconds) || 0);
                    absorbServerRanges(response.watchedranges || '');
                    if (progressNode) {
                        progressNode.textContent = response.completionpercentage + '%';
                    }
                }
                return response;
            }).catch(Notification.exception).finally(function() {
                state.saveInFlight = null;
                if (state.saveQueued) {
                    state.saveQueued = false;
                    window.setTimeout(function() {
                        saveProgress(true);
                    }, 0);
                }
            });

            return state.saveInFlight;
        };

        var captureMediaProgress = function() {
            var current = Number(node.currentTime);
            if (!Number.isFinite(current)) {
                return;
            }

            if (state.lastMediaTime !== null && !node.seeking && !node.paused) {
                var delta = current - state.lastMediaTime;
                if (delta > 0 && delta <= MAX_CONTIGUOUS_MEDIA_DELTA) {
                    addRange(state, state.lastMediaTime, current);
                }
            }
            state.lastMediaTime = current;
            saveProgress(false);
        };

        node.addEventListener('loadedmetadata', function() {
            if (Number.isFinite(node.duration) && node.duration > 0) {
                state.duration = node.duration;
                state.ranges = mergeRanges(state.ranges, state.duration);
            }

            if (!state.restored && state.resumeSecond > 0 && state.duration > 0) {
                var resume = Math.min(state.resumeSecond, Math.max(0, state.duration - 0.5));
                if (resume > 0) {
                    try {
                        node.currentTime = resume;
                    } catch (error) {
                        // Some Safari states delay seekability until canplay.
                    }
                }
            }
            state.restored = true;
            state.lastMediaTime = Number(node.currentTime) || 0;
        });

        node.addEventListener('canplay', function() {
            if (!state.restored && state.resumeSecond > 0 && Number.isFinite(node.duration) && node.duration > 0) {
                node.currentTime = Math.min(state.resumeSecond, Math.max(0, node.duration - 0.5));
                state.restored = true;
            }
        });

        node.addEventListener('play', function() {
            state.lastMediaTime = Number(node.currentTime) || 0;
            if (!document.hidden) {
                state.activeStartedAt = Date.now();
            }
        });

        node.addEventListener('timeupdate', captureMediaProgress);

        node.addEventListener('seeking', function() {
            state.lastMediaTime = null;
        });

        node.addEventListener('seeked', function() {
            state.lastMediaTime = Number(node.currentTime) || 0;
            saveProgress(true);
        });

        node.addEventListener('pause', function() {
            accrueActiveTime();
            state.activeStartedAt = null;
            saveProgress(true);
        });

        node.addEventListener('ended', function() {
            var current = Number(node.currentTime) || state.duration;
            if (state.lastMediaTime !== null && current > state.lastMediaTime) {
                addRange(state, state.lastMediaTime, current);
            }
            accrueActiveTime();
            state.activeStartedAt = null;
            saveProgress(true);
        });

        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                accrueActiveTime();
                state.activeStartedAt = null;
                saveProgress(true);
            } else if (!node.paused) {
                state.activeStartedAt = Date.now();
                state.lastMediaTime = Number(node.currentTime) || 0;
            }
        });

        window.addEventListener('pagehide', function() {
            accrueActiveTime();
            state.activeStartedAt = null;
            saveProgress(true);
        });

        window.setInterval(function() {
            if (!node.paused && !document.hidden) {
                saveProgress(false);
            }
        }, HEARTBEAT_INTERVAL);
    };

    var init = function() {
        var nodes = Array.prototype.slice.call(document.querySelectorAll('.js-drive-resource-video'));
        if (!nodes.length) {
            return;
        }

        nodes.forEach(function(node) {
            prepareVideo(node);
            initProgressTracker(node);
            markOrientation(node);
        });
    };

    return {
        init: init
    };
});
