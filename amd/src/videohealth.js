// This file is part of Moodle - http://moodle.org/

/**
 * Protected video health monitoring and controlled recovery.
 *
 * @module     mod_videoplayer/videohealth
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {
    var MAX_RETRIES = 3;
    var STALL_TIMEOUT = 10000;
    var PROBE_RANGE = 'bytes=0-1';

    var getSourceUrl = function(node) {
        if (node.currentSrc) {
            return node.currentSrc;
        }

        var source = node.querySelector('source');
        return source ? source.src : node.src;
    };

    var cancelProbeBody = function(response) {
        if (response.body && typeof response.body.cancel === 'function') {
            response.body.cancel().catch(function() {
                // The response may already be closed after a two-byte range.
            });
        }
    };

    var probeTransport = function(node) {
        var url = getSourceUrl(node);
        if (!url || typeof window.fetch !== 'function') {
            return Promise.resolve({
                ok: false,
                status: 'PROBE_UNAVAILABLE',
                httpStatus: 0
            });
        }

        return window.fetch(url, {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                Range: PROBE_RANGE
            }
        }).then(function(response) {
            var status = response.headers.get('X-Drive-Resource-Status') || '';
            var contentType = (response.headers.get('Content-Type') || '').toLowerCase();
            var healthy = response.ok && status === 'MEDIA' && contentType.indexOf('video/') === 0;
            cancelProbeBody(response);

            return {
                ok: healthy,
                status: status || (response.ok ? 'MEDIA_UNKNOWN' : 'HTTP_ERROR'),
                httpStatus: response.status
            };
        }).catch(function() {
            return {
                ok: false,
                status: 'NETWORK_ERROR',
                httpStatus: 0
            };
        });
    };

    var getStatusElements = function(node) {
        var frame = node.closest('.mod-videoplayer-native-frame');
        if (!frame) {
            return null;
        }

        var overlay = frame.querySelector('[data-region="video-health"]');
        if (!overlay) {
            return null;
        }

        return {
            frame: frame,
            overlay: overlay,
            message: overlay.querySelector('[data-region="video-health-message"]'),
            retry: overlay.querySelector('[data-action="retry-video"]')
        };
    };

    var hideStatus = function(elements) {
        if (!elements) {
            return;
        }

        elements.overlay.hidden = true;
        elements.frame.classList.remove('has-video-error');
        if (elements.retry) {
            elements.retry.hidden = false;
            elements.retry.disabled = false;
        }
    };

    var showStatus = function(elements, type, recoverable) {
        if (!elements) {
            return;
        }

        var message = elements.overlay.getAttribute('data-message-' + type) ||
            elements.overlay.getAttribute('data-message-generic') || '';

        if (elements.message) {
            elements.message.textContent = message;
        }
        if (elements.retry) {
            elements.retry.hidden = !recoverable;
            elements.retry.disabled = !recoverable;
        }

        elements.overlay.hidden = false;
        elements.frame.classList.add('has-video-error');
    };

    var classifyFailure = function(mediaCode, diagnostic) {
        if (diagnostic && !diagnostic.ok) {
            return diagnostic.status === 'NETWORK_ERROR' ? 'network' : 'unavailable';
        }

        if (mediaCode === 3 || mediaCode === 4) {
            return 'codec';
        }
        if (mediaCode === 2) {
            return 'network';
        }

        return 'generic';
    };

    var registerNode = function(node) {
        if (node.dataset.videoHealthReady === '1') {
            return;
        }
        node.dataset.videoHealthReady = '1';

        var elements = getStatusElements(node);
        var state = {
            retries: 0,
            probeGeneration: 0,
            stallTimer: null,
            resumeSecond: 0,
            resumePlayback: false
        };

        var clearStallTimer = function() {
            if (state.stallTimer !== null) {
                window.clearTimeout(state.stallTimer);
                state.stallTimer = null;
            }
        };

        var markHealthy = function() {
            clearStallTimer();
            state.probeGeneration += 1;
            if (!node.error) {
                state.retries = 0;
                hideStatus(elements);
            }
        };

        var diagnose = function(mediaCode, fallbackType) {
            var generation = ++state.probeGeneration;

            probeTransport(node).then(function(diagnostic) {
                if (generation !== state.probeGeneration) {
                    return;
                }

                var type = classifyFailure(mediaCode, diagnostic);
                if (fallbackType && type === 'generic') {
                    type = fallbackType;
                }

                var recoverable = type !== 'codec' && state.retries < MAX_RETRIES;
                showStatus(elements, type, recoverable);
            });
        };

        var scheduleStallDiagnosis = function() {
            clearStallTimer();
            if (node.paused || node.ended) {
                return;
            }

            state.stallTimer = window.setTimeout(function() {
                state.stallTimer = null;
                if (node.paused || node.ended || node.readyState >= 3) {
                    return;
                }
                diagnose(2, 'network');
            }, STALL_TIMEOUT);
        };

        node.addEventListener('error', function() {
            clearStallTimer();
            var mediaCode = node.error ? node.error.code : 0;
            if (mediaCode === 1) {
                return;
            }
            diagnose(mediaCode, 'generic');
        });

        node.addEventListener('waiting', scheduleStallDiagnosis);
        node.addEventListener('stalled', scheduleStallDiagnosis);
        node.addEventListener('loadedmetadata', markHealthy);
        node.addEventListener('canplay', markHealthy);
        node.addEventListener('playing', markHealthy);
        node.addEventListener('timeupdate', clearStallTimer);
        node.addEventListener('pause', clearStallTimer);
        node.addEventListener('ended', clearStallTimer);

        if (elements && elements.retry) {
            elements.retry.addEventListener('click', function() {
                if (state.retries >= MAX_RETRIES) {
                    elements.retry.disabled = true;
                    return;
                }

                state.retries += 1;
                state.probeGeneration += 1;
                clearStallTimer();
                state.resumeSecond = Number.isFinite(node.currentTime) ? node.currentTime : 0;
                state.resumePlayback = !node.paused && !node.ended;
                hideStatus(elements);

                var restore = function() {
                    node.removeEventListener('loadedmetadata', restore);
                    if (state.resumeSecond > 0 && Number.isFinite(node.duration) && node.duration > 0) {
                        try {
                            node.currentTime = Math.min(
                                state.resumeSecond,
                                Math.max(0, node.duration - 0.25)
                            );
                        } catch (error) {
                            // Safari may delay seekability until canplay.
                        }
                    }

                    if (state.resumePlayback) {
                        var playPromise = node.play();
                        if (playPromise && typeof playPromise.catch === 'function') {
                            playPromise.catch(function() {
                                // A browser autoplay policy can still require an explicit play action.
                            });
                        }
                    }
                };

                node.addEventListener('loadedmetadata', restore);
                node.load();
            });
        }
    };

    var init = function() {
        Array.prototype.slice.call(
            document.querySelectorAll('.js-drive-resource-video')
        ).forEach(registerNode);
    };

    return {
        init: init
    };
});
