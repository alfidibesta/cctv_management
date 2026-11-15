/* Argus — live wall: multi-grid TV wall, digital zoom/pan, night vision, snapshots. */
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
    const nightButton = root.querySelector('[data-night-vision]');
    const telemetryButton = root.querySelector('[data-telemetry-toggle]');
    const zenButton = root.querySelector('[data-wall-zen]');
    const inspector = document.querySelector('[data-inspector]');
    const inspectorScrim = document.querySelector('[data-inspector-scrim]');

    const baseUrl = document.querySelector('meta[name="base-url"]')?.content || '/';
    const cameras = Array.isArray(config.cameras) ? config.cameras : [];
    const cameraById = new Map(cameras.map((camera) => [Number(camera.id), camera]));

    const players = new Map();
    const viewports = new Map();
    let tileObserver = null;

    const BLANK_FRAME = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    const state = {
        slots: Number(config.layout.slots) || 9,
        mode: config.layout.mode === 'custom' ? 'custom' : 'auto',
        tiles: [],
        page: 0,
        selected: 0,
        refresh: Math.max(2, Number(config.refresh) || 5),
        statusEvery: Math.max(10, Number(config.status) || 20),
        canManage: Boolean(config.canManage),
        paused: false,
        nightVision: false,
        modalCamera: null,
    };

    const icons = {
        plus: '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>',
        expand: '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M9 4H4v5M15 4h5v5M15 20h5v-5M9 20H4v-5"/></svg>',
        open: '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/></svg>',
        download: '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4v11"/><path d="m8 11 4 4 4-4"/><path d="M4 19h16"/></svg>',
        close: '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg>',
        moon: '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20.2 14.2A8.4 8.4 0 0 1 9.8 3.8a8.4 8.4 0 1 0 10.4 10.4z"/></svg>',
        zoom: '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><circle cx="11" cy="11" r="6.5"/><path d="M20 20l-4.2-4.2M8.5 11h5M11 8.5v5"/></svg>',
        ai: '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3.5" y="6" width="10" height="12" rx="2"/><path d="M6.5 10.5v3M10.5 10.5v3M17 9.5l1.2 2.6 2.6 1.2-2.6 1.2L17 17.1l-1.2-2.6-2.6-1.2 2.6-1.2z"/></svg>',
        sliders: '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><path d="M4 8h9M17 8h3M4 16h3M11 16h9"/><circle cx="15" cy="8" r="2.2"/><circle cx="9" cy="16" r="2.2"/></svg>',
        info: '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="8.5"/><path d="M12 11v5M12 8h.01"/></svg>',
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

    function gridColumns(slots) {
        if (slots >= 16) return 4;
        if (slots >= 9) return 3;
        if (slots >= 4) return 2;
        return 1;
    }

    function render() {
        const tiles = visibleTiles();
        stopAllStreams();
        grid.style.setProperty('--grid-cols', String(gridColumns(state.slots)));
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
        observeTiles();
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
        bindDropTarget(tile, index);
        return tile;
    }

    function cameraTile(cameraId, index) {
        const camera = cameraById.get(cameraId);
        const tile = document.createElement('article');
        tile.className = 'tile';
        tile.dataset.slot = String(index);
        tile.dataset.cameraId = String(cameraId);

        const transport = String(camera.transport || 'tcp').toLowerCase();
        const latency = Math.max(50, Math.min(2000, Number(camera.latency_ms) || 300));

        tile.innerHTML = `
            <div class="tile__media" data-media></div>
            <div class="tile__shade"></div>
            <div class="tile__pill">
                <span class="dot dot--${esc(camera.status)}" data-wall-dot="${cameraId}"></span>
                <span class="tile__pill-name">${esc(camera.name)}</span>
                <span class="tile__pill-live" data-wall-status>${camera.status === 'online' ? 'LIVE' : esc(camera.status)}</span>
            </div>
            <div class="tile__meta">
                <div>
                    <h3>${esc(camera.name)}</h3>
                    <p>${esc(camera.location || camera.group)}</p>
                </div>
                <span class="tile__badge">${esc(modeLabel(camera.mode))}</span>
            </div>
            <div class="tile__quick" data-quick-bar>
                <button type="button" class="tile__quick-btn" data-quick="zoom" title="Digital zoom" aria-label="Digital zoom">${icons.zoom}</button>
                <button type="button" class="tile__quick-btn" data-quick="ai" title="Person detection & counting" aria-label="Person detection">${icons.ai}</button>
                <button type="button" class="tile__quick-btn" data-quick="stream" title="Transport & jitter buffer" aria-label="Stream settings">${icons.sliders}</button>
                <button type="button" class="tile__quick-btn" data-quick="ir" title="IR night vision" aria-label="IR night vision">${icons.moon}</button>
                <button type="button" class="tile__quick-btn" data-quick="snap" title="PNG snapshot" aria-label="Snapshot">${icons.download}</button>
                <button type="button" class="tile__quick-btn" data-quick="details" title="Camera inspector" aria-label="Camera inspector">${icons.info}</button>
            </div>
            <div class="tile__pop" data-stream-popover hidden>
                <header class="tile__pop-head">
                    <strong>Stream &amp; latency</strong>
                    <button type="button" class="tile__pop-close" data-pop-close aria-label="Close">${icons.close}</button>
                </header>
                <div class="tile__pop-field">
                    <span>Transport</span>
                    <div class="segmented segmented--xs">
                        <button type="button" class="segmented__btn ${transport === 'udp' ? 'is-active' : ''}" data-transport="udp">UDP</button>
                        <button type="button" class="segmented__btn ${transport === 'tcp' ? 'is-active' : ''}" data-transport="tcp">TCP</button>
                    </div>
                </div>
                <label class="tile__pop-field">
                    <span>Jitter buffer <b data-jitter-value>${latency} ms</b></span>
                    <input class="tile__pop-range" type="range" min="50" max="2000" step="10" value="${latency}" data-jitter>
                </label>
                <label class="tile__pop-check">
                    <input type="checkbox" data-framedrop ${camera.frame_drop ? 'checked' : ''}>
                    <span>Drop late frames</span>
                </label>
                <button type="button" class="btn btn--primary btn--sm btn--block" data-stream-save>Apply</button>
                <p class="tile__pop-hint">Applies the next time this stream starts.</p>
            </div>
            <div class="tile__offline" hidden>no signal</div>`;

        if (!camera.enabled) {
            tile.querySelector('[data-wall-status]').textContent = 'disabled';
        }

        const mediaHost = tile.querySelector('[data-media]');
        const viewport = window.Argus.createViewport(mediaHost, {
            latencyTarget: camera.latency_ms,
            hud: true,
            pip: true,
        });

        if (viewport) {
            viewports.set(cameraId, viewport);
            viewport.setSource(buildMediaSource(camera), camera.mode === 'hls' ? 'video' : 'image');
            viewport.setNightVision(state.nightVision);

            attachTileDetector(camera, tile, viewport);
        }

        bindTileControls(tile, camera, viewport, index);
        bindDropTarget(tile, index);
        return tile;
    }

    /* ------------------------------------------------------- quick actions */
    function bindTileControls(tile, camera, viewport, index) {
        const pop = tile.querySelector('[data-stream-popover]');
        if (pop) {
            let transport = String(camera.transport || 'tcp').toLowerCase();
            const markTransport = (value) => {
                transport = value;
                pop.querySelectorAll('[data-transport]').forEach((button) => {
                    button.classList.toggle('is-active', button.dataset.transport === value);
                });
            };
            markTransport(transport);

            if (!state.canManage) {
                pop.querySelectorAll('input, button').forEach((control) => {
                    control.disabled = true;
                });
            }

            const range = pop.querySelector('[data-jitter]');
            const rangeLabel = pop.querySelector('[data-jitter-value]');
            range?.addEventListener('input', () => {
                if (rangeLabel) rangeLabel.textContent = `${range.value} ms`;
            });

            pop.querySelectorAll('[data-transport]').forEach((button) => {
                button.addEventListener('click', (event) => {
                    event.stopPropagation();
                    if (state.canManage) markTransport(button.dataset.transport);
                });
            });

            pop.querySelector('[data-pop-close]')?.addEventListener('click', (event) => {
                event.stopPropagation();
                pop.hidden = true;
            });

            pop.querySelector('[data-stream-save]')?.addEventListener('click', async (event) => {
                event.stopPropagation();
                if (!state.canManage) return;

                const button = event.currentTarget;
                button.disabled = true;
                try {
                    const result = await window.Argus.postJson(`${baseUrl}api/cameras/${camera.id}/stream`, {
                        transport,
                        latency_ms: Number(range?.value) || 300,
                        frame_drop: Boolean(pop.querySelector('[data-framedrop]')?.checked),
                    });
                    camera.transport = result.transport;
                    camera.latency_ms = result.latency_ms;
                    camera.frame_drop = result.frame_drop;
                    viewport?.setLatencyTarget(result.latency_ms);
                    window.Argus?.toast?.('Stream settings saved — applied on the next start.', 'success');
                    pop.hidden = true;
                } catch (error) {
                    window.Argus?.toast?.(error.message || 'Could not save the stream settings.', 'error');
                } finally {
                    button.disabled = false;
                }
            });

            pop.addEventListener('click', (event) => event.stopPropagation());
            pop.addEventListener('pointerdown', (event) => event.stopPropagation());
        }

        tile.addEventListener('click', (event) => {
            const action = event.target.closest('[data-quick]')?.dataset.quick;
            if (action) {
                event.stopPropagation();
                state.selected = index;
                syncSelection();
                handleQuickAction(action, camera, tile, viewport);
                return;
            }

            if (event.target.closest('.vp__bar') || event.target.closest('.tile__pop') || event.target.closest('.dt-panel')) return;
            state.selected = index;
            syncSelection();
        });

        tile.addEventListener('dblclick', (event) => {
            if (event.target.closest('.vp__bar') || event.target.closest('.tile__pop') || event.target.closest('.dt-panel')) return;
            openCameraModal(camera.id);
        });
    }

    // Clicking anywhere else dismisses the floating controls.
    document.addEventListener('click', (event) => {
        if (event.target.closest('.tile__pop') || event.target.closest('.dt-panel')) return;
        if (event.target.closest('[data-quick]')) return;
        closeStreamPopovers();
        window.Argus.detector?.closePanels();
    });

    function closeStreamPopovers(except = null) {
        grid.querySelectorAll('[data-stream-popover]').forEach((pop) => {
            if (pop !== except) pop.hidden = true;
        });
    }

    function handleQuickAction(action, camera, tile, viewport) {
        if (action === 'zoom') {
            closeStreamPopovers();
            window.Argus.detector?.closePanels();
            tile.classList.toggle('is-zoom');
            return;
        }

        if (action === 'ai') {
            closeStreamPopovers();
            tile.classList.remove('is-zoom');
            window.Argus.detector?.togglePanel(camera.id);
            return;
        }

        if (action === 'stream') {
            const pop = tile.querySelector('[data-stream-popover]');
            const willOpen = Boolean(pop?.hidden);
            closeStreamPopovers();
            window.Argus.detector?.closePanels();
            tile.classList.remove('is-zoom');
            if (pop && willOpen) pop.hidden = false;
            return;
        }

        if (action === 'ir') {
            toggleNightVision(camera, viewport);
            return;
        }

        if (action === 'snap') {
            saveSnapshot(camera);
            return;
        }

        if (action === 'details') {
            openInspector(camera, viewport);
        }
    }

    function bindDropTarget(tile, index) {
        tile.addEventListener('dragover', onDragOver);
        tile.addEventListener('dragleave', () => tile.classList.remove('is-dropping'));
        tile.addEventListener('drop', (event) => {
            event.preventDefault();
            tile.classList.remove('is-dropping');
            const id = Number(event.dataTransfer.getData('text/camera-id'));
            if (id) assign(id, index);
        });
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

    /* ------------------------------------------------------ night vision */
    function toggleNightVision(camera, viewport) {
        if (!viewport) return;
        const active = !viewport.host.classList.contains('is-night');
        viewport.setNightVision(active);
        window.Argus?.toast?.(`IR night vision ${active ? 'on' : 'off'} — ${camera.name}`, 'success');
    }

    nightButton?.addEventListener('click', () => {
        state.nightVision = !state.nightVision;
        nightButton.setAttribute('aria-pressed', state.nightVision ? 'true' : 'false');
        nightButton.classList.toggle('is-active', state.nightVision);
        viewports.forEach((viewport) => viewport.setNightVision(state.nightVision));
    });

    /* ------------------------------------------------ telemetry & theatre */
    const pref = {
        get(key) {
            try {
                return window.localStorage.getItem(`argus.${key}`);
            } catch (error) {
                return null;
            }
        },
        set(key, value) {
            try {
                window.localStorage.setItem(`argus.${key}`, value);
            } catch (error) {
                /* private mode */
            }
        },
    };

    function applyTelemetry(on) {
        grid.classList.toggle('is-telemetry', on);
        telemetryButton?.setAttribute('aria-pressed', on ? 'true' : 'false');
        telemetryButton?.classList.toggle('is-active', on);
    }

    applyTelemetry(pref.get('telemetry') === '1');
    telemetryButton?.addEventListener('click', () => {
        const next = !grid.classList.contains('is-telemetry');
        applyTelemetry(next);
        pref.set('telemetry', next ? '1' : '0');
    });

    function applyZen(on) {
        const html = document.documentElement;
        if (on) {
            html.dataset.zen = '1';
        } else {
            delete html.dataset.zen;
        }
        zenButton?.setAttribute('aria-pressed', on ? 'true' : 'false');
        zenButton?.classList.toggle('is-active', on);
    }

    zenButton?.addEventListener('click', () => applyZen(document.documentElement.dataset.zen !== '1'));

    /* ------------------------------------------------------------ drawer */
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

                if (entry.latency_ms) camera.last_latency_ms = entry.latency_ms;

                const tile = grid.querySelector(`[data-camera-id="${entry.id}"]`);
                const statusText = tile?.querySelector('[data-wall-status]');
                if (statusText) statusText.textContent = entry.status === 'online' ? 'LIVE' : entry.status;
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
        viewports.forEach((viewport, cameraId) => {
            const img = viewport.host.querySelector('img[data-snapshot]');
            const tile = grid.querySelector(`[data-camera-id="${cameraId}"]`);
            if (!img || (tile && tile.dataset.inview === '0')) return;
            const camera = cameraById.get(cameraId);
            if (!camera || tile?.dataset.live !== '1') return;
            const base = img.dataset.snapshot;
            img.src = `${base}${base.includes('?') ? '&' : '?'}t=${Date.now()}`;
        });
    }

    function attachTimers() {
        window.clearInterval(snapshotTimer);
        snapshotTimer = window.setInterval(refreshSnapshots, state.refresh * 1000);
    }

    /* ------------------------------------------------- live stream gating */
    function buildMediaSource(camera) {
        if (camera.mode === 'hls') {
            const video = document.createElement('video');
            video.muted = true;
            video.playsInline = true;
            video.autoplay = true;
            video.preload = 'auto';
            video.dataset.hls = String(camera.id);
            return video;
        }

        const img = document.createElement('img');
        img.alt = camera.name;
        img.decoding = 'async';

        if (camera.mode === 'snapshot') {
            img.dataset.snapshot = camera.snapshot_url;
            img.src = `${camera.snapshot_url}${camera.snapshot_url.includes('?') ? '&' : '?'}t=${Date.now()}`;
        } else {
            img.dataset.mjpeg = camera.mjpeg_url;
            img.dataset.src = camera.mjpeg_url;
        }

        return img;
    }

    function destroyPlayer(cameraId) {
        const player = players.get(cameraId);
        if (!player) return;
        players.delete(cameraId);
        try {
            player.destroy();
        } catch (error) {
            /* already torn down */
        }
    }

    function teardownVideo(video) {
        if (!video) return;
        try {
            video._argusTeardown?.();
        } catch (error) {
            /* already torn down */
        }
        video._argusTeardown = null;
        video.srcObject = null;
        video.removeAttribute('src');
        try {
            video.load();
        } catch (error) {
            /* detached */
        }
    }

    /** Person detection runs in a worker; the overlay draws at 30 FPS. */
    function attachTileDetector(camera, tile, viewport) {
        window.Argus.detector?.attach({
            camera,
            tile,
            viewport,
            key: camera.id,
            canManage: state.canManage,
            variant: 'tile',
            onOpen: () => openCameraModal(camera.id),
        });
    }

    function stopAllStreams() {
        window.Argus.detector?.detachAll();

        players.forEach((player) => {
            try {
                player.destroy();
            } catch (error) {
                /* already torn down */
            }
        });
        players.clear();

        viewports.forEach((viewport) => {
            teardownVideo(viewport.host.querySelector('video'));
            viewport.destroy();
        });
        viewports.clear();

        if (tileObserver) tileObserver.disconnect();
    }

    function startTileMedia(tile, camera) {
        if (!tile || tile.dataset.live === '1') return;
        tile.dataset.live = '1';

        const viewport = viewports.get(camera.id);
        const media = viewport?.host.querySelector('.vp__media');
        if (!media) return;

        if (camera.mode === 'mjpeg') {
            if (media.dataset.src && !media.src) media.src = media.dataset.src;
        } else if (camera.mode === 'hls') {
            startStream(media, camera, viewport);
        }
    }

    function stopTileMedia(tile, camera) {
        if (!tile || tile.dataset.live !== '1') return;
        tile.dataset.live = '0';

        const viewport = viewports.get(camera?.id);
        const media = viewport?.host.querySelector('.vp__media');

        if (camera?.mode === 'mjpeg' && media) {
            media.src = BLANK_FRAME;
        } else if (camera?.mode === 'hls') {
            destroyPlayer(camera.id);
            teardownVideo(media);
            viewport?.showTestPattern(false);
        }
    }

    /** Keep media running only for tiles that are on screen and visible. */
    function syncLiveStreams() {
        grid.querySelectorAll('.tile[data-camera-id]').forEach((tile) => {
            const camera = cameraById.get(Number(tile.dataset.cameraId));
            if (!camera) return;

            if (!document.hidden && tile.dataset.inview === '1') {
                startTileMedia(tile, camera);
            } else {
                stopTileMedia(tile, camera);
            }
        });
    }

    function observeTiles() {
        const tiles = Array.from(grid.querySelectorAll('.tile[data-camera-id]'));
        if (!tiles.length) return;

        if (!('IntersectionObserver' in window)) {
            tiles.forEach((tile) => {
                tile.dataset.inview = '1';
            });
            syncLiveStreams();
            return;
        }

        if (tileObserver) tileObserver.disconnect();
        tileObserver = new IntersectionObserver(
            (entries) => {
                entries.forEach((entry) => {
                    entry.target.dataset.inview = entry.isIntersecting ? '1' : '0';
                });
                syncLiveStreams();
            },
            { threshold: 0.05 }
        );

        tiles.forEach((tile) => tileObserver.observe(tile));
    }

    document.addEventListener('visibilitychange', () => {
        syncLiveStreams();
        if (!document.hidden) refreshSnapshots();
    });

    window.setInterval(pollStatus, state.statusEvery * 1000);
    window.setTimeout(pollStatus, 2500);

    /* ------------------------------------------------------ media pipeline */
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

    /** WebRTC (WHEP) first; rejects quickly so HLS/MSE can take over. */
    async function startWebrtc(video, camera, viewport = null) {
        if (!camera.webrtc_url || !window.RTCPeerConnection) {
            throw new Error('WebRTC is not available.');
        }

        const peer = new RTCPeerConnection({ iceServers: [] });
        let closed = false;
        let statsTimer = 0;
        let lastBytes = null;
        let lastSampleAt = 0;

        // Real inbound bytes and candidate-pair RTT from the RTC stats API.
        const sampleStats = async () => {
            if (closed) return;
            try {
                const report = await peer.getStats();
                let bytes = null;
                let rtt = null;

                report.forEach((entry) => {
                    if (
                        entry.type === 'inbound-rtp' &&
                        (entry.kind === 'video' || entry.mediaType === 'video') &&
                        typeof entry.bytesReceived === 'number'
                    ) {
                        bytes = entry.bytesReceived;
                    }
                    if (
                        entry.type === 'candidate-pair' &&
                        entry.state === 'succeeded' &&
                        typeof entry.currentRoundTripTime === 'number'
                    ) {
                        rtt = entry.currentRoundTripTime * 1000;
                    }
                });

                const now = performance.now();
                let bitrate = null;
                if (bytes !== null && lastBytes !== null && now > lastSampleAt) {
                    bitrate = ((bytes - lastBytes) * 8 * 1000) / (now - lastSampleAt);
                }
                if (bytes !== null) {
                    lastBytes = bytes;
                    lastSampleAt = now;
                }

                viewport?.setNetwork?.({ bitrate, rtt });
            } catch (error) {
                /* stats are best-effort */
            }
        };

        statsTimer = window.setInterval(sampleStats, 1000);

        const close = () => {
            if (closed) return;
            closed = true;
            window.clearInterval(statsTimer);
            viewport?.setNetwork?.({ bitrate: null, rtt: null });
            try {
                peer.close();
            } catch (error) {
                /* already closed */
            }
        };

        video._argusTeardown = close;
        video.muted = true;
        video.autoplay = true;
        video.playsInline = true;

        const stream = new MediaStream();
        video.srcObject = stream;

        peer.ontrack = (event) => {
            if (event.streams && event.streams[0]) {
                video.srcObject = event.streams[0];
            } else {
                stream.addTrack(event.track);
            }
            video.play().catch(() => {});
        };

        peer.addTransceiver('video', { direction: 'recvonly' });
        const offer = await peer.createOffer();
        await peer.setLocalDescription(offer);

        const answer = await window.Argus.postJson(camera.webrtc_url, {
            sdp: offer.sdp,
            type: offer.type,
        });

        if (!answer || !answer.sdp) throw new Error('The media server returned no SDP answer.');

        await peer.setRemoteDescription({ type: 'answer', sdp: answer.sdp });

        await new Promise((resolve, reject) => {
            const timer = window.setTimeout(() => reject(new Error('WebRTC negotiation timed out.')), 6000);

            const settle = () => {
                window.clearTimeout(timer);
                resolve();
            };

            video.addEventListener('playing', settle, { once: true });
            peer.addEventListener('connectionstatechange', () => {
                if (peer.connectionState === 'connected') settle();
                if (['failed', 'disconnected', 'closed'].includes(peer.connectionState)) {
                    window.clearTimeout(timer);
                    reject(new Error('WebRTC connection failed.'));
                }
            });
        });

        return peer;
    }

    /** HLS through hls.js (MSE) or native playback, with one stability retry. */
    async function startHls(video, camera, stable = false, viewport = null, key = null) {
        const streamKey = key ?? camera.id;
        if (!video || players.has(streamKey)) return;

        const response = await window.Argus.post(`${baseUrl}api/hls/${camera.id}`, {});

        if (typeof response.latency_ms === 'number') {
            viewport?.setLatencyTarget(response.latency_ms);
        }

        if (video.canPlayType('application/vnd.apple.mpegurl')) {
            video.src = response.playlist;
            video.play().catch(() => {});
            return;
        }

        const Hls = await loadHlsLibrary();

        if (typeof Hls.isSupported === 'function' && !Hls.isSupported()) {
            throw new Error('This browser cannot play HLS (no Media Source support).');
        }

        const player = new Hls(stable ? {
            lowLatencyMode: false,
            liveSyncDurationCount: 3,
            liveMaxLatencyDurationCount: 6,
            maxBufferLength: 10,
            backBufferLength: 6,
            enableWorker: true,
        } : {
            lowLatencyMode: true,
            liveSyncDurationCount: 1,
            liveMaxLatencyDurationCount: 4,
            maxLiveSyncPlaybackRate: 2,
            enableWorker: true,
            backBufferLength: 4,
            maxBufferLength: 4,
        });

        players.set(streamKey, player);
        video._argusTeardown = () => {
            players.delete(streamKey);
            player.destroy();
        };

        let retried = stable;
        player.on(Hls.Events.ERROR, (event, data) => {
            if (!data.fatal) return;

            if (data.type === Hls.ErrorTypes.NETWORK_ERROR) {
                player.startLoad();
                return;
            }
            if (data.type === Hls.ErrorTypes.MEDIA_ERROR) {
                player.recoverMediaError();
                return;
            }

            destroyPlayer(streamKey);

            if (!retried) {
                retried = true;
                startHls(video, camera, true, viewport, streamKey).catch(() => {
                    viewport?.showTestPattern(true);
                });
                return;
            }

            viewport?.showTestPattern(true);
        });

        player.on(Hls.Events.MANIFEST_PARSED, () => video.play().catch(() => {}));

        // hls.js exposes the real distance from the live edge: use it for the HUD.
        player.on(Hls.Events.LEVEL_UPDATED, () => {
            if (typeof player.latency === 'number' && player.latency > 0) {
                viewport?.setMeasuredLatency(player.latency * 1000);
            }

            // Until fragments arrive, report hls.js' own bandwidth estimate.
            if (typeof player.bandwidthEstimate === 'number' && player.bandwidthEstimate > 0) {
                viewport?.setNetwork?.({ bitrate: player.bandwidthEstimate });
            }
        });

        // Actual media bitrate from the bytes of the last 10 s of fragments.
        const fragments = [];
        player.on(Hls.Events.FRAG_LOADED, (event, data) => {
            const stats = data?.payload?.stats || {};
            const bytes = Number(stats.loaded ?? stats.total ?? 0);
            if (!bytes) return;

            const now = performance.now();
            fragments.push({ at: now, bytes });

            const cutoff = now - 10000;
            while (fragments.length > 1 && fragments[0].at < cutoff) fragments.shift();

            const total = fragments.reduce((sum, item) => sum + item.bytes, 0);
            const span = Math.max(1000, now - fragments[0].at);
            viewport?.setNetwork?.({ bitrate: (total * 8 * 1000) / span });
        });

        player.loadSource(response.playlist);
        player.attachMedia(video);
    }

    /**
     * WebRTC → HLS/MSE → MJPEG → canvas test pattern.
     * The last step is deliberate: a dead camera still shows a live clock instead
     * of a frozen black rectangle.
     */
    async function startStream(video, camera, viewport, key = null) {
        if (!video) return;
        viewport?.showTestPattern(false);

        try {
            await startWebrtc(video, camera, viewport);
            viewport?.setMeasuredLatency(camera.latency_ms);
            return;
        } catch (error) {
            console.warn('WebRTC unavailable, falling back', error);
            teardownVideo(video);
        }

        try {
            await startHls(video, camera, false, viewport, key);
        } catch (error) {
            console.warn('HLS unavailable, falling back', error);
            teardownVideo(video);
            viewport?.showTestPattern(true);
        }
    }

    /* --------------------------------------------------------- snapshot */
    function saveSnapshot(camera) {
        const viewport = viewports.get(camera.id);
        const dataUrl = viewport?.snapshot();

        if (!dataUrl) {
            window.location.href = camera.download_url;
            return;
        }

        const link = document.createElement('a');
        const stamp = new Date().toISOString().replace(/[-:]/g, '').slice(0, 15);
        link.href = dataUrl;
        link.download = `${String(camera.name).replace(/[^A-Za-z0-9._-]+/g, '-')}-${stamp}.png`;
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.Argus?.toast?.('Snapshot saved from the live canvas.', 'success');
    }

    /* --------------------------------------------------- camera inspector */
    let inspectorTimer = null;
    let inspectorTarget = null;

    function openInspector(camera, viewport) {
        if (!inspector) return;

        inspectorTarget = { camera, viewport };
        closeStreamPopovers();
        window.Argus.detector?.closePanels();

        const set = (selector, value) => {
            const node = inspector.querySelector(selector);
            if (node) node.textContent = value;
        };

        const host = String(camera.endpoint || '').split(':')[0];
        const hasHost = host !== '';
        const rtsp = hasHost && camera.rtsp_path
            ? `rtsp://${host}:${Number(camera.rtsp_port) || 554}${camera.rtsp_path}`
            : '—';

        set('[data-inspector-title]', camera.name);
        set('[data-inspector-sub]', [camera.location, camera.group, camera.driver_label].filter(Boolean).join(' · '));
        set('[data-inspector-endpoint]', hasHost ? camera.endpoint : '—');
        set('[data-inspector-rtsp]', rtsp);
        set('[data-inspector-driver]', camera.driver_label || camera.driver || '—');
        set('[data-inspector-mode]', modeLabel(camera.mode));
        set('[data-inspector-transport]', String(camera.transport || 'tcp').toUpperCase());
        set('[data-inspector-probe]', camera.last_latency_ms ? `${camera.last_latency_ms} ms` : (camera.status || 'unknown'));

        const result = inspector.querySelector('[data-inspector-verify-result]');
        if (result) {
            result.hidden = true;
            result.innerHTML = '';
        }

        const edit = inspector.querySelector('[data-inspector-edit]');
        if (edit) {
            edit.href = `${baseUrl}cameras/${camera.id}/edit`;
            edit.hidden = !state.canManage;
        }

        const remove = inspector.querySelector('[data-inspector-remove]');
        if (remove) remove.hidden = !(state.canManage && state.mode === 'custom');

        const verify = inspector.querySelector('[data-inspector-verify]');
        if (verify) verify.disabled = !state.canManage;

        updateInspectorTelemetry();
        window.clearInterval(inspectorTimer);
        inspectorTimer = window.setInterval(updateInspectorTelemetry, 1000);

        inspector.classList.add('is-open');
        inspector.setAttribute('aria-hidden', 'false');
        if (inspectorScrim) inspectorScrim.hidden = false;
    }

    function updateInspectorTelemetry() {
        if (!inspectorTarget || !inspector) return;
        const { camera, viewport } = inspectorTarget;
        const telemetry = viewport?.telemetry || {};
        const source = viewport?.getSourceElement?.();
        const width = source?.videoWidth || source?.naturalWidth || 0;
        const height = source?.videoHeight || source?.naturalHeight || 0;

        const set = (selector, value) => {
            const node = inspector.querySelector(selector);
            if (node) node.textContent = value;
        };

        set('[data-inspector-fps]', Number(telemetry.fps || 0).toFixed(1));
        set('[data-inspector-frame]', `${Number(telemetry.frameTime || 0).toFixed(1)} ms`);
        set('[data-inspector-jitter]', `${Number(telemetry.jitter || 0).toFixed(1)} ms`);
        set('[data-inspector-buffer]', `${Math.round(Number(telemetry.buffer || 0))}%`);
        set('[data-inspector-latency]', `${Math.round(Number(telemetry.latency || camera.latency_ms || 0))} ms`);
        set('[data-inspector-resolution]', width && height ? `${width} × ${height}` : '—');
        set('[data-inspector-bitrate]', formatBitrate(telemetry.bitrate));
        set('[data-inspector-rtt]', Number(telemetry.rtt) > 0 ? `${Math.round(Number(telemetry.rtt))} ms` : '—');
    }

    function formatBitrate(value) {
        const bps = Number(value);
        if (!Number.isFinite(bps) || bps <= 0) return '—';

        return bps >= 1e6 ? `${(bps / 1e6).toFixed(2)} Mbps` : `${Math.round(bps / 1e3)} kbps`;
    }

    function closeInspector() {
        window.clearInterval(inspectorTimer);
        inspectorTimer = null;
        inspectorTarget = null;
        inspector?.classList.remove('is-open');
        inspector?.setAttribute('aria-hidden', 'true');
        if (inspectorScrim) inspectorScrim.hidden = true;
    }

    inspector?.querySelector('[data-inspector-close]')?.addEventListener('click', closeInspector);
    inspectorScrim?.addEventListener('click', closeInspector);

    inspector?.querySelector('[data-inspector-verify]')?.addEventListener('click', async (event) => {
        if (!inspectorTarget) return;

        const button = event.currentTarget;
        const box = inspector.querySelector('[data-inspector-verify-result]');
        button.disabled = true;

        try {
            const report = await window.Argus.postJson(`${baseUrl}cameras/${inspectorTarget.camera.id}/credentials`, {});
            if (!box) return;

            box.hidden = false;
            box.innerHTML = `
                <p class="inspector__verdict ${report.ok ? 'is-ok' : 'is-bad'}">${esc(report.status || 'UNKNOWN')}</p>
                <dl class="inspector__grid inspector__grid--wide">
                    <div><dt>Decrypted</dt><dd><code>${esc(report.plaintext || '(none)')}</code></dd></div>
                    <div><dt>Masked</dt><dd>${esc(report.masked || '—')}</dd></div>
                    <div><dt>Encoded</dt><dd><code>${esc(report.encoded_password || '—')}</code></dd></div>
                    <div><dt>RTSP URI</dt><dd>${esc(report.uri_masked || report.uri || '—')}</dd></div>
                </dl>
                ${(report.issues || []).length ? `<ul class="inspector__issues">${report.issues.map((issue) => `<li>${esc(issue)}</li>`).join('')}</ul>` : ''}`;
        } catch (error) {
            if (box) {
                box.hidden = false;
                box.innerHTML = `<p class="inspector__verdict is-bad">${esc(error.message || 'Verification failed.')}</p>`;
            }
        } finally {
            if (inspectorTarget) button.disabled = false;
        }
    });

    inspector?.querySelector('[data-inspector-remove]')?.addEventListener('click', () => {
        if (!inspectorTarget) return;
        const cameraId = inspectorTarget.camera.id;
        const tile = grid.querySelector(`.tile[data-camera-id="${cameraId}"]`);
        const slot = tile ? Number(tile.dataset.slot) : -1;
        closeInspector();
        if (slot >= 0) clearSlot(slot);
    });

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

        const mediaHost = modal.querySelector('[data-modal-media]');
        window.Argus.detector?.detach(`modal-${camera.id}`);
        mediaHost.replaceChildren();

        const viewport = window.Argus.createViewport(mediaHost, {
            latencyTarget: camera.latency_ms,
            hud: true,
            pip: true,
        });

        window.Argus.detector?.attach({
            camera,
            tile: mediaHost,
            viewport,
            key: `modal-${camera.id}`,
            canManage: state.canManage,
            variant: 'modal',
        });

        const source = buildMediaSource(camera);
        viewport.setSource(source, camera.mode === 'hls' ? 'video' : 'image');
        viewport.setNightVision(state.nightVision);

        const modalKey = `modal-${camera.id}`;

        if (camera.mode === 'hls') {
            startStream(source, camera, viewport, modalKey);
        } else if (camera.mode === 'mjpeg' && source.dataset.src) {
            source.src = source.dataset.src;
        }

        const timeline = window.setInterval(() => {
            if (modal.hidden) return;
            if (camera.mode !== 'snapshot') return;
            source.src = `${camera.snapshot_url}${camera.snapshot_url.includes('?') ? '&' : '?'}t=${Date.now()}`;
        }, Math.max(2000, state.refresh * 1000));

        const body = modal.querySelector('[data-modal-body]');
        body.innerHTML = `
            <div class="viewer">
                <div class="viewer__detail">
                    <h4 class="card__mini-title">Real-time pipeline</h4>
                    <ul class="diag-list">
                        <li><span>Transport</span><span class="diag">${esc(String(camera.transport || 'tcp').toUpperCase())}</span></li>
                        <li><span>Latency target</span><span class="diag">${Number(camera.latency_ms) || 300} ms</span></li>
                        <li><span>Frame drop</span><span class="diag">${camera.frame_drop ? 'on' : 'off'}</span></li>
                        <li><span>Stream mode</span><span class="diag">${esc(modeLabel(camera.mode))}</span></li>
                        <li><span>Live telemetry</span><span class="diag" data-modal-telemetry>—</span></li>
                    </ul>
                </div>
                <div class="viewer__detail">
                    <h4 class="card__mini-title">Digital view</h4>
                    <p class="field__hint">
                        Wheel to zoom at the cursor, drag to pan, double-click to reset. Zoom and pan are
                        browser-side only — the camera is never moved.
                    </p>
                    <div class="viewer__actions">
                        <button type="button" class="btn btn--subtle btn--sm" data-modal-night>${icons.moon} Night vision</button>
                        <button type="button" class="btn btn--subtle btn--sm" data-modal-snapshot>${icons.download} Snapshot</button>
                        <button type="button" class="btn btn--ghost btn--sm" data-modal-reset>Reset 1.0x</button>
                    </div>
                </div>
            </div>`;

        const telemetry = body.querySelector('[data-modal-telemetry]');
        const onTelemetry = (event) => {
            const data = event.detail?.telemetry;
            if (!data || !telemetry) return;
            telemetry.textContent = `${data.fps.toFixed(1)} fps · ${data.frameTime.toFixed(1)} ms · jitter ${data.jitter.toFixed(1)} · buffer ${data.buffer}%`;
        };
        window.addEventListener('viewport:telemetry', onTelemetry);

        body.querySelector('[data-modal-night]')?.addEventListener('click', (event) => {
            const active = !viewport.host.classList.contains('is-night');
            viewport.setNightVision(active);
            event.currentTarget.classList.toggle('is-active', active);
        });
        body.querySelector('[data-modal-snapshot]')?.addEventListener('click', () => {
            const dataUrl = viewport.snapshot();
            if (!dataUrl) return;
            const link = document.createElement('a');
            link.href = dataUrl;
            link.download = `${String(camera.name).replace(/[^A-Za-z0-9._-]+/g, '-')}.png`;
            link.click();
        });
        body.querySelector('[data-modal-reset]')?.addEventListener('click', () => viewport.reset());

        modal.hidden = false;
        document.body.style.overflow = 'hidden';

        const closeHandler = () => {
            modal.hidden = true;
            window.clearInterval(timeline);
            window.removeEventListener('viewport:telemetry', onTelemetry);
            document.body.style.overflow = '';
            document.removeEventListener('keydown', escHandler);
            window.Argus.detector?.detach(modalKey);
            teardownVideo(viewport.host.querySelector('video'));
            destroyPlayer(modalKey);
            viewport.destroy();
            mediaHost.replaceChildren();

            // Detection settings may have changed in the camera view: rebuild
            // the tile overlay from the refreshed camera record.
            const tile = grid.querySelector(`.tile[data-camera-id="${camera.id}"]`);
            const tileViewport = viewports.get(camera.id);
            if (tile && tileViewport && tileViewport.host.isConnected) {
                window.Argus.detector?.detach(camera.id);
                attachTileDetector(camera, tile, tileViewport);
            }
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
            closeStreamPopovers();
            window.Argus.detector?.closePanels();
            closeInspector();
            applyZen(false);
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
