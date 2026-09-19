// This file is part of Moodle - http://moodle.org/

/**
 * Protected PDF.js viewer for Drive Resource.
 *
 * Renders only the visible page, supports bounded high-DPI output, local text
 * search, responsive zoom/fullscreen and exact page-progress persistence.
 *
 * @module     mod_videoplayer/pdfviewer
 * @copyright  2026 Jose Erasmo Moreno Salgado - Elearning Cloud
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax', 'core/notification'], function(Ajax, Notification) {
    const PDFJS_URL = M.cfg.wwwroot + '/mod/videoplayer/thirdpartylibs/pdfjs/pdf.min.mjs';
    const PDFJS_WORKER_URL = M.cfg.wwwroot + '/mod/videoplayer/thirdpartylibs/pdfjs/pdf.worker.min.mjs';
    const SAVE_INTERVAL = 10000;
    const HEARTBEAT_INTERVAL = 30000;
    const MIN_ZOOM = 0.75;
    const MAX_ZOOM = 2.75;
    const ZOOM_STEP = 0.15;
    const MOBILE_BREAKPOINT = 768;
    const TEXT_CACHE_LIMIT = 30;
    const MAX_VISITED_PAGES = 20000;
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

    const normalizeSearchText = function(value) {
        const text = String(value || '').toLowerCase();
        return typeof text.normalize === 'function'
            ? text.normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            : text;
    };

    const parseVisitedPages = function(value) {
        let parsed;
        try {
            parsed = JSON.parse(value || '[]');
        } catch (error) {
            parsed = [];
        }
        if (!Array.isArray(parsed)) {
            return [];
        }

        const unique = new Set();
        parsed.slice(0, MAX_VISITED_PAGES).forEach(function(page) {
            const num = parseInt(page, 10);
            if (num > 0) {
                unique.add(num);
            }
        });
        return Array.from(unique).sort(function(a, b) {
            return a - b;
        });
    };

    const showError = function(root, error) {
        hide(root.querySelector('.mod-videoplayer-pdfjs-canvas-wrap'), true);
        hide(root.querySelector('.mod-videoplayer-pdfjs-searchbar'), true);
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
            if ((event.ctrlKey || event.metaKey) && ['s', 'p'].indexOf(key) !== -1) {
                block(event);
            }
        }, true);
    };

    const initViewer = function(root, pdfjsLib) {
        const pdfUrl = root.getAttribute('data-pdf-url');
        const cmid = parseInt(root.getAttribute('data-cmid'), 10) || 0;
        const canvas = root.querySelector('.mod-videoplayer-pdfjs-canvas');
        const wrap = root.querySelector('.mod-videoplayer-pdfjs-canvas-wrap');
        const loading = root.querySelector('[data-region="pdfjs-loading"]');
        const previous = root.querySelector('[data-action="previous-page"]');
        const next = root.querySelector('[data-action="next-page"]');
        const fullscreen = root.querySelector('[data-action="fullscreen"]');
        const zoomIn = root.querySelector('[data-action="zoom-in"]');
        const zoomOut = root.querySelector('[data-action="zoom-out"]');
        const fitScreen = root.querySelector('[data-action="fit-screen"]');
        const currentPageNode = root.querySelector('[data-region="current-page"]');
        const totalPagesNode = root.querySelector('[data-region="total-pages"]');
        const zoomLevelNode = root.querySelector('[data-region="zoom-level"]');
        const searchForm = root.querySelector('[data-region="pdf-search-form"]');
        const searchInput = root.querySelector('[data-region="pdf-search-input"]');
        const searchButton = root.querySelector('[data-action="search-pdf"]');
        const searchStatus = root.querySelector('[data-region="pdf-search-status"]');
        const container = root.closest('.mod-videoplayer-container') || document;
        const progressNode = container.querySelector('[data-region="pdfjs-progress"]');

        if (!pdfUrl || !canvas || !wrap) {
            showError(root);
            return;
        }

        hardenViewer(root, canvas);
        root.classList.toggle('is-mobile-viewer', isMobileViewport());

        const context = canvas.getContext('2d', {alpha: false});
        const visitedPages = new Set(parseVisitedPages(root.getAttribute('data-visited-pages')));
        const pageTextCache = new Map();
        let pdfDocument = null;
        let pageNumber = Math.max(1, parseInt(root.getAttribute('data-initial-page'), 10) || 1);
        let rendering = false;
        let pendingPage = null;
        let renderVersion = 0;
        let firstRender = true;
        let lastSave = 0;
        let activeSeconds = Math.max(0, parseFloat(root.getAttribute('data-initial-progress')) || 0);
        let timeSpent = Math.max(0, parseInt(root.getAttribute('data-initial-time-spent'), 10) || 0);
        let completed = root.getAttribute('data-initial-completed') === '1';
        let lastTick = Date.now();
        let zoomFactor = 1;
        let autoFit = true;
        let touchStartX = 0;
        let touchStartY = 0;
        let touchMoved = false;
        let lastSearchQuery = '';
        let lastSearchPage = 0;
        let searchBusy = false;
        let saveInFlight = null;
        let saveQueued = false;

        const isFullscreen = function() {
            return document.fullscreenElement === root || root.classList.contains('is-fallback-fullscreen');
        };

        const completionPercent = function() {
            if (!pdfDocument || !pdfDocument.numPages) {
                return 0;
            }
            return Math.min(100, Math.round((visitedPages.size / pdfDocument.numPages) * 10000) / 100);
        };

        const serializeVisitedPages = function() {
            return JSON.stringify(Array.from(visitedPages).sort(function(a, b) {
                return a - b;
            }).slice(0, MAX_VISITED_PAGES));
        };

        const absorbServerPages = function(value) {
            parseVisitedPages(value).forEach(function(page) {
                if (!pdfDocument || page <= pdfDocument.numPages) {
                    visitedPages.add(page);
                }
            });
        };

        const updateZoomStatus = function(baseScale, appliedScale) {
            if (zoomLevelNode && baseScale) {
                zoomLevelNode.textContent = Math.round((appliedScale / baseScale) * 100) + '%';
            }
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

        const accrueActiveTime = function() {
            const now = Date.now();
            const delta = Math.max(0, Math.round((now - lastTick) / 1000));
            activeSeconds += delta;
            timeSpent += delta;
            lastTick = now;
        };

        const saveProgress = function(force) {
            if (!cmid || !pdfDocument) {
                return Promise.resolve();
            }

            const now = Date.now();
            if (!force && now - lastSave < SAVE_INTERVAL) {
                return Promise.resolve();
            }
            accrueActiveTime();
            lastSave = now;

            if (saveInFlight) {
                saveQueued = saveQueued || force;
                return saveInFlight;
            }

            const percent = completionPercent();
            const request = {
                methodname: 'mod_videoplayer_save_progress',
                args: {
                    cmid: cmid,
                    progress: activeSeconds,
                    completed: completed || percent >= 100,
                    completionpercentage: percent,
                    lastpage: pageNumber,
                    totalpages: pdfDocument.numPages,
                    visitedpages: serializeVisitedPages(),
                    lastsecond: 0,
                    totalseconds: 0,
                    watchedranges: '',
                    timespent: timeSpent
                }
            };

            saveInFlight = Ajax.call([request])[0].then(function(response) {
                if (response) {
                    completed = Boolean(response.completed);
                    activeSeconds = Math.max(activeSeconds, parseFloat(response.progress) || 0);
                    timeSpent = Math.max(timeSpent, parseInt(response.timespent, 10) || 0);
                    absorbServerPages(response.visitedpages || '');
                    if (progressNode) {
                        progressNode.textContent = response.completionpercentage + '%';
                    }
                }
                return response;
            }).catch(Notification.exception).finally(function() {
                saveInFlight = null;
                if (saveQueued) {
                    saveQueued = false;
                    window.setTimeout(function() {
                        saveProgress(true);
                    }, 0);
                }
            });

            return saveInFlight;
        };

        const prefetch = function(num) {
            if (pdfDocument && num >= 1 && num <= pdfDocument.numPages) {
                pdfDocument.getPage(num).catch(function() {
                    // Neighbor prefetch is best effort.
                });
            }
        };

        const restoreScrollPosition = function(oldWidth, oldHeight, oldScrollLeft, oldScrollTop) {
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
            if (!pdfDocument) {
                return;
            }

            const currentVersion = ++renderVersion;
            const oldScrollWidth = wrap.scrollWidth;
            const oldScrollHeight = wrap.scrollHeight;
            const oldScrollLeft = wrap.scrollLeft;
            const oldScrollTop = wrap.scrollTop;
            rendering = true;
            canvas.classList.add('is-rendering');
            if (firstRender) {
                hide(loading, false);
            }

            pdfDocument.getPage(num).then(function(page) {
                if (currentVersion !== renderVersion) {
                    return null;
                }

                const horizontalPadding = isMobileViewport() ? 10 : 24;
                const verticalPadding = isMobileViewport() ? 10 : 24;
                const availableWidth = Math.max(wrap.clientWidth - horizontalPadding, 280);
                const availableHeight = Math.max(wrap.clientHeight - verticalPadding, 320);
                const base = page.getViewport({scale: 1});
                const fitWidth = availableWidth / base.width;
                const fitHeight = availableHeight / base.height;
                const baseScale = isFullscreen() ? Math.min(fitWidth, fitHeight) : fitWidth;
                const appliedZoom = autoFit ? 1 : zoomFactor;
                const cssScale = clamp(baseScale * appliedZoom, baseScale * MIN_ZOOM, baseScale * MAX_ZOOM);
                const viewport = page.getViewport({scale: cssScale});
                const outputScale = isFullscreen()
                    ? Math.min(window.devicePixelRatio || 1, 3)
                    : Math.min(window.devicePixelRatio || 1, 2.25);

                canvas.width = Math.max(1, Math.floor(viewport.width * outputScale));
                canvas.height = Math.max(1, Math.floor(viewport.height * outputScale));
                canvas.style.width = Math.floor(viewport.width) + 'px';
                canvas.style.height = Math.floor(viewport.height) + 'px';
                context.setTransform(1, 0, 0, 1, 0, 0);
                updateZoomStatus(baseScale, cssScale);

                return page.render({
                    canvasContext: context,
                    viewport: viewport,
                    transform: outputScale !== 1 ? [outputScale, 0, 0, outputScale, 0, 0] : null
                }).promise;
            }).then(function(result) {
                if (result === null || currentVersion !== renderVersion) {
                    return null;
                }
                rendering = false;
                visitedPages.add(num);
                restoreScrollPosition(oldScrollWidth, oldScrollHeight, oldScrollLeft, oldScrollTop);
                firstRender = false;
                hide(loading, true);
                canvas.classList.remove('is-rendering');
                updateButtons();
                prefetch(pageNumber + 1);
                prefetch(pageNumber - 1);
                saveProgress(false);

                if (pendingPage !== null) {
                    const queued = pendingPage;
                    pendingPage = null;
                    pageNumber = queued;
                    renderPage(queued);
                }
                return null;
            }).catch(function(error) {
                if (currentVersion !== renderVersion) {
                    return;
                }
                rendering = false;
                canvas.classList.remove('is-rendering');
                Notification.exception(error);
                showError(root, error);
            });
        };

        const queue = function(num) {
            if (!pdfDocument) {
                return;
            }
            const safe = Math.max(1, Math.min(pdfDocument.numPages, num));
            pageNumber = safe;
            if (rendering) {
                pendingPage = safe;
                renderVersion++;
                rendering = false;
            }
            renderPage(safe);
        };

        const go = function(num, resetZoom) {
            if (resetZoom) {
                autoFit = true;
                zoomFactor = 1;
            }
            queue(num);
        };

        const zoomTo = function(value) {
            zoomFactor = clamp(value, MIN_ZOOM, MAX_ZOOM);
            autoFit = zoomFactor === 1;
            queue(pageNumber);
        };

        const cachePageText = function(page, text) {
            if (pageTextCache.has(page)) {
                pageTextCache.delete(page);
            }
            pageTextCache.set(page, text);
            while (pageTextCache.size > TEXT_CACHE_LIMIT) {
                pageTextCache.delete(pageTextCache.keys().next().value);
            }
        };

        const getPageText = function(num) {
            if (pageTextCache.has(num)) {
                const cached = pageTextCache.get(num);
                pageTextCache.delete(num);
                pageTextCache.set(num, cached);
                return Promise.resolve(cached);
            }

            return pdfDocument.getPage(num).then(function(page) {
                return page.getTextContent();
            }).then(function(content) {
                const text = normalizeSearchText(content.items.map(function(item) {
                    return item.str || '';
                }).join(' '));
                cachePageText(num, text);
                return text;
            });
        };

        const buildSearchOrder = function(start) {
            const order = [];
            for (let num = start; num <= pdfDocument.numPages; num++) {
                order.push(num);
            }
            for (let num = 1; num < start; num++) {
                order.push(num);
            }
            return order;
        };

        const findInPages = function(order, index, query) {
            if (index >= order.length) {
                return Promise.resolve(0);
            }
            const num = order[index];
            return getPageText(num).then(function(text) {
                return text.indexOf(query) !== -1 ? num : findInPages(order, index + 1, query);
            });
        };

        const searchDocument = function() {
            if (!pdfDocument || !searchInput || searchBusy) {
                return;
            }

            const query = normalizeSearchText(searchInput.value.trim());
            if (!query) {
                if (searchStatus) {
                    searchStatus.textContent = '';
                }
                return;
            }

            const repeated = query === lastSearchQuery && lastSearchPage > 0;
            let start = repeated ? lastSearchPage + 1 : pageNumber;
            if (start > pdfDocument.numPages) {
                start = 1;
            }

            searchBusy = true;
            if (searchButton) {
                searchButton.disabled = true;
            }
            root.classList.add('is-searching');

            findInPages(buildSearchOrder(start), 0, query).then(function(foundPage) {
                lastSearchQuery = query;
                lastSearchPage = foundPage;
                if (foundPage > 0) {
                    if (searchStatus) {
                        searchStatus.textContent =
                            (root.getAttribute('data-search-result-page') || 'Result on page') + ' ' + foundPage;
                    }
                    go(foundPage, true);
                } else if (searchStatus) {
                    searchStatus.textContent = root.getAttribute('data-search-not-found') || 'No results found';
                }
                return null;
            }).catch(Notification.exception).finally(function() {
                searchBusy = false;
                if (searchButton) {
                    searchButton.disabled = false;
                }
                root.classList.remove('is-searching');
            });
        };

        if (previous) {
            previous.addEventListener('click', function() {
                go(pageNumber - 1, true);
            });
        }
        if (next) {
            next.addEventListener('click', function() {
                go(pageNumber + 1, true);
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
        if (searchForm) {
            searchForm.addEventListener('submit', function(event) {
                event.preventDefault();
                searchDocument();
            });
        }

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
            if (Math.abs(dx) >= 70 && Math.abs(dx) >= Math.abs(dy) * 1.35) {
                go(pageNumber + (dx < 0 ? 1 : -1), true);
            }
        }, {passive: true});

        if (fullscreen) {
            fullscreen.addEventListener('click', function() {
                if (document.fullscreenElement) {
                    document.exitFullscreen();
                    return;
                }
                if (root.classList.contains('is-fallback-fullscreen')) {
                    root.classList.remove('is-fallback-fullscreen');
                    document.documentElement.classList.remove('mod-videoplayer-no-scroll');
                    document.body.classList.remove('mod-videoplayer-no-scroll');
                    queue(pageNumber);
                    return;
                }
                if (root.requestFullscreen) {
                    root.requestFullscreen().catch(function() {
                        root.classList.add('is-fallback-fullscreen');
                        document.documentElement.classList.add('mod-videoplayer-no-scroll');
                        document.body.classList.add('mod-videoplayer-no-scroll');
                        queue(pageNumber);
                    });
                } else {
                    root.classList.add('is-fallback-fullscreen');
                    document.documentElement.classList.add('mod-videoplayer-no-scroll');
                    document.body.classList.add('mod-videoplayer-no-scroll');
                    queue(pageNumber);
                }
            });

            document.addEventListener('fullscreenchange', function() {
                if (!document.fullscreenElement) {
                    root.classList.remove('is-fallback-fullscreen');
                    document.documentElement.classList.remove('mod-videoplayer-no-scroll');
                    document.body.classList.remove('mod-videoplayer-no-scroll');
                }
                if (pdfDocument) {
                    queue(pageNumber);
                }
            });
        }

        let resizeTimer = null;
        window.addEventListener('resize', function() {
            root.classList.toggle('is-mobile-viewer', isMobileViewport());
            if (!pdfDocument) {
                return;
            }
            window.clearTimeout(resizeTimer);
            resizeTimer = window.setTimeout(function() {
                autoFit = true;
                zoomFactor = 1;
                queue(pageNumber);
            }, 180);
        });

        window.addEventListener('pagehide', function() {
            saveProgress(true);
        });
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                saveProgress(true);
            } else {
                lastTick = Date.now();
            }
        });
        window.setInterval(function() {
            if (!document.hidden) {
                saveProgress(false);
            }
        }, HEARTBEAT_INTERVAL);

        hide(loading, false);
        pdfjsLib.getDocument({
            url: pdfUrl,
            withCredentials: true,
            rangeChunkSize: 262144,
            disableAutoFetch: false,
            disableStream: false
        }).promise.then(function(pdf) {
            pdfDocument = pdf;
            Array.from(visitedPages).forEach(function(page) {
                if (page > pdfDocument.numPages) {
                    visitedPages.delete(page);
                }
            });
            pageNumber = Math.max(1, Math.min(pageNumber, pdfDocument.numPages));
            updateButtons();
            renderPage(pageNumber);
            return null;
        }).catch(function(error) {
            Notification.exception(error);
            showError(root, error);
        });
    };

    const init = function() {
        const viewers = Array.prototype.slice.call(document.querySelectorAll('.mod-videoplayer-pdfjs-viewer'));
        if (!viewers.length) {
            return;
        }

        loadPdfJs().then(function(pdfjsLib) {
            viewers.forEach(function(root) {
                if (root.dataset.pdfViewerReady === '1') {
                    return;
                }
                root.dataset.pdfViewerReady = '1';
                initViewer(root, pdfjsLib);
            });
            return null;
        }).catch(function(error) {
            Notification.exception(error);
            viewers.forEach(function(root) {
                showError(root, error);
            });
        });
    };

    return {init: init};
});
