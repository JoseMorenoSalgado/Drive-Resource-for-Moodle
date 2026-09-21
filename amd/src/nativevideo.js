/**
 * Drive Resource native HTML5 video player.
 *
 * @module     mod_videoplayer/nativevideo
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax'], function(Ajax) {
    var SAVE_INTERVAL_MS = 15000;
    var RESUME_GUARD_SECONDS = 3;
    var LOADING_DELAY_MS = 700;
    var STALL_RECOVERY_MS = 8000;
    var RECOVERY_RESET_MS = 30000;
    var MAX_RECOVERY_ATTEMPTS = 3;
    var MAX_CONTIGUOUS_MEDIA_DELTA = 5;
    var MAX_WATCHED_RANGES = 512;

    var formatTime = function(seconds) {
        if (!Number.isFinite(seconds) || seconds < 0) {
            return '0:00';
        }
        var value = Math.floor(seconds);
        var hours = Math.floor(value / 3600);
        var minutes = Math.floor((value % 3600) / 60);
        var secs = value % 60;
        if (hours > 0) {
            return hours + ':' + String(minutes).padStart(2, '0') + ':' + String(secs).padStart(2, '0');
        }
        return minutes + ':' + String(secs).padStart(2, '0');
    };

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

    var collectPlaybackSample = function(video, ranges, lastMediaTime) {
        var currentTime = Number(video.currentTime);
        if (!Number.isFinite(currentTime)) {
            return {ranges: ranges, lastMediaTime: lastMediaTime};
        }

        if (lastMediaTime === null || video.seeking || video.paused) {
            return {ranges: ranges, lastMediaTime: currentTime};
        }

        var delta = currentTime - lastMediaTime;
        if (delta <= 0 || delta > MAX_CONTIGUOUS_MEDIA_DELTA) {
            return {ranges: ranges, lastMediaTime: currentTime};
        }

        return {
            ranges: mergeRanges(ranges.concat([[lastMediaTime, currentTime]]), video.duration),
            lastMediaTime: currentTime
        };
    };

    var blockEvent = function(event) {
        event.preventDefault();
        event.stopPropagation();
        return false;
    };

    var initPlayer = function(root) {
        if (root.dataset.customPlayerReady === '1') {
            return;
        }
        root.dataset.customPlayerReady = '1';

        var video = root.querySelector('.js-drive-resource-video');
        var loading = root.querySelector('.js-video-loading');
        var errorBox = root.querySelector('.js-video-error');
        var retry = root.querySelector('.js-video-retry');
        var bigPlay = root.querySelector('.js-video-big-play');
        var toggle = root.querySelector('.js-video-toggle');
        var seek = root.querySelector('.js-video-seek');
        var buffer = root.querySelector('.js-video-buffer');
        var current = root.querySelector('.js-video-current');
        var durationNode = root.querySelector('.js-video-duration');
        var mute = root.querySelector('.js-video-mute');
        var volume = root.querySelector('.js-video-volume');
        var speed = root.querySelector('.js-video-speed');
        var fullscreen = root.querySelector('.js-video-fullscreen');
        var progressLabel = root.querySelector('.js-video-progress-label');
        var frame = root.querySelector('.js-native-video-frame');
        var primary = frame ? frame.dataset.primarySrc || '' : '';
        var fallback = frame ? frame.dataset.fallbackSrc || '' : '';
        var cmid = parseInt(root.dataset.cmid, 10) || 0;
        var initialPosition = Math.max(0, parseFloat(root.dataset.initialPosition) || 0);
        var initialTimeSpent = Math.max(0, parseInt(root.dataset.initialTimespent, 10) || 0);
        var initialCompletion = Math.max(0, parseFloat(root.dataset.initialCompletion) || 0);
        var disableContextMenu = root.dataset.disableContextMenu === '1';
        var trackingEnabled = root.dataset.trackingEnabled === '1';
        var fallbackActive = false;
        var controlsTimer = null;
        var loadingTimer = null;
        var stallTimer = null;
        var recoveryResetTimer = null;
        var saveTimer = null;
        var savePending = false;
        var saveQueued = false;
        var lastActiveTick = Date.now();
        var pageVisible = !document.hidden;
        var activeSeconds = initialTimeSpent;
        var restoredPosition = false;
        var pendingPosition = initialPosition;
        var resumeAfterReload = false;
        var recoveryAttempts = 0;
        var watchedRanges = parseRanges(root.dataset.watchedRanges || '[]', 0);
        var lastMediaTime = null;

        if (!video || !frame || !primary) {
            return;
        }

        video.controls = false;
        video.preload = 'metadata';
        video.setAttribute('playsinline', '');
        video.setAttribute('webkit-playsinline', '');
        video.setAttribute('controlslist', 'nodownload');
        video.setAttribute('draggable', 'false');
        if ('disablePictureInPicture' in video) {
            video.disablePictureInPicture = true;
        }

        if (disableContextMenu) {
            [root, frame, video].forEach(function(node) {
                ['contextmenu', 'dragstart'].forEach(function(name) {
                    node.addEventListener(name, blockEvent, true);
                });
            });
        }

        var setLoading = function(visible, immediate) {
            if (!loading) {
                return;
            }

            window.clearTimeout(loadingTimer);
            loadingTimer = null;

            if (!visible) {
                loading.hidden = true;
                return;
            }

            if (immediate) {
                loading.hidden = false;
                return;
            }

            loadingTimer = window.setTimeout(function() {
                loading.hidden = false;
            }, LOADING_DELAY_MS);
        };

        var setError = function(visible) {
            if (errorBox) {
                errorBox.hidden = !visible;
            }
            root.classList.toggle('has-video-error', visible);
        };

        var syncPlayState = function() {
            var playing = !video.paused && !video.ended;
            root.classList.toggle('is-playing', playing);
            if (toggle) {
                toggle.setAttribute('aria-label', playing
                    ? (toggle.dataset.labelPause || 'Pause')
                    : (toggle.dataset.labelPlay || 'Play'));
            }
            if (bigPlay) {
                bigPlay.hidden = playing;
            }
        };

        var completionPercentage = function() {
            if (!Number.isFinite(video.duration) || video.duration <= 0) {
                return initialCompletion;
            }
            var watched = watchedSeconds(watchedRanges);
            var percentage = (watched / video.duration) * 100;
            return Math.max(initialCompletion, Math.max(0, Math.min(100, percentage)));
        };

        var updateTime = function() {
            if (current) {
                current.textContent = formatTime(video.currentTime);
            }
            if (durationNode) {
                durationNode.textContent = formatTime(video.duration);
            }
            if (seek && Number.isFinite(video.duration) && video.duration > 0) {
                seek.value = String(Math.round((video.currentTime / video.duration) * 1000));
            }
            if (progressLabel) {
                progressLabel.textContent = Math.round(completionPercentage()) + '%';
            }
        };

        var updateBuffered = function() {
            if (!buffer || !Number.isFinite(video.duration) || video.duration <= 0 || !video.buffered.length) {
                return;
            }
            try {
                var end = video.buffered.end(video.buffered.length - 1);
                var percent = Math.max(0, Math.min(100, (end / video.duration) * 100));
                buffer.style.width = percent + '%';
            } catch (error) {
                // TimeRanges may mutate while being inspected.
            }
        };

        var updateActiveTime = function() {
            var now = Date.now();
            if (!video.paused && !video.ended && pageVisible) {
                activeSeconds += Math.max(0, (now - lastActiveTick) / 1000);
            }
            lastActiveTick = now;
        };

        var sendProgress = function(force) {
            if (!trackingEnabled || !cmid || !Number.isFinite(video.duration) || video.duration <= 0) {
                return Promise.resolve();
            }

            updateActiveTime();
            if (savePending) {
                saveQueued = saveQueued || force;
                return Promise.resolve();
            }

            savePending = true;
            var percentage = completionPercentage();
            var completed = video.ended || percentage >= 99.5;
            var request = {
                methodname: 'mod_videoplayer_save_progress',
                args: {
                    cmid: cmid,
                    progress: watchedSeconds(watchedRanges),
                    completed: completed,
                    completionpercentage: Math.round(percentage * 100) / 100,
                    lastpage: 0,
                    totalpages: 0,
                    timespent: Math.round(activeSeconds),
                    lastposition: Math.max(0, video.currentTime),
                    duration: Math.max(0, video.duration),
                    watchedranges: JSON.stringify(watchedRanges)
                }
            };

            return Ajax.call([request])[0]
                .then(function(response) {
                    if (response) {
                        watchedRanges = parseRanges(response.watchedranges || '[]', video.duration);
                        initialCompletion = Math.max(
                            initialCompletion,
                            parseFloat(response.completionpercentage) || 0
                        );
                        if (progressLabel) {
                            progressLabel.textContent = Math.round(response.completionpercentage) + '%';
                        }
                    }
                    return response;
                })
                .catch(function(error) {
                    if (window.console && window.console.warn) {
                        window.console.warn('Drive Resource progress save failed.', error);
                    }
                })
                .then(function() {
                    savePending = false;
                    if (saveQueued) {
                        saveQueued = false;
                        return sendProgress(true);
                    }
                    return null;
                });
        };

        var markOrientation = function() {
            frame.classList.remove('is-portrait-video', 'is-landscape-video', 'is-square-video');
            if (!video.videoWidth || !video.videoHeight) {
                return;
            }
            if (video.videoHeight > video.videoWidth) {
                frame.classList.add('is-portrait-video');
            } else if (video.videoHeight < video.videoWidth) {
                frame.classList.add('is-landscape-video');
            } else {
                frame.classList.add('is-square-video');
            }
        };

        var clearStallTimer = function() {
            window.clearTimeout(stallTimer);
            stallTimer = null;
        };

        var scheduleRecoveryReset = function() {
            window.clearTimeout(recoveryResetTimer);
            recoveryResetTimer = window.setTimeout(function() {
                recoveryAttempts = 0;
            }, RECOVERY_RESET_MS);
        };

        var recoveryUrl = function(src, forceRefresh) {
            try {
                var url = new URL(src, window.location.href);
                if (forceRefresh) {
                    url.searchParams.set('refresh', '1');
                }
                url.searchParams.set('retry', String(recoveryAttempts));
                return url.toString();
            } catch (error) {
                var separator = src.indexOf('?') === -1 ? '?' : '&';
                return src + separator + (forceRefresh ? 'refresh=1&' : '')
                    + 'retry=' + encodeURIComponent(String(recoveryAttempts));
            }
        };

        var loadSource = function(src, isFallback, resumePosition, autoplay) {
            if (!src) {
                setLoading(false);
                setError(true);
                return;
            }

            clearStallTimer();
            fallbackActive = Boolean(isFallback);
            frame.dataset.streamMode = fallbackActive ? 'source' : 'transcoded';
            restoredPosition = false;
            pendingPosition = Math.max(0, Number(resumePosition) || 0);
            resumeAfterReload = Boolean(autoplay);
            lastMediaTime = null;
            setError(false);
            setLoading(true, true);
            video.pause();
            video.removeAttribute('src');
            video.src = src;
            video.load();
        };

        var recoverStream = function() {
            clearStallTimer();

            if (recoveryAttempts >= MAX_RECOVERY_ATTEMPTS) {
                setLoading(false);
                setError(true);
                return;
            }

            var position = Number.isFinite(video.currentTime) ? video.currentTime : 0;
            var autoplay = !video.paused && !video.ended;
            recoveryAttempts += 1;

            if (!fallbackActive && recoveryAttempts === 1) {
                loadSource(recoveryUrl(primary, true), false, position, autoplay);
                return;
            }

            if (!fallbackActive && fallback && fallback !== primary) {
                loadSource(recoveryUrl(fallback, false), true, position, autoplay);
                return;
            }

            loadSource(recoveryUrl(primary, true), false, position, autoplay);
        };

        var scheduleStallRecovery = function() {
            if (video.paused || video.ended) {
                return;
            }

            setLoading(true, false);
            clearStallTimer();
            stallTimer = window.setTimeout(function() {
                if (!video.paused && !video.ended) {
                    recoverStream();
                }
            }, STALL_RECOVERY_MS);
        };

        var tryFallback = function() {
            var position = Number.isFinite(video.currentTime) ? video.currentTime : 0;
            var autoplay = !video.paused && !video.ended;

            if (!fallbackActive && recoveryAttempts === 0) {
                recoveryAttempts += 1;
                loadSource(recoveryUrl(primary, true), false, position, autoplay);
                return;
            }

            if (!fallbackActive && fallback && fallback !== primary) {
                recoveryAttempts += 1;
                loadSource(recoveryUrl(fallback, false), true, position, autoplay);
                return;
            }

            setLoading(false);
            setError(true);
        };

        var togglePlayback = function() {
            if (video.paused || video.ended) {
                var promise = video.play();
                if (promise && typeof promise.catch === 'function') {
                    promise.catch(syncPlayState);
                }
            } else {
                video.pause();
            }
        };

        var showControls = function() {
            frame.classList.remove('controls-hidden');
            window.clearTimeout(controlsTimer);
            if (!video.paused) {
                controlsTimer = window.setTimeout(function() {
                    frame.classList.add('controls-hidden');
                }, 2800);
            }
        };

        [toggle, bigPlay].forEach(function(node) {
            if (node) {
                node.addEventListener('click', function(event) {
                    event.preventDefault();
                    togglePlayback();
                    showControls();
                });
            }
        });

        video.addEventListener('click', function() {
            togglePlayback();
            showControls();
        });
        frame.addEventListener('pointermove', showControls);
        frame.addEventListener('touchstart', showControls, {passive: true});
        frame.addEventListener('mouseleave', function() {
            if (!video.paused) {
                frame.classList.add('controls-hidden');
            }
        });

        video.addEventListener('loadstart', function() {
            setLoading(true, true);
            syncPlayState();
        });
        video.addEventListener('loadedmetadata', function() {
            setLoading(false);
            setError(false);
            markOrientation();
            watchedRanges = mergeRanges(watchedRanges, video.duration);
            var targetPosition = pendingPosition > 0 ? pendingPosition : initialPosition;
            if (!restoredPosition && targetPosition > 0 && targetPosition < video.duration - RESUME_GUARD_SECONDS) {
                try {
                    video.currentTime = targetPosition;
                } catch (error) {
                    // Some engines reject seeking until seekable ranges exist.
                }
                restoredPosition = true;
                pendingPosition = 0;
            }
            updateTime();
            updateBuffered();
        });
        video.addEventListener('canplay', function() {
            clearStallTimer();
            setLoading(false);
            setError(false);
            if (resumeAfterReload) {
                resumeAfterReload = false;
                var promise = video.play();
                if (promise && typeof promise.catch === 'function') {
                    promise.catch(syncPlayState);
                }
            }
        });
        video.addEventListener('waiting', scheduleStallRecovery);
        video.addEventListener('stalled', scheduleStallRecovery);
        video.addEventListener('playing', function() {
            clearStallTimer();
            scheduleRecoveryReset();
            lastActiveTick = Date.now();
            lastMediaTime = Number(video.currentTime) || 0;
            setLoading(false);
            syncPlayState();
            showControls();
        });
        video.addEventListener('pause', function() {
            clearStallTimer();
            updateActiveTime();
            syncPlayState();
            showControls();
            sendProgress(true);
        });
        video.addEventListener('ended', function() {
            clearStallTimer();
            updateActiveTime();
            syncPlayState();
            showControls();
            sendProgress(true);
        });
        video.addEventListener('timeupdate', function() {
            var sample = collectPlaybackSample(video, watchedRanges, lastMediaTime);
            watchedRanges = sample.ranges;
            lastMediaTime = sample.lastMediaTime;
            updateTime();
        });
        video.addEventListener('seeking', function() {
            lastMediaTime = null;
        });
        video.addEventListener('seeked', function() {
            lastMediaTime = Number(video.currentTime) || 0;
            sendProgress(true);
        });
        video.addEventListener('progress', updateBuffered);
        video.addEventListener('durationchange', updateTime);
        video.addEventListener('error', tryFallback);

        if (seek) {
            seek.addEventListener('input', function() {
                if (!Number.isFinite(video.duration) || video.duration <= 0) {
                    return;
                }
                video.currentTime = (Number(seek.value) / 1000) * video.duration;
                updateTime();
            });
            seek.addEventListener('change', function() {
                sendProgress(true);
            });
        }

        var syncVolumeState = function() {
            var muted = video.muted || video.volume === 0;
            root.classList.toggle('is-muted', muted);
            if (mute) {
                mute.setAttribute('aria-label', muted
                    ? (mute.dataset.labelUnmute || 'Unmute')
                    : (mute.dataset.labelMute || 'Mute'));
            }
            if (volume) {
                volume.value = String(video.muted ? 0 : video.volume);
            }
        };

        if (mute) {
            mute.addEventListener('click', function() {
                video.muted = !video.muted;
                syncVolumeState();
            });
        }
        if (volume) {
            volume.addEventListener('input', function() {
                video.volume = Number(volume.value);
                video.muted = video.volume === 0;
                syncVolumeState();
            });
        }
        video.addEventListener('volumechange', syncVolumeState);

        if (speed) {
            speed.addEventListener('change', function() {
                var value = Number(speed.value);
                if (Number.isFinite(value) && value >= 0.5 && value <= 2) {
                    video.playbackRate = value;
                }
            });
        }

        var syncFullscreenState = function() {
            frame.classList.toggle('is-fullscreen', document.fullscreenElement === frame);
        };
        if (fullscreen) {
            fullscreen.addEventListener('click', function() {
                if (document.fullscreenElement) {
                    document.exitFullscreen();
                    return;
                }
                if (frame.requestFullscreen) {
                    var request = frame.requestFullscreen();
                    if (request && typeof request.catch === 'function') {
                        request.catch(function() {
                            if (video.webkitEnterFullscreen) {
                                video.webkitEnterFullscreen();
                            }
                        });
                    }
                } else if (video.webkitEnterFullscreen) {
                    video.webkitEnterFullscreen();
                }
            });
        }
        document.addEventListener('fullscreenchange', syncFullscreenState);

        if (retry) {
            retry.addEventListener('click', function() {
                fallbackActive = false;
                recoveryAttempts = 0;
                loadSource(primary, false, video.currentTime, false);
            });
        }

        frame.addEventListener('keydown', function(event) {
            var key = (event.key || '').toLowerCase();
            if (key === ' ' || key === 'k') {
                event.preventDefault();
                togglePlayback();
            } else if (key === 'arrowleft') {
                event.preventDefault();
                video.currentTime = Math.max(0, video.currentTime - 5);
            } else if (key === 'arrowright') {
                event.preventDefault();
                video.currentTime = Math.min(video.duration || video.currentTime + 5, video.currentTime + 5);
            } else if (key === 'm') {
                event.preventDefault();
                video.muted = !video.muted;
            } else if (key === 'f' && fullscreen) {
                event.preventDefault();
                fullscreen.click();
            }
            updateTime();
            showControls();
        });

        document.addEventListener('visibilitychange', function() {
            updateActiveTime();
            pageVisible = !document.hidden;
            if (!pageVisible) {
                sendProgress(true);
            }
        });
        window.addEventListener('pagehide', function() {
            sendProgress(true);
            clearStallTimer();
            window.clearTimeout(loadingTimer);
            window.clearTimeout(recoveryResetTimer);
            if (saveTimer) {
                window.clearInterval(saveTimer);
                saveTimer = null;
            }
        });

        saveTimer = window.setInterval(function() {
            if (!video.paused && !video.ended) {
                sendProgress(false);
            }
        }, SAVE_INTERVAL_MS);

        syncVolumeState();
        syncPlayState();
        loadSource(primary, false, initialPosition, false);
    };

    var init = function() {
        Array.prototype.forEach.call(document.querySelectorAll('.js-custom-video-player'), initPlayer);
    };

    return {init: init};
});
