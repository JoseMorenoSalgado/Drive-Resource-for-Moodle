// This file is part of Moodle - http://moodle.org/

/**
 * Protected PDF.js viewer for Drive Resource.
 *
 * @module     mod_videoplayer/pdfviewer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax', 'core/notification'], function(Ajax, Notification) {
    const PDFJS_URL = M.cfg.wwwroot + '/mod/videoplayer/thirdpartylibs/pdfjs/pdf.min.mjs';
    const PDFJS_WORKER_URL = M.cfg.wwwroot + '/mod/videoplayer/thirdpartylibs/pdfjs/pdf.worker.min.mjs';
    const SAVE_INTERVAL = 10000;
    const MIN_ZOOM = 0.75;
    const MAX_ZOOM = 2.75;
    const ZOOM_STEP = 0.15;
    const MOBILE_BREAKPOINT = 768;
    let pdfjsPromise = null;

    const loadPdfJs = function() {
        if (!pdfjsPromise) {
            pdfjsPromise = import(PDFJS_URL).then(function(pdfjsLib) {
                pdfjsLib.GlobalWorkerOptions.workerSrc = PDFJS_WORKER_URL;
                return pdfjsLib;
            });
        }
        return pdfjsPromise;
    };

    const hide = function(node, value) {
        if (node) {
            node.hidden = value;
        }
    };

    const block = function(event) {
        event.preventDefault();
        event.stopPropagation();
        return false;
    };

    const clamp = function(value, min, max) {
        return Math.max(min, Math.min(max, value));
    };

    const isMobileViewport = function() {
        return window.matchMedia('(max-width: ' + (MOBILE_BREAKPOINT - 1) + 'px)').matches;
    };

    const showError = function(root, error) {
        hide(root.querySelector('.mod-videoplayer-pdfjs-canvas-wrap'), true);
        hide(root.querySelector('.mod-videoplayer-pdfjs-topbar'), true);
        hide(root.querySelector('[data-region="pdfjs-loading"]'), true);
        hide(root.querySelector('[data-region="pdfjs-error"]'), false);
        if (error && window.console) {
            window.console.error(error);
        }
    };

    const hardenViewer = function(root, canvas) {
        if (root.getAttribute('data-disable-context-menu') !== '1') {
            return;
        }
        [root, canvas].forEach(function(node) {
            if (!node) {
                return;
            }
            node.setAttribute('draggable', 'false');
            ['contextmenu', 'dragstart', 'copy', 'cut', 'paste', 'selectstart'].forEach(function(name) {
                node.addEventListener(name, block, true);
            });
        });
        root.addEventListener('keydown', function(event) {
            const key = (event.key || '').toLowerCase();
            if ((event.ctrlKey || event.metaKey) && ['s', 'p', 'c', 'a'].indexOf(key) !== -1) {
                block(event);
            }
        }, true);
    };

    const notifyRewards = function(root, rewards) {
        const parent = root.closest('.mod-videoplayer-container');
        const region = parent ? parent.querySelector('[data-region="pdfjs-achievements"]') : null;
        if (!region || !rewards || !rewards.length) {
            return;
        }
        rewards.forEach(function(reward) {
            const item = document.createElement('div');
            item.className = 'alert alert-success mod-videoplayer-reward';
            item.textContent = reward.label + ' +' + reward.points;
            region.appendChild(item);
            window.setTimeout(function() {
                item.remove();
            }, 7000);
        });
    };

    const initViewer = function(root, pdfjsLib) {
        const pdfUrl = root.getAttribute('data-pdf-url');
        const cmid = parseInt(root.getAttribute('data-cmid'), 10) || 0;
        const canvas = root.querySelector('.mod-videoplayer-pdfjs-canvas');
        const previous = root.querySelector('[data-action="previous-page"]');
        const next = root.querySelector('[data-action="next-page"]');
        const fullscreen = root.querySelector('[data-action="fullscreen"]');
        const zoomIn = root.querySelector('[data-action="zoom-in"]');
        const zoomOut = root.querySelector('[data-action="zoom-out"]');
        const fitScreen = root.querySelector('[data-action="fit-screen"]');
        const currentPageNode = root.querySelector('[data-region="current-page"]');
        const totalPagesNode = root.querySelector('[data-region="total-pages"]');
        const zoomLevelNode = root.querySelector('[data-region="zoom-level"]');
        const loading = root.querySelector('[data-region="pdfjs-loading"]');
        const wrap = root.querySelector('.mod-videoplayer-pdfjs-canvas-wrap');
        const container = root.closest('.mod-videoplayer-container') || document;
        const pointsNode = container.querySelector('[data-region="pdfjs-points"]');
        const progressNode = container.querySelector('[data-region="pdfjs-progress"]');
        const searchInput = root.querySelector('[data-region="pdfjs-search-input"]');
        const searchButton = root.querySelector('[data-action="search-pdf"]');
        const searchNext = root.querySelector('[data-action="search-next"]');
        const searchStatus = root.querySelector('[data-region="pdfjs-search-status"]');
        const searchingText = root.getAttribute('data-searching-text') || 'Searching…';
        const trackingEnabled = root.getAttribute('data-tracking-enabled') === '1';
        const pointsLabel = root.getAttribute('data-points-label') || 'points';
        const noMatchesText = root.getAttribute('data-no-matches-text') || 'No matches';

        if (!pdfUrl || !canvas) {
            showError(root);
            return;
        }

        hardenViewer(root, canvas);
        root.classList.toggle('is-mobile-viewer', isMobileViewport());

        const context = canvas.getContext('2d');
        let pdfDocument = null;
        let pageNumber = Math.max(1, parseInt(root.getAttribute('data-initial-page'), 10) || 1);
        let rendering = false;
        let pendingPage = null;
        let firstRender = true;
        let lastSave = 0;
        let activeSeconds = Math.max(0, parseInt(root.getAttribute('data-initial-timespent'), 10) || 0);
        let lastTick = Date.now();
        let pageVisible = !document.hidden;
        let savePending = false;
        let saveQueued = false;
        let saveTimer = null;
        let completed = false;
        let zoomFactor = 1;
        let autoFit = true;
        let touchStartX = 0;
        let touchStartY = 0;
        let touchMoved = false;
        let searchMatches = [];
        let searchIndex = -1;
        let searchToken = 0;

        const isFullscreen = function() {
            return document.fullscreenElement || root.classList.contains('is-fallback-fullscreen');
        };

        const completionPercent = function() {
            if (!pdfDocument || !pdfDocument.numPages) {
                return 0;
            }
            return Math.min(100, Math.round((pageNumber / pdfDocument.numPages) * 10000) / 100);
        };

        const updateZoomStatus = function(baseScale, appliedScale) {
            if (!zoomLevelNode || !baseScale) {
                return;
            }
            const relativeZoom = Math.round((appliedScale / baseScale) * 100);
            zoomLevelNode.textContent = relativeZoom + '%';
        };

        const updateActiveTime = function() {
            const now = Date.now();
            if (pageVisible) {
                activeSeconds += Math.max(0, (now - lastTick) / 1000);
            }
            lastTick = now;
        };

        const saveProgress = function(force) {
            if (!trackingEnabled || !cmid || !pdfDocument) {
                return Promise.resolve();
            }

            updateActiveTime();
            const now = Date.now();
            if (!force && now - lastSave < SAVE_INTERVAL) {
                return Promise.resolve();
            }
            if (savePending) {
                saveQueued = saveQueued || force;
                return Promise.resolve();
            }

            lastSave = now;
            savePending = true;
            const percent = completionPercent();
            completed = completed || percent >= 100;
            const request = {
                methodname: 'mod_videoplayer_save_progress',
                args: {
                    cmid: cmid,
                    progress: activeSeconds,
                    completed: completed,
                    completionpercentage: percent,
                    lastpage: pageNumber,
                    totalpages: pdfDocument.numPages,
                    timespent: Math.round(activeSeconds),
                    lastposition: 0,
                    duration: 0
                }
            };

            return Ajax.call([request])[0].then(function(response) {
                if (response) {
                    completed = Boolean(response.completed);
                    if (pointsNode) {
                        pointsNode.textContent = response.points + ' ' + pointsLabel;
                    }
                    if (progressNode) {
                        progressNode.textContent = response.completionpercentage + '%';
                    }
                    notifyRewards(root, response.rewards);
                }
                return response;
            }).catch(Notification.exception).then(function(response) {
                savePending = false;
                if (saveQueued) {
                    saveQueued = false;
                    return saveProgress(true);
                }
                return response;
            });
        };

        const updateButtons = function() {
            if (!pdfDocument) {
                return;
            }
            if (previous) {
                previous.disabled = pageNumber <= 1;
            }
            if (next) {
                next.disabled = pageNumber >= pdfDocument.numPages;
            }
            if (zoomOut) {
                zoomOut.disabled = zoomFactor <= MIN_ZOOM;
            }
            if (zoomIn) {
                zoomIn.disabled = zoomFactor >= MAX_ZOOM;
            }
            if (fitScreen) {
                fitScreen.classList.toggle('is-active', autoFit && zoomFactor === 1);
            }
            if (currentPageNode) {
                currentPageNode.textContent = String(pageNumber);
            }
            if (totalPagesNode) {
                totalPagesNode.textContent = String(pdfDocument.numPages);
            }
        };

        const prefetch = function(num) {
            if (!pdfDocument || num < 1 || num > pdfDocument.numPages) {
                return null;
            }
            return pdfDocument.getPage(num).catch(function() {
                return null;
            });
        };

        const restoreScrollPosition = function(oldWidth, oldHeight, oldScrollLeft, oldScrollTop) {
            if (!wrap) {
                return;
            }

            if (firstRender || autoFit) {
                wrap.scrollLeft = 0;
                wrap.scrollTop = 0;
                return;
            }

            const widthRatio = oldWidth > 0 ? wrap.scrollWidth / oldWidth : 1;
            const heightRatio = oldHeight > 0 ? wrap.scrollHeight / oldHeight : 1;
            wrap.scrollLeft = oldScrollLeft * widthRatio;
            wrap.scrollTop = oldScrollTop * heightRatio;
        };

        const renderPage = function(num) {
            const oldScrollWidth = wrap ? wrap.scrollWidth : 0;
            const oldScrollHeight = wrap ? wrap.scrollHeight : 0;
            const oldScrollLeft = wrap ? wrap.scrollLeft : 0;
            const oldScrollTop = wrap ? wrap.scrollTop : 0;

            rendering = true;
            canvas.classList.add('is-rendering');
            if (firstRender) {
                hide(loading, false);
            }
            pdfDocument.getPage(num).then(function(page) {
                const horizontalPadding = isMobileViewport() ? 10 : 24;
                const verticalPadding = isMobileViewport() ? 10 : 24;
                const availableWidth = wrap ? Math.max(wrap.clientWidth - horizontalPadding, 280) : 900;
                const availableHeight = wrap ? Math.max(wrap.clientHeight - verticalPadding, 320) : 900;
                const base = page.getViewport({scale: 1});
                const fitWidth = availableWidth / base.width;
                const fitHeight = availableHeight / base.height;
                const baseScale = isFullscreen() ? Math.min(fitWidth, fitHeight) : fitWidth;
                const appliedZoom = autoFit ? 1 : zoomFactor;
                const cssScale = clamp(baseScale * appliedZoom, baseScale * MIN_ZOOM, baseScale * MAX_ZOOM);
                const viewport = page.getViewport({scale: cssScale});
                const pixelRatio = window.devicePixelRatio || 1;
                const outputScale = isFullscreen()
                    ? Math.min(pixelRatio, 3)
                    : Math.min(pixelRatio, 2.25);
                canvas.width = Math.floor(viewport.width * outputScale);
                canvas.height = Math.floor(viewport.height * outputScale);
                canvas.style.width = Math.floor(viewport.width) + 'px';
                canvas.style.height = Math.floor(viewport.height) + 'px';
                context.setTransform(1, 0, 0, 1, 0, 0);
                updateZoomStatus(baseScale, cssScale);
                return page.render({
                    canvasContext: context,
                    viewport: viewport,
                    transform: outputScale !== 1 ? [outputScale, 0, 0, outputScale, 0, 0] : null
                }).promise;
            }).then(function() {
                rendering = false;
                restoreScrollPosition(oldScrollWidth, oldScrollHeight, oldScrollLeft, oldScrollTop);
                firstRender = false;
                hide(loading, true);
                canvas.classList.remove('is-rendering');
                updateButtons();
                prefetch(pageNumber + 1);
                prefetch(pageNumber - 1);
                if (pendingPage !== null) {
                    const nextPage = pendingPage;
                    pendingPage = null;
                    renderPage(nextPage);
                }
                return saveProgress(false);
            }).catch(function(error) {
                rendering = false;
                Notification.exception(error);
                showError(root, error);
                return null;
            });
        };

        const queue = function(num) {
            if (rendering) {
                pendingPage = num;
            } else {
                renderPage(num);
            }
        };

        const go = function(num) {
            if (!pdfDocument) {
                return;
            }
            pageNumber = Math.max(1, Math.min(pdfDocument.numPages, num));
            autoFit = true;
            zoomFactor = 1;
            queue(pageNumber);
        };

        const zoomTo = function(value) {
            if (!pdfDocument) {
                return;
            }
            zoomFactor = clamp(value, MIN_ZOOM, MAX_ZOOM);
            autoFit = zoomFactor === 1;
            queue(pageNumber);
        };

        const updateSearchStatus = function() {
            if (!searchStatus) {
                return;
            }
            if (!searchMatches.length) {
                searchStatus.textContent = searchInput && searchInput.value.trim() ? noMatchesText : '';
                return;
            }
            searchStatus.textContent = (searchIndex + 1) + ' / ' + searchMatches.length;
        };

        const goToSearchMatch = function(index) {
            if (!searchMatches.length) {
                return;
            }
            searchIndex = (index + searchMatches.length) % searchMatches.length;
            go(searchMatches[searchIndex]);
            updateSearchStatus();
        };

        const searchPageForQuery = function(page, query, token) {
            if (token !== searchToken || !pdfDocument) {
                return Promise.resolve(null);
            }

            return pdfDocument.getPage(page)
                .then(function(pdfPage) {
                    return pdfPage.getTextContent();
                })
                .then(function(content) {
                    if (token !== searchToken) {
                        return null;
                    }
                    const text = content.items.map(function(item) {
                        return item.str || '';
                    }).join(' ').toLocaleLowerCase();

                    return text.indexOf(query) !== -1 ? page : null;
                });
        };

        const searchPdf = function() {
            if (!pdfDocument || !searchInput) {
                return Promise.resolve(null);
            }

            const query = searchInput.value.trim().toLocaleLowerCase();
            searchMatches = [];
            searchIndex = -1;
            if (searchNext) {
                searchNext.disabled = true;
            }
            if (!query) {
                updateSearchStatus();
                return Promise.resolve([]);
            }

            const token = ++searchToken;
            const tasks = [];
            for (let page = 1; page <= pdfDocument.numPages; page++) {
                tasks.push(searchPageForQuery(page, query, token));
            }

            if (searchStatus) {
                searchStatus.textContent = searchingText;
            }

            return Promise.all(tasks).then(function(matches) {
                if (token !== searchToken) {
                    return null;
                }

                searchMatches = matches.filter(function(page) {
                    return page !== null;
                });
                searchMatches.sort(function(a, b) {
                    return a - b;
                });

                if (searchNext) {
                    searchNext.disabled = searchMatches.length === 0;
                }
                if (searchMatches.length) {
                    goToSearchMatch(0);
                } else {
                    updateSearchStatus();
                }

                return searchMatches;
            }).catch(function(error) {
                Notification.exception(error);
                return null;
            });
        };

        if (searchButton) {
            searchButton.addEventListener('click', searchPdf);
        }
        if (searchInput) {
            searchInput.addEventListener('keydown', function(event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    searchPdf();
                }
            });
        }
        if (searchNext) {
            searchNext.addEventListener('click', function() {
                goToSearchMatch(searchIndex + 1);
            });
        }

        if (previous) {
            previous.addEventListener('click', function() {
                go(pageNumber - 1);
            });
        }
        if (next) {
            next.addEventListener('click', function() {
                go(pageNumber + 1);
            });
        }
        if (zoomIn) {
            zoomIn.addEventListener('click', function() {
                zoomTo(zoomFactor + ZOOM_STEP);
            });
        }
        if (zoomOut) {
            zoomOut.addEventListener('click', function() {
                zoomTo(zoomFactor - ZOOM_STEP);
            });
        }
        if (fitScreen) {
            fitScreen.addEventListener('click', function() {
                zoomFactor = 1;
                autoFit = true;
                queue(pageNumber);
            });
        }
        if (wrap) {
            wrap.addEventListener('touchstart', function(event) {
                if (!event.touches || event.touches.length !== 1) {
                    return;
                }
                touchMoved = false;
                touchStartX = event.touches[0].clientX;
                touchStartY = event.touches[0].clientY;
            }, {passive: true});

            wrap.addEventListener('touchmove', function(event) {
                if (!event.touches || event.touches.length !== 1) {
                    return;
                }
                const dx = event.touches[0].clientX - touchStartX;
                const dy = event.touches[0].clientY - touchStartY;
                touchMoved = Math.abs(dx) > 18 || Math.abs(dy) > 18;
            }, {passive: true});

            wrap.addEventListener('touchend', function(event) {
                if (!touchMoved || !pdfDocument) {
                    return;
                }
                const changed = event.changedTouches && event.changedTouches[0];
                if (!changed) {
                    return;
                }
                const dx = changed.clientX - touchStartX;
                const dy = changed.clientY - touchStartY;
                if (Math.abs(dx) < 70 || Math.abs(dx) < Math.abs(dy) * 1.35) {
                    return;
                }
                if (dx < 0) {
                    go(pageNumber + 1);
                } else {
                    go(pageNumber - 1);
                }
            }, {passive: true});
        }
        if (fullscreen) {
            fullscreen.addEventListener('click', function() {
                if (document.fullscreenElement) {
                    document.exitFullscreen();
                    return;
                }
                if (root.requestFullscreen) {
                    const request = root.requestFullscreen();
                    if (request && typeof request.catch === 'function') {
                        request.catch(function() {
                            root.classList.add('is-fallback-fullscreen');
                            queue(pageNumber);
                        });
                    }
                } else {
                    root.classList.add('is-fallback-fullscreen');
                    queue(pageNumber);
                }
            });
            document.addEventListener('fullscreenchange', function() {
                if (!document.fullscreenElement) {
                    root.classList.remove('is-fallback-fullscreen');
                }
                if (pdfDocument) {
                    queue(pageNumber);
                }
            });
        }

        window.addEventListener('resize', function() {
            root.classList.toggle('is-mobile-viewer', isMobileViewport());
            if (pdfDocument) {
                autoFit = true;
                zoomFactor = 1;
                queue(pageNumber);
            }
        });
        window.addEventListener('pagehide', function() {
            saveProgress(true);
            if (saveTimer) {
                window.clearInterval(saveTimer);
                saveTimer = null;
            }
        });
        document.addEventListener('visibilitychange', function() {
            updateActiveTime();
            pageVisible = !document.hidden;
            if (!pageVisible) {
                saveProgress(true);
            }
        });
        saveTimer = window.setInterval(function() {
            if (pageVisible) {
                saveProgress(false);
            }
        }, SAVE_INTERVAL);

        hide(loading, false);
        pdfjsLib.getDocument({url: pdfUrl, withCredentials: true, rangeChunkSize: 262144}).promise.then(function(pdf) {
            pdfDocument = pdf;
            pageNumber = Math.min(pageNumber, pdfDocument.numPages);
            updateButtons();
            renderPage(pageNumber);
            return pdf;
        }).catch(function(error) {
            Notification.exception(error);
            showError(root, error);
            return null;
        });
    };

    const init = function() {
        const viewers = Array.prototype.slice.call(document.querySelectorAll('.mod-videoplayer-pdfjs-viewer'));
        if (!viewers.length) {
            return;
        }
        loadPdfJs().then(function(pdfjsLib) {
            viewers.forEach(function(root) {
                initViewer(root, pdfjsLib);
            });
            return pdfjsLib;
        }).catch(function(error) {
            Notification.exception(error);
            viewers.forEach(function(root) {
                showError(root, error);
            });
            return null;
        });
    };

    return {init: init};
});