// This file is part of Moodle - http://moodle.org/

/**
 * Direct browser-to-Bunny Stream TUS uploader.
 *
 * Bunny API credentials never reach this module. Moodle asks WHMCS for a
 * short-lived, video-scoped TUS signature and video bytes then travel directly
 * from the teacher browser to Bunny Stream.
 *
 * @module     mod_videoplayer/bunnyupload
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax', 'core/notification'], function(Ajax, Notification) {
    'use strict';

    var TUS_VERSION = '1.0.0';
    var CHUNK_SIZE = 8 * 1024 * 1024;
    var RETRY_DELAYS = [0, 1000, 3000, 5000, 10000, 20000];

    var delay = function(ms) {
        return new Promise(function(resolve) {
            window.setTimeout(resolve, ms);
        });
    };

    var formatBytes = function(bytes) {
        var value = Number(bytes) || 0;
        if (value <= 0) {
            return '0 B';
        }
        var units = ['B', 'KB', 'MB', 'GB', 'TB'];
        var index = Math.min(units.length - 1, Math.floor(Math.log(value) / Math.log(1024)));
        var amount = value / Math.pow(1024, index);
        return amount.toFixed(index >= 3 ? 2 : 1) + ' ' + units[index];
    };

    var base64Utf8 = function(value) {
        var bytes = new TextEncoder().encode(String(value || ''));
        var binary = '';
        for (var i = 0; i < bytes.length; i++) {
            binary += String.fromCharCode(bytes[i]);
        }
        return window.btoa(binary);
    };

    var uploadMetadata = function(file, title) {
        var activityTitle = String(title || '').trim() || file.name;

        return [
            'filename ' + base64Utf8(file.name),
            'filetype ' + base64Utf8(file.type || 'application/octet-stream'),
            'title ' + base64Utf8(activityTitle)
        ].join(',');
    };

    var authHeaders = function(auth) {
        return {
            'Tus-Resumable': TUS_VERSION,
            'AuthorizationSignature': auth.signature,
            'AuthorizationExpire': String(auth.expiration),
            'VideoId': auth.videoid,
            'LibraryId': String(auth.libraryid)
        };
    };

    var createUpload = async function(auth, file, title) {
        var headers = authHeaders(auth);
        headers['Upload-Length'] = String(file.size);
        headers['Upload-Metadata'] = uploadMetadata(file, title);

        var response = await window.fetch(auth.endpoint, {
            method: 'POST',
            headers: headers,
            mode: 'cors',
            credentials: 'omit',
            cache: 'no-store',
            redirect: 'error'
        });
        if (response.status !== 201) {
            throw new Error('TUS create failed with HTTP ' + response.status);
        }

        var location = response.headers.get('Location');
        if (!location) {
            throw new Error('TUS server did not return an upload location');
        }

        var uploadUrl = new URL(location, auth.endpoint);
        if (uploadUrl.protocol !== 'https:' || uploadUrl.hostname !== 'video.bunnycdn.com') {
            throw new Error('TUS server returned an unexpected upload host');
        }
        return uploadUrl.toString();
    };

    var readOffset = async function(auth, uploadUrl) {
        var response = await window.fetch(uploadUrl, {
            method: 'HEAD',
            headers: authHeaders(auth),
            mode: 'cors',
            credentials: 'omit',
            cache: 'no-store',
            redirect: 'error'
        });
        if (!response.ok) {
            throw new Error('TUS resume probe failed with HTTP ' + response.status);
        }
        var offset = Number(response.headers.get('Upload-Offset'));
        if (!Number.isFinite(offset) || offset < 0) {
            throw new Error('TUS server returned an invalid upload offset');
        }
        return offset;
    };

    var patchChunk = function(auth, uploadUrl, blob, offset, total, onProgress) {
        return new Promise(function(resolve, reject) {
            var xhr = new XMLHttpRequest();
            xhr.open('PATCH', uploadUrl, true);
            var headers = authHeaders(auth);
            Object.keys(headers).forEach(function(name) {
                xhr.setRequestHeader(name, headers[name]);
            });
            xhr.setRequestHeader('Content-Type', 'application/offset+octet-stream');
            xhr.setRequestHeader('Upload-Offset', String(offset));
            xhr.responseType = 'text';
            xhr.timeout = 600000;

            xhr.upload.addEventListener('progress', function(event) {
                if (event.lengthComputable && typeof onProgress === 'function') {
                    onProgress(Math.min(total, offset + event.loaded), total);
                }
            });
            xhr.addEventListener('load', function() {
                if (xhr.status >= 200 && xhr.status < 300) {
                    var nextOffset = Number(xhr.getResponseHeader('Upload-Offset'));
                    resolve(Number.isFinite(nextOffset) ? nextOffset : offset + blob.size);
                    return;
                }
                reject(new Error('TUS patch failed with HTTP ' + xhr.status));
            });
            xhr.addEventListener('error', function() {
                reject(new Error('Network error while uploading a TUS chunk'));
            });
            xhr.addEventListener('timeout', function() {
                reject(new Error('TUS chunk upload timed out'));
            });
            xhr.addEventListener('abort', function() {
                reject(new Error('TUS chunk upload was aborted'));
            });
            xhr.send(blob);
        });
    };

    var uploadFile = async function(auth, file, callbacks) {
        var uploadUrl = await createUpload(auth, file, callbacks.title);
        var offset = 0;
        var retry = 0;

        while (offset < file.size) {
            if (Math.floor(Date.now() / 1000) >= Number(auth.expiration) - 90) {
                if (typeof callbacks.refreshAuth !== 'function') {
                    throw new Error('The direct-upload authorisation expired before the upload completed');
                }
                auth = await callbacks.refreshAuth(auth);
            }

            var end = Math.min(file.size, offset + CHUNK_SIZE);
            var chunk = file.slice(offset, end);

            try {
                offset = await patchChunk(auth, uploadUrl, chunk, offset, file.size, callbacks.onProgress);
                retry = 0;
            } catch (error) {
                if (retry >= RETRY_DELAYS.length - 1) {
                    throw error;
                }
                retry++;
                if (typeof callbacks.onRetry === 'function') {
                    callbacks.onRetry(retry);
                }
                await delay(RETRY_DELAYS[retry]);
                try {
                    offset = await readOffset(auth, uploadUrl);
                } catch (ignored) {
                    // Keep the last known offset; the bounded retry loop will
                    // retry or reconcile it on the next successful HEAD.
                }
            }
        }

        if (typeof callbacks.onProgress === 'function') {
            callbacks.onProgress(file.size, file.size);
        }
    };

    var callMoodle = function(methodname, args) {
        return Ajax.call([{methodname: methodname, args: args}])[0];
    };

    var init = function(config) {
        var root = document.getElementById('mod-videoplayer-bunny-upload');
        if (!root || root.dataset.ready === '1') {
            return;
        }
        root.dataset.ready = '1';

        var form = root.closest('form');
        var source = form ? form.querySelector('[name="source"]') : null;
        var fileInput = document.getElementById('mod-videoplayer-bunny-file');
        var dropzone = document.getElementById('mod-videoplayer-bunny-dropzone');
        var fileSummary = document.getElementById('mod-videoplayer-bunny-file-summary');
        var fileName = document.getElementById('mod-videoplayer-bunny-file-name');
        var fileSize = document.getElementById('mod-videoplayer-bunny-file-size');
        var startButton = document.getElementById('mod-videoplayer-bunny-start');
        var status = document.getElementById('mod-videoplayer-bunny-status');
        var quota = document.getElementById('mod-videoplayer-bunny-quota');
        var progressSection = document.getElementById('mod-videoplayer-bunny-progress-section');
        var progressWrap = document.getElementById('mod-videoplayer-bunny-progress-wrap');
        var progressPercent = document.getElementById('mod-videoplayer-bunny-progress-percent');
        var progressBytes = document.getElementById('mod-videoplayer-bunny-progress-bytes');
        var assetField = form ? form.querySelector('[name="providerassetid"]') : null;
        var uploadField = form ? form.querySelector('[name="provideruploadid"]') : null;
        var sizeField = form ? form.querySelector('[name="providerfilesize"]') : null;
        var statusField = form ? form.querySelector('[name="providerstatus"]') : null;
        var nameField = form ? form.querySelector('[name="name"]') : null;
        var submitButtons = form ? Array.prototype.slice.call(form.querySelectorAll('[type="submit"]')) : [];
        var selectedFile = null;
        var uploadInProgress = false;
        var progressBar = null;
        var strings = config.strings || {};

        if (
            !form
            || !source
            || !fileInput
            || !dropzone
            || !startButton
            || !assetField
            || !uploadField
            || !sizeField
            || !statusField
        ) {
            return;
        }

        if (progressWrap) {
            progressBar = document.createElement('div');
            progressBar.className = 'progress-bar mod-videoplayer-upload-progress-bar';
            progressBar.setAttribute('role', 'progressbar');
            progressBar.setAttribute('aria-valuemin', '0');
            progressBar.setAttribute('aria-valuemax', '100');
            progressBar.style.width = '0%';
            progressWrap.appendChild(progressBar);
        }

        var setState = function(state) {
            root.dataset.state = state || 'idle';
        };

        var setStatus = function(message, isError) {
            if (!status) {
                return;
            }
            status.textContent = message || '';
            status.classList.toggle('is-error', Boolean(isError));
        };

        var setFormLocked = function(locked) {
            uploadInProgress = Boolean(locked);
            submitButtons.forEach(function(button) {
                button.disabled = uploadInProgress;
            });
            fileInput.disabled = uploadInProgress;
            dropzone.classList.toggle('is-disabled', uploadInProgress);
            root.setAttribute('aria-busy', uploadInProgress ? 'true' : 'false');
            startButton.disabled = uploadInProgress || !selectedFile || source.value !== 'bunnystream';
        };

        var setProgress = function(uploaded, total) {
            if (!progressWrap || !progressBar || !total) {
                return;
            }

            if (progressSection) {
                progressSection.hidden = false;
            }
            progressWrap.hidden = false;

            var percent = Math.max(0, Math.min(100, (uploaded / total) * 100));
            progressBar.style.width = percent.toFixed(1) + '%';
            progressBar.setAttribute('aria-valuenow', percent.toFixed(1));

            if (progressPercent) {
                progressPercent.textContent = Math.floor(percent) + '%';
            }
            if (progressBytes) {
                progressBytes.textContent = formatBytes(uploaded) + ' / ' + formatBytes(total);
            }
        };

        var showQuota = function(info) {
            if (!quota || !info) {
                return;
            }

            quota.hidden = false;
            quota.textContent = (strings.quota || 'Storage after upload: {$a->projected} / {$a->included}')
                .replace('{$a->projected}', formatBytes(info.projectedbytes))
                .replace('{$a->included}', formatBytes(info.includedbytes));
            quota.classList.remove('is-warning');

            if (Number(info.overagebytes) > 0) {
                quota.textContent += ' ' + (strings.overage || 'Overage: {$a}')
                    .replace('{$a}', formatBytes(info.overagebytes));
                quota.classList.add('is-warning');
            }
        };

        var isSupportedVideoFile = function(file) {
            if (!file || file.size <= 0) {
                return false;
            }

            if (String(file.type || '').toLowerCase().indexOf('video/') === 0) {
                return true;
            }

            return /\.(mp4|mov|m4v|webm|mkv|avi|mpeg|mpg)$/i.test(file.name || '');
        };

        var resetProgress = function() {
            if (progressSection) {
                progressSection.hidden = true;
            }
            if (progressWrap) {
                progressWrap.hidden = true;
            }
            if (progressBar) {
                progressBar.style.width = '0%';
                progressBar.setAttribute('aria-valuenow', '0');
            }
            if (progressPercent) {
                progressPercent.textContent = '0%';
            }
            if (progressBytes) {
                progressBytes.textContent = '';
            }
        };

        var showSelectedFile = function(file) {
            if (!file) {
                if (fileSummary) {
                    fileSummary.hidden = true;
                }
                return;
            }

            if (fileName) {
                fileName.textContent = file.name;
            }
            if (fileSize) {
                fileSize.textContent = formatBytes(file.size);
            }
            if (fileSummary) {
                fileSummary.hidden = false;
            }
        };

        var selectFile = function(file) {
            if (!isSupportedVideoFile(file)) {
                selectedFile = null;
                showSelectedFile(null);
                resetProgress();
                setState('error');
                setStatus(strings.invalidtype || strings.failed || 'Select a valid video file.', true);
                syncSourceState();
                return;
            }

            selectedFile = file;
            showSelectedFile(file);
            resetProgress();
            clearQuota();
            setState('ready');
            setStatus((strings.ready || 'Ready to upload.') + ' ' + formatBytes(file.size), false);
            syncSourceState();
        };

        var syncSourceState = function() {
            if (source.value !== 'bunnystream') {
                startButton.disabled = true;
                return;
            }
            startButton.disabled = uploadInProgress || !selectedFile;
            if (!selectedFile && assetField.value) {
                setState('complete');
                setStatus(strings.existing || 'An Elearning Stream video is already linked.', false);
            }
        };

        var activityTitle = function(file) {
            if (nameField && nameField.value) {
                return nameField.value;
            }
            return file.name;
        };

        var clearQuota = function() {
            if (quota) {
                quota.textContent = '';
                quota.hidden = true;
                quota.classList.remove('is-warning');
            }
        };

        fileInput.addEventListener('change', function() {
            var file = fileInput.files && fileInput.files.length ? fileInput.files[0] : null;
            if (!file) {
                syncSourceState();
                return;
            }
            selectFile(file);
        });

        ['dragenter', 'dragover'].forEach(function(eventName) {
            dropzone.addEventListener(eventName, function(event) {
                event.preventDefault();
                if (!uploadInProgress) {
                    dropzone.classList.add('is-dragging');
                }
            });
        });

        ['dragleave', 'drop'].forEach(function(eventName) {
            dropzone.addEventListener(eventName, function(event) {
                event.preventDefault();
                dropzone.classList.remove('is-dragging');
            });
        });

        dropzone.addEventListener('drop', function(event) {
            if (uploadInProgress) {
                return;
            }

            var files = event.dataTransfer ? event.dataTransfer.files : null;
            if (files && files.length) {
                selectFile(files[0]);
            }
        });

        source.addEventListener('change', syncSourceState);

        startButton.addEventListener('click', async function() {
            if (!selectedFile || uploadInProgress || source.value !== 'bunnystream') {
                return;
            }

            setFormLocked(true);
            setState('authorizing');
            setStatus(strings.authorizing || 'Authorizing upload…', false);
            clearQuota();

            try {
                var auth = await callMoodle('mod_videoplayer_create_bunny_upload', {
                    courseid: Number(config.courseid) || 0,
                    cmid: Number(config.cmid) || 0,
                    filename: selectedFile.name,
                    filesize: selectedFile.size,
                    mimetype: selectedFile.type || 'application/octet-stream',
                    title: activityTitle(selectedFile)
                });

                showQuota(auth.quota);
                setState('uploading');
                setStatus(strings.uploading || 'Uploading directly to Elearning Stream…', false);

                await uploadFile(auth, selectedFile, {
                    title: activityTitle(selectedFile),
                    onProgress: setProgress,
                    onRetry: function() {
                        setState('retrying');
                        setStatus(strings.retrying || 'Resuming upload…', false);
                    },
                    refreshAuth: function(currentAuth) {
                        setState('authorizing');
                        setStatus(strings.reauthorizing || 'Refreshing secure upload authorization…', false);
                        return callMoodle('mod_videoplayer_refresh_bunny_upload', {
                            courseid: Number(config.courseid) || 0,
                            cmid: Number(config.cmid) || 0,
                            uploadid: currentAuth.uploadid,
                            videoid: currentAuth.videoid
                        });
                    }
                });

                assetField.value = auth.videoid;
                uploadField.value = auth.uploadid;
                sizeField.value = String(selectedFile.size);
                statusField.value = 'uploaded';

                try {
                    var completed = await callMoodle('mod_videoplayer_complete_bunny_upload', {
                        courseid: Number(config.courseid) || 0,
                        cmid: Number(config.cmid) || 0,
                        uploadid: auth.uploadid,
                        videoid: auth.videoid,
                        filesize: selectedFile.size
                    });
                    statusField.value = completed.status || 'processing';
                } catch (ignored) {
                    // Bytes are already in Bunny. The post-save bind task will
                    // retry server-to-server through WHMCS.
                    statusField.value = 'uploaded';
                }

                setProgress(selectedFile.size, selectedFile.size);
                setState('complete');
                setStatus(strings.processing || 'Upload complete. Video is processing.', false);
                selectedFile = null;
                fileInput.value = '';
                startButton.disabled = true;
            } catch (error) {
                setState('error');
                setStatus(strings.failed || 'Upload failed.', true);
                Notification.exception(error);
            } finally {
                setFormLocked(false);
                syncSourceState();
            }
        });

        syncSourceState();
    };

    return {init: init};
});
