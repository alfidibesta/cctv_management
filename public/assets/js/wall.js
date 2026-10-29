/* Argus — live wall: grid, paging, camera assignment, snapshot refresh, PTZ. */
(() => {
    'use strict';

    const root = document.querySelector('[data-wall]');
    const dataNode = document.getElementById('wall-data');
    if (!root || !dataNode) return;

    const config = JSON.parse(dataNode.textContent);
    const grid = root.querySelector('[data-wall-grid]');
    const pager = root.querySelector('[data-pager]');
    const pageLabel = root.querySelector('[data-page-label]');
    const onlineCount = root.querySelector('[data-online-count]');
    const drawer = document.querySelector('[data-drawer]');
    const scrim = document.querySelector('[data-scrim]');
    const modal = document.querySelector('[data-camera-modal]');
    const Icon = window.Argus || {};

    const baseUrl = document.querySelector('meta[name="base-url"]')?.content || '/';
    const cameras = Array.isArray(config.cameras) ? config.cameras : [];
    const cameraById = new Map(cameras.map((camera) => [Number(camera.id), camera]));

    const state = {
        slots: Number(config.layout.slots) || 9,
        mode: config.layout.mode === 'custom' ? 'custom' : 'auto',
        tiles: [],
        page: 0,
        selected: 0,
        refresh: Math.max(2, Number(config.refresh) || 5),
        statusEvery: Math.max(10, Number(config.status) || 20),
        canManage: Boolean(config.canManage),
        modalCamera: null,
        paused: false,
    };

    const icons = {
        plus: '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>',
        expand: '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M9 4H4v5M15 4h5v5M15 20h5v-5M9 20H4v-5"/></svg>',
        camera: '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 8.5A1.5 1.5 0 0 1 4.5 7h9A1.5 1.5 0 0 1 15 8.5v7A1.5 1.5 0 0 1 13.5 17h-9A1.5 1.5 0 0 1 3 15.5z"/><path d="m15 11 4.5-2.5v7L15 13"/><circle cx="9" cy="12" r="2.4"/></svg>',
        download: '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4v11"/><path d="m8 11 4 4 4-4"/><path d="M4 19h16"/></svg>',
        ptz: '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v4M12 17v4M3 12h4M17 12h4"/><circle cx="12" cy="12" r="3.2"/></svg>',
        close: '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg>',
        up: '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5M6 11l6-6 6 6"/></svg>',
        down: '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M6 13l6 6 6-6"/></svg>',
        left: '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M11 6l-6 6 6 6"/></svg>',
        right: '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>',
        zoomIn: '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><circle cx="11" cy="11" r="6.2"/><path d="m20 20-4.4-4.4M8.5 11h5M11 8.5v5"/></svg>',
        zoomOut: '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><circle cx="11" cy="11" r="6.2"/><path d="m20 20-4.4-4.4M8.5 11h5"/></svg>',
        home: '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="m4 11 8-6.5 8 6.5"/><path d="M6.5 9.8V20h11V9.8"/></svg>',
        stop: '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="6.5" y="6.5" width="11" height="11" rx="2"/></svg>',
        check: '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12.5 4.6 4.5L19 6.5"/></svg>',
    };

    const esc = (value) =>
        String(value ?? '').replace(/[&<>"']/g, (character) => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;',
        }[character]));

    const modeLabel = (mode) => ({ mjpeg: 'mjpeg', snapshot: 'snap', hls: 'hls' }[mode] || mode);

    /* ------------------------------------------------------------- tiles */
    function normalizeTiles(tiles, slots) {
        const result = [];
        for (let i = 0; i < slots; i += 1) {
            const value = Number(tiles[i]);
            result.push(Number.isFinite(value) && cameraById.has(value) ? value : null);
        }
        return result;
    }

    function autoPageCount() {
        return Math.max(1, Math.ceil(cameras.length / state.slots));
    }

    function autoTiles() {
        const start = state.page * state.slots;
        const slice = cameras.slice(start, start + state.slots).map((camera) => Number(camera.id));
        return normalizeTiles(slice, state.slots);
    }

    function visibleTiles() {
        return state.mode === 'auto' ? autoTiles() : state.tiles;
    }

    function render() {
        const tiles = visibleTiles();
        grid.style.setProperty('--grid-cols', String(state.slots === 1 ? 1 : state.slots === 4 ? 2 : 3));
        grid.innerHTML = '';

        tiles.forEach((cameraId, index) => {
            grid.appendChild(cameraId ? cameraTile(cameraId, index) : emptyTile(index));
        });

        grid.classList.toggle('is-empty', cameras.length === 0);
        const empty = root.querySelector('[data-wall-empty]');
        if (empty) empty.hidden = cameras.length > 0;

        pager.hidden = !(state.mode === 'auto' && autoPageCount() > 1);
        pageLabel.textContent = `${state.page + 1} / ${autoPageCount()}`;

        syncSelection();
        attachTimers();
        updateOnlineCount();
    }

    function emptyTile(index) {
        const tile = document.createElement('button');
        tile.type = 'button';
        tile.className = 'tile tile--empty';
        tile.dataset.slot = String(index);
        tile.innerHTML = `<span class="tile__empty-inner">${icons.plus}<span>Add camera</span><small>Slot ${index + 1}</small></span>`;
        tile.addEventListener('click', () => {
            state.selected = index;
            syncSelection();
            openDrawer();
        });
        tile.addEventListener('dragover', onDragOver);
        tile.addEventListener('dragleave', () => tile.classList.remove('is-dropping'));
        tile.addEventListener('drop', (event) => {
            event.preventDefault();
            tile.classList.remove('is-dropping');
            const id = Number(event.dataTransfer.getData('text/camera-id'));
            if (id) assign(id, index);
        });
        return tile;
    }

    function cameraTile(cameraId, index) {
        const camera = cameraById.get(cameraId);
        const tile = document.createElement('article');
        tile.className = 'tile';
        tile.dataset.slot = String(index);
        tile.dataset.cameraId = String(cameraId);

        const media =
            camera.mode === 'mjpeg'
                ? `<img src="${esc(camera.mjpeg_url)}" alt="${esc(camera.name)}" data-mjpeg>`
                : camera.mode === 'hls'
                ? `<video data-hls="${cameraId}" muted playsinline autoplay></video>`
                : `<img src="${esc(camera.snapshot_url)}" alt="${esc(camera.name)}" data-snapshot="${esc(camera.snapshot_url)}">`;

        tile.innerHTML = `
            <div class="tile__media">${media}</div>
            <div class="tile__shade"></div>
            <div class="tile__top">
                <span class="tile__status">
                    <span class="dot dot--${esc(camera.status)}" data-wall-dot="${cameraId}"></span>
                    <span data-wall-latency>${esc(camera.status)}</span>
                </span>
                <div class="tile__actions">
                    ${camera.ptz ? `<button type="button" class="tile-btn" data-action="ptz" title="Open & PTZ">${icons.ptz}</button>` : `<button type="button" class="tile-btn" data-action="open" title="Open camera">${icons.expand}</button>`}
                    <button type="button" class="tile-btn" data-action="snapshot" title="Save snapshot">${icons.download}</button>
                    <button type="button" class="tile-btn" data-action="fullscreen" title="Fullscreen">${icons.expand}</button>
                    <button type="button" class="tile-btn" data-action="clear" title="Remove from wall">${icons.close}</button>
                </div>
            </div>
            <div class="tile__meta">
                <div>
                    <h3>${esc(camera.name)}</h3>
                    <p>${esc(camera.location || camera.group)}</p>
                </div>
                <span class="tile__badge">${esc(modeLabel(camera.mode))}</span>
            </div>
            <div class="tile__offline" hidden>no signal</div>`;

        if (!camera.enabled) {
            tile.querySelector('[data-wall-latency]').textContent = 'disabled';
        }

        const img = tile.querySelector('[data-snapshot], [data-mjpeg]');
        if (img) {
            img.addEventListener('error', () => {
                const overlay = tile.querySelector('.tile__offline');
                if (overlay) overlay.hidden = false;
            });
            img.addEventListener('load', () => {
                const overlay = tile.querySelector('.tile__offline');
                if (overlay) overlay.hidden = true;
            });
        }

        if (camera.mode === 'hls') {
            startHls(tile, camera);
        }

        tile.addEventListener('click', (event) => {
            if (event.target.closest('[data-action]')) return;
            state.selected = index;
            syncSelection();
        });

        tile.addEventListener('dblclick', () => openCameraModal(camera.id));

        tile.addEventListener('click', (event) => {
            const action = event.target.closest('[data-action]')?.dataset.action;
            if (!action) return;
            event.stopPropagation();
            state.selected = index;
            syncSelection();

            if (action === 'fullscreen') {
                tile.requestFullscreen?.().catch(() => {});
            } else if (action === 'snapshot') {
                window.location.href = camera.download_url;
            } else if (action === 'ptz' || action === 'open') {
                openCameraModal(camera.id);
            } else if (action === 'clear') {
                clearSlot(index);
            }
        });

        tile.addEventListener('dragover', onDragOver);
        tile.addEventListener('dragleave', () => tile.classList.remove('is-dropping'));
        tile.addEventListener('drop', (event) => {
            event.preventDefault();
            tile.classList.remove('is-dropping');
            const id = Number(event.dataTransfer.getData('text/camera-id'));
            if (id) assign(id, index);
        });

        return tile;
    }

    function onDragOver(event) {
        event.preventDefault();
        event.dataTransfer.dropEffect = 'copy';
        event.currentTarget.classList.add('is-dropping');
    }

    function syncSelection() {
        grid.querySelectorAll('.tile').forEach((tile) => {
            tile.classList.toggle('is-selected', Number(tile.dataset.slot) === state.selected);
        });
    }

    /* --------------------------------------------------------- assignment */
    function ensureCustomMode() {
        if (state.mode === 'custom') return;
        state.tiles = autoTiles();
        state.mode = 'custom';
        updateModeButtons();
        window.Argus?.toast?.('Switched to custom layout — drag remains saved.', 'success');
    }

    function assign(cameraId, slot = null) {
        ensureCustomMode();
        state.tiles = normalizeTiles(state.tiles, state.slots);

        let target = slot;
        if (target === null) {
            target = state.tiles[state.selected] === null ? state.selected : state.tiles.indexOf(null);
        }
        if (target === null || target < 0) {
            target = state.selected % state.slots;
        }

        // The same camera cannot appear twice on one page.
        const existing = state.tiles.indexOf(cameraId);
        if (existing !== -1) {
            state.tiles[existing] = state.tiles[target];
        }

        state.tiles[target] = cameraId;
        state.selected = Math.min(target + 1, state.slots - 1);
        render();
        saveLayout();
        markDrawerUsage();
        closeDrawer();
    }

    function clearSlot(index) {
        if (state.mode === 'auto') {
            window.Argus?.toast?.('Switch to Custom to edit individual slots.', 'error');
            return;
        }
        state.tiles[index] = null;
        render();
        saveLayout();
    }

    function saveLayout() {
        window.clearTimeout(saveLayout.timer);
        saveLayout.timer = window.setTimeout(async () => {
            try {
                await window.Argus.post(`${baseUrl}api/layout`, {
                    columns: state.slots,
                    mode: state.mode,
                    tiles: state.tiles,
                });
            } catch (error) {
                window.Argus?.toast?.(error.message || 'Could not save the layout.', 'error');
            }
        }, 550);
    }

    /* ---------------------------------------------------------- wall mode */
    function updateModeButtons() {
        root.querySelectorAll('[data-mode]').forEach((button) => {
            button.classList.toggle('is-active', button.dataset.mode === state.mode);
        });
        root.querySelectorAll('[data-grid]').forEach((button) => {
            button.classList.toggle('is-active', Number(button.dataset.grid) === state.slots);
        });
    }

    root.querySelectorAll('[data-mode]').forEach((button) => {
        button.addEventListener('click', () => {
            const mode = button.dataset.mode;
            if (mode === state.mode) return;

            if (mode === 'custom' && state.mode === 'auto') {
                state.tiles = autoTiles();
            }
            state.mode = mode;
            if (mode === 'auto') state.page = 0;
            updateModeButtons();
            render();
            saveLayout();
        });
    });

    root.querySelectorAll('[data-grid]').forEach((button) => {
        button.addEventListener('click', () => {
            const previous = state.slots;
            state.slots = Number(button.dataset.grid) || 9;
            const base = state.tiles.length ? state.tiles : autoTiles();
            state.tiles = normalizeTiles(base, previous);
            while (state.tiles.length < state.slots) state.tiles.push(null);
            state.tiles = normalizeTiles(state.tiles, state.slots);
            state.selected = 0;
            state.page = 0;
            updateModeButtons();
            render();
            saveLayout();
        });
    });

    pager.querySelector('[data-page="prev"]')?.addEventListener('click', () => {
        state.page = (state.page - 1 + autoPageCount()) % autoPageCount();
        render();
    });

    pager.querySelector('[data-page="next"]')?.addEventListener('click', () => {
        state.page = (state.page + 1) % autoPageCount();
        render();
    });

    root.querySelector('[data-wall-fullscreen]')?.addEventListener('click', () => {
        if (document.fullscreenElement) {
            document.exitFullscreen();
        } else {
            root.requestFullscreen?.().catch(() => {});
        }
    });

    /* ------------------------------------------------------------- drawer */
    function openDrawer() {
        drawer.classList.add('is-open');
        drawer.setAttribute('aria-hidden', 'false');
        scrim.hidden = false;
        const hint = drawer.querySelector('[data-drawer-hint]');
        if (hint) hint.textContent = `Slot ${state.selected + 1} — pick a camera`;
        markDrawerUsage();
    }

    function closeDrawer() {
        drawer.classList.remove('is-open');
        drawer.setAttribute('aria-hidden', 'true');
        scrim.hidden = true;
    }

    root.querySelector('[data-drawer-open]')?.addEventListener('click', openDrawer);
    document.querySelectorAll('[data-drawer-close]').forEach((button) => button.addEventListener('click', closeDrawer));
    scrim?.addEventListener('click', closeDrawer);

    function markDrawerUsage() {
        const used = new Set(visibleTiles().filter(Boolean));
        drawer.querySelectorAll('[data-camera-id]').forEach((row) => {
            row.classList.toggle('is-used', used.has(Number(row.dataset.cameraId)));
        });
    }

    const search = drawer.querySelector('[data-camera-search]');
    let activeGroup = 'all';

    function filterCameras() {
        const query = (search?.value || '').trim().toLowerCase();
        let visible = 0;

        drawer.querySelectorAll('[data-camera-id]').forEach((row) => {
            const matchesGroup = activeGroup === 'all' || row.dataset.groupId === activeGroup;
            const matchesText = query === '' || (row.dataset.search || '').includes(query);
            const show = matchesGroup && matchesText;
            row.hidden = !show;
            if (show) visible += 1;
        });

        const empty = drawer.querySelector('[data-list-empty]');
        if (empty) empty.hidden = visible > 0;
    }

    search?.addEventListener('input', filterCameras);

    drawer.querySelectorAll('[data-group]').forEach((chip) => {
        chip.addEventListener('click', () => {
            activeGroup = chip.dataset.group;
            drawer.querySelectorAll('[data-group]').forEach((other) => {
                other.classList.toggle('is-active', other === chip);
            });
            filterCameras();
        });
    });

    drawer.querySelectorAll('[data-camera-id]').forEach((row) => {
        row.addEventListener('click', () => {
            const id = Number(row.dataset.cameraId);
            if (id) assign(id);
        });

        row.addEventListener('dragstart', (event) => {
            event.dataTransfer.setData('text/camera-id', row.dataset.cameraId);
            event.dataTransfer.effectAllowed = 'copy';
        });
    });

    markDrawerUsage();

    /* -------------------------------------------------------------- status */
    async function pollStatus() {
        if (document.hidden) return;
        const ids = visibleTiles().filter(Boolean);
        if (!ids.length) return;

        try {
            const response = await window.Argus.get(`${baseUrl}api/status?ids=${ids.join(',')}`);
            (response.cameras || []).forEach((entry) => {
                const camera = cameraById.get(Number(entry.id));
                if (camera) {
                    camera.status = entry.status;
                    camera.last_error = entry.error;
                }

                document.querySelectorAll(`[data-wall-dot="${entry.id}"]`).forEach((dot) => {
                    dot.classList.remove('dot--online', 'dot--offline', 'dot--unknown');
                    dot.classList.add(`dot--${entry.status}`);
                });

                document.querySelectorAll(`[data-status-dot="${entry.id}"]`).forEach((dot) => {
                    dot.classList.remove('dot--online', 'dot--offline', 'dot--unknown');
                    dot.classList.add(`dot--${entry.status}`);
                });

                const tile = grid.querySelector(`[data-camera-id="${entry.id}"]`);
                const latency = tile?.querySelector('[data-wall-latency]');
                if (latency) {
                    latency.textContent = entry.status === 'online'
                        ? (entry.latency_ms ? `${entry.latency_ms} ms` : 'online')
                        : entry.status;
                }

                if (tile) {
                    const overlay = tile.querySelector('.tile__offline');
                    if (overlay) overlay.hidden = entry.status !== 'offline';
                }
            });

            updateOnlineCount();
        } catch (error) {
            /* polling failures are non-fatal */
        }
    }

    function updateOnlineCount() {
        const ids = new Set(visibleTiles().filter(Boolean));
        const online = Array.from(ids).filter((id) => cameraById.get(id)?.status === 'online').length;
        if (onlineCount) onlineCount.textContent = String(online);
    }

    /* ------------------------------------------------------------ snapshots */
    let snapshotTimer = null;

    function refreshSnapshots() {
        if (document.hidden || state.paused) return;
        grid.querySelectorAll('img[data-snapshot]').forEach((img) => {
            const base = img.dataset.snapshot;
            img.src = `${base}${base.includes('?') ? '&' : '?'}t=${Date.now()}`;
        });
    }

    function attachTimers() {
        window.clearInterval(snapshotTimer);
        snapshotTimer = window.setInterval(refreshSnapshots, state.refresh * 1000);
    }

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) refreshSnapshots();
    });

    window.setInterval(pollStatus, state.statusEvery * 1000);
    window.setTimeout(pollStatus, 2500);

    /* ---------------------------------------------------------------- HLS */
    let hlsLibraryPromise = null;

    function loadHlsLibrary() {
        if (window.Hls) return Promise.resolve(window.Hls);
        if (hlsLibraryPromise) return hlsLibraryPromise;

        hlsLibraryPromise = new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = `${baseUrl}assets/vendor/hls.min.js`;
            script.onload = () => resolve(window.Hls);
            script.onerror = () => reject(new Error('HLS player library is unavailable.'));
            document.head.appendChild(script);
        });

        return hlsLibraryPromise;
    }

    async function startHls(tile, camera) {
        const video = tile.querySelector('video[data-hls]');
        if (!video) return;

        try {
            const response = await window.Argus.post(`${baseUrl}api/hls/${camera.id}`, {});
            if (video.canPlayType('application/vnd.apple.mpegurl')) {
                video.src = response.playlist;
            } else {
                const Hls = await loadHlsLibrary();
                const player = new Hls({ liveSyncDurationCount: 3 });
                player.loadSource(response.playlist);
                player.attachMedia(video);
            }
            video.play().catch(() => {});
        } catch (error) {
            const overlay = tile.querySelector('.tile__offline');
            if (overlay) {
                overlay.textContent = 'stream unavailable';
                overlay.hidden = false;
            }
            console.warn('HLS error', error);
        }
    }

    /* -------------------------------------------------------- camera modal */
    function openCameraModal(cameraId) {
        const camera = cameraById.get(Number(cameraId));
        if (!camera) return;

        state.modalCamera = camera;
        modal.querySelector('[data-modal-title]').textContent = camera.name;
        modal.querySelector('[data-modal-subtitle]').textContent = [camera.location, camera.group, camera.driver_label]
            .filter(Boolean)
            .join(' · ');

        const status = modal.querySelector('[data-modal-status]');
        status.innerHTML = `<span class="dot dot--${esc(camera.status)}"></span> ${esc(camera.status)}`;

        const media = modal.querySelector('[data-modal-media]');
        media.innerHTML = `<img src="${esc(camera.snapshot_url)}&t=${Date.now()}" alt="${esc(camera.name)}">`;
        const timeline = window.setInterval(() => {
            const img = media.querySelector('img');
            if (img && !modal.hidden) img.src = `${camera.snapshot_url}&t=${Date.now()}`;
        }, Math.max(2000, state.refresh * 1000));

        const body = modal.querySelector('[data-modal-body]');
        body.innerHTML = `
            <div class="ptz">
                <div class="ptz__pad">
                    <span></span>
                    <button type="button" class="ptz__btn" data-ptz="up" title="Tilt up">${icons.up}</button>
                    <span></span>
                    <button type="button" class="ptz__btn" data-ptz="left" title="Pan left">${icons.left}</button>
                    <button type="button" class="ptz__btn" data-ptz="stop" title="Stop">${icons.stop}</button>
                    <button type="button" class="ptz__btn" data-ptz="right" title="Pan right">${icons.right}</button>
                    <span></span>
                    <button type="button" class="ptz__btn" data-ptz="down" title="Tilt down">${icons.down}</button>
                    <span></span>
                </div>
                <div class="ptz__side">
                    <div class="ptz__zoom">
                        <button type="button" class="ptz__btn" data-ptz="zoom_out" title="Zoom out">${icons.zoomOut}</button>
                        <button type="button" class="ptz__btn" data-ptz="zoom_in" title="Zoom in">${icons.zoomIn}</button>
                    </div>
                    <label class="field">
                        <span class="field__label">PTZ speed</span>
                        <input class="slider" type="range" min="0.1" max="1" step="0.1" value="0.5" data-ptz-speed>
                    </label>
                    <div class="ptz__zoom">
                        <button type="button" class="ptz__btn" data-ptz="home" title="Home position">${icons.home}</button>
                        <a class="ptz__btn" href="${esc(camera.download_url)}" title="Save snapshot">${icons.download}</a>
                    </div>
                    <p class="field__hint">${camera.ptz ? 'Hold a direction to move, release to stop.' : 'PTZ is not enabled for this camera.'}</p>
                    <p class="field__hint" data-ptz-feedback></p>
                </div>
            </div>`;

        if (!camera.ptz) {
            body.querySelectorAll('[data-ptz]').forEach((button) => {
                button.disabled = true;
                button.style.opacity = '0.4';
            });
        }

        modal.hidden = false;
        document.body.style.overflow = 'hidden';

        const closeHandler = () => {
            modal.hidden = true;
            window.clearInterval(timeline);
            document.body.style.overflow = '';
            document.removeEventListener('keydown', escHandler);
        };

        const escHandler = (event) => {
            if (event.key === 'Escape') closeHandler();
        };

        document.addEventListener('keydown', escHandler);
        modal.querySelectorAll('[data-modal-close]').forEach((button) => {
            button.onclick = closeHandler;
        });
        modal.onclick = (event) => {
            if (event.target === modal) closeHandler();
        };
    }

    modal?.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-ptz]');
        if (!button || !state.modalCamera || !state.modalCamera.ptz) return;

        const command = button.dataset.ptz;
        const speed = Number(modal.querySelector('[data-ptz-speed]')?.value || 0.5);
        const feedback = modal.querySelector('[data-ptz-feedback]');

        try {
            await window.Argus.post(`${baseUrl}api/ptz/${state.modalCamera.id}`, { command, speed });
            if (feedback) feedback.textContent = `Sent: ${command}`;
        } catch (error) {
            if (feedback) feedback.textContent = error.message;
        }
    });

    /* -------------------------------------------------------------- keyboard */
    document.addEventListener('keydown', (event) => {
        if (['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName)) return;
        if (event.key === 'ArrowRight' && state.mode === 'auto' && autoPageCount() > 1) {
            state.page = (state.page + 1) % autoPageCount();
            render();
        } else if (event.key === 'ArrowLeft' && state.mode === 'auto' && autoPageCount() > 1) {
            state.page = (state.page - 1 + autoPageCount()) % autoPageCount();
            render();
        } else if (event.key === 'Escape') {
            closeDrawer();
        }
    });

    /* ----------------------------------------------------------------- boot */
    if (state.mode === 'custom') {
        state.tiles = normalizeTiles(config.layout.tiles || [], state.slots);
    }
    updateModeButtons();
    render();

    const requested = new URLSearchParams(window.location.search).get('camera');
    if (requested && cameraById.has(Number(requested))) {
        openCameraModal(Number(requested));
    }
})();
