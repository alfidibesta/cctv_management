/*
 * Argus — person detection overlay and people counting.
 *
 * The Web Worker runs inference (or simulated pedestrians) at 5–10 FPS; this
 * module draws boxes, tracking ids, tripwire and zone overlays on a separate
 * canvas at a locked 30 FPS, so video playback never waits for inference.
 */
(() => {
    'use strict';

    const Argus = (window.Argus = window.Argus || {});
    const baseUrl = document.querySelector('meta[name="base-url"]')?.content || '/';
    const FRAME_INTERVAL = 1000 / 30;
    const SYNC_INTERVAL = 5000;
    const OCCUPANCY_INTERVAL = 30000;
    const instances = new Map();

    const esc = (value) =>
        String(value ?? '').replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));

    const DEFAULTS = {
        enabled: false,
        confidence: 0.55,
        boxes: true,
        mode: 'off',
        tripwire: null,
        flip: false,
        zone: null,
        zone_threshold: 5,
    };

    const clamp = (value, min, max) => Math.min(max, Math.max(min, value));

    const icons = {
        person: '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="6.5" r="2.6"/><path d="M7 20v-3.4a2 2 0 0 1 1.6-2l2-.4V10h2.8v4.2l2 .4a2 2 0 0 1 1.6 2V20"/></svg>',
        close: '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg>',
    };

    /** css-positioned projection helper for normalised frame coordinates. */
    function projector(viewport) {
        const p = viewport?.getProjection?.();
        if (!p) return null;

        return {
            p,
            x: (nx) => p.dx + nx * p.sw * p.scale,
            y: (ny) => p.dy + ny * p.sh * p.scale,
            w: (nw) => nw * p.sw * p.scale,
            h: (nh) => nh * p.sh * p.scale,
            fromClient(clientX, clientY, rect) {
                return {
                    x: clamp((clientX - rect.left - p.dx) / (p.sw * p.scale), 0, 1),
                    y: clamp((clientY - rect.top - p.dy) / (p.sh * p.scale), 0, 1),
                };
            },
        };
    }

    function mergeConfig(raw) {
        const config = Object.assign({}, DEFAULTS, raw || {});
        config.confidence = clamp(Number(config.confidence) || 0.55, 0.3, 0.95);
        config.zone_threshold = clamp(Number(config.zone_threshold) || 5, 1, 999);
        return config;
    }

    function side(segment, point) {
        return (
            (segment.b.x - segment.a.x) * (point.y - segment.a.y) -
            (segment.b.y - segment.a.y) * (point.x - segment.a.x)
        );
    }

    function insideZone(zone, point) {
        return point.x >= zone.x && point.x <= zone.x + zone.w && point.y >= zone.y && point.y <= zone.y + zone.h;
    }

    function attach(options) {
        const { camera, tile, viewport, canManage } = options;
        const key = String(options.key ?? camera.id);
        if (!camera || !tile || !viewport) return null;
        if (instances.has(key)) return instances.get(key);

        const host = viewport.host;
        const state = {
            camera,
            key,
            canManage: Boolean(canManage),
            config: mergeConfig(camera.analytics),
            detections: [],
            previous: new Map(),
            counts: { in: 0, out: 0, inside: 0 },
            events: [],
            lastSync: 0,
            lastOccupancy: 0,
            alarm: false,
            drawMode: null,
            draft: null,
            tempA: null,
            worker: null,
            backend: camera.analytics_backend || 'simulate',
            inferenceMs: 0,
            lastFrame: 0,
            lastHud: 0,
            running: true,
        };

        if (!state.config.enabled && state.config.mode === 'off') {
            // Counting stays off until an operator draws a line or box, but the
            // worker still runs so enabling detection is instantaneous.
        }

        /* ------------------------------------------------------------ DOM */
        const overlay = document.createElement('canvas');
        overlay.className = 'dt-overlay';
        host.appendChild(overlay);

        const counts = document.createElement('div');
        counts.className = 'dt-counts';
        counts.innerHTML = '<span class="dt-counts__item">IN <b data-dt-in>0</b></span>'
            + '<span class="dt-counts__item">OUT <b data-dt-out>0</b></span>'
            + '<span class="dt-counts__item">INSIDE <b data-dt-inside>0</b></span>';
        host.appendChild(counts);

        const cluster = document.createElement('div');
        cluster.className = 'dt-cluster';
        cluster.innerHTML = `<button type="button" class="dt-btn" data-dt-open title="Person detection & counting">${icons.person}<span>People</span></button>`;
        host.appendChild(cluster);

        const panel = document.createElement('div');
        panel.className = 'dt-panel';
        panel.hidden = true;
        panel.innerHTML = `
            <header class="dt-panel__head">
                <strong>Person detection</strong>
                <span class="dt-backend" data-dt-backend>${esc(state.backend)}</span>
                <button type="button" class="dt-icon" data-dt-close aria-label="Close">${icons.close}</button>
            </header>
            <label class="dt-switch">
                <input type="checkbox" data-dt-enabled ${state.config.enabled ? 'checked' : ''}>
                <span>Detection</span>
            </label>
            <label class="dt-field">
                <span>Confidence <b data-dt-conf>${Math.round(state.config.confidence * 100)}%</b></span>
                <input type="range" min="30" max="95" step="1" value="${Math.round(state.config.confidence * 100)}" data-dt-confidence>
            </label>
            <label class="dt-switch">
                <input type="checkbox" data-dt-boxes ${state.config.boxes ? 'checked' : ''}>
                <span>Bounding boxes</span>
            </label>
            <div class="dt-buttons">
                <button type="button" class="dt-chip" data-dt-draw="tripwire">Tripwire</button>
                <button type="button" class="dt-chip" data-dt-draw="zone">Zone</button>
                <button type="button" class="dt-chip" data-dt-flip>Flip IN/OUT</button>
                <button type="button" class="dt-chip" data-dt-clear>Clear</button>
            </div>
            <label class="dt-field">
                <span>Zone occupancy limit</span>
                <input type="number" min="1" max="999" step="1" value="${state.config.zone_threshold}" data-dt-threshold>
            </label>
            <p class="dt-hint" data-dt-hint>
                ${state.canManage ? 'Draw a line for a tripwire or a box for zone occupancy.' : 'Detection settings are managed by an operator.'}
            </p>
            <p class="dt-hint" data-dt-alarm hidden>Zone occupancy exceeded.</p>`;

        host.appendChild(panel);

        if (!state.canManage) {
            panel.querySelectorAll('[data-dt-draw], [data-dt-flip], [data-dt-clear], [data-dt-confidence], [data-dt-boxes], [data-dt-threshold]')
                .forEach((element) => {
                    element.disabled = true;
                });
        }

        const el = {
            overlay,
            ctx: overlay.getContext('2d'),
            counts,
            in: counts.querySelector('[data-dt-in]'),
            out: counts.querySelector('[data-dt-out]'),
            inside: counts.querySelector('[data-dt-inside]'),
            panel,
            backend: panel.querySelector('[data-dt-backend]'),
            conf: panel.querySelector('[data-dt-conf]'),
            hint: panel.querySelector('[data-dt-hint]'),
            alarm: panel.querySelector('[data-dt-alarm]'),
        };

        /* --------------------------------------------------------- worker */
        const worker = new Worker(`${baseUrl}assets/js/detector-worker.js`);

        worker.onmessage = (event) => {
            const message = event.data || {};
            if (message.type === 'ready') {
                state.backend = message.backend;
                if (el.backend) el.backend.textContent = state.backend === 'coco-ssd' ? 'COCO-SSD' : 'simulated';
            } else if (message.type === 'detections') {
                state.detections = Array.isArray(message.boxes) ? message.boxes : [];
                state.inferenceMs = message.inferenceMs || 0;
                count(state.detections);
            }
        };

        worker.postMessage({
            type: 'init',
            backend: state.backend,
            cameraId: Number(camera.id),
            intervalMs: 166,
            seed: Number(camera.id) * 17 + 3,
            active: state.config.enabled,
        });
        state.worker = worker;

        /* -------------------------------------------------- frame capture */
        let captureCanvas = null;
        const captureTimer = window.setInterval(() => {
            if (!state.running || state.backend !== 'coco-ssd' || !state.config.enabled) return;

            const source = viewport.getSourceElement?.();
            if (!source) return;

            const ready = source.tagName === 'VIDEO' ? source.readyState >= 2 : source.naturalWidth > 0;
            if (!ready) return;
            if (source.tagName === 'VIDEO' && source.paused) return;

            try {
                if (!captureCanvas) {
                    captureCanvas = document.createElement('canvas');
                    captureCanvas.width = 320;
                    captureCanvas.height = 180;
                }

                const ctx = captureCanvas.getContext('2d');
                ctx.drawImage(source, 0, 0, captureCanvas.width, captureCanvas.height);

                if (typeof createImageBitmap === 'function') {
                    createImageBitmap(captureCanvas)
                        .then((bitmap) => {
                            if (!state.running) return;
                            worker.postMessage(
                                { type: 'frame', frame: bitmap, width: captureCanvas.width, height: captureCanvas.height },
                                [bitmap]
                            );
                        })
                        .catch(() => {});
                }
            } catch (error) {
                /* tainted or not ready: skip this interval */
            }
        }, 166);

        /* -------------------------------------------------------- counting */
        function count(detections) {
            if (!state.config.enabled) {
                state.counts.inside = 0;
                state.previous = new Map();
                return;
            }

            const threshold = state.config.confidence;
            const visible = detections.filter((box) => box.confidence >= threshold);
            const zone = state.config.zone;
            const mode = state.config.mode;

            const inside = mode === 'zone' || mode === 'both'
                ? visible.filter((box) => zone && insideZone(zone, centre(box))).length
                : visible.length;

            state.counts.inside = inside;

            const next = new Map();

            visible.forEach((box) => {
                const point = centre(box);
                const id = box.id;
                const previous = state.previous.get(id);

                if (mode === 'tripwire' || mode === 'both') {
                    const segment = state.config.tripwire;
                    if (segment && previous) {
                        const nowSide = side(segment, point);
                        const wasSide = side(segment, previous);
                        const crossed = (nowSide < 0 && wasSide >= 0) || (nowSide > 0 && wasSide <= 0);

                        if (crossed && Math.abs(nowSide - wasSide) > 0) {
                            // The vector a→b defines the IN side; flip swaps it.
                            const inwards = (nowSide < 0) !== Boolean(state.config.flip);
                            record(inwards ? 'IN' : 'OUT', inside, box.confidence);
                        }
                    }
                }

                next.set(id, point);
            });

            state.previous = next;

            if (state.config.mode === 'zone' || state.config.mode === 'both') {
                const exceeded = Boolean(zone) && inside > state.config.zone_threshold;

                if (exceeded && !state.alarm) {
                    state.alarm = true;
                    Argus.toast?.(`Zone occupancy ${inside} exceeds the limit of ${state.config.zone_threshold} on ${state.camera.name}.`, 'error');
                } else if (!exceeded) {
                    state.alarm = false;
                }

                el.counts.classList.toggle('is-alarm', state.alarm);
                if (el.alarm) el.alarm.hidden = !state.alarm;
            }
        }

        function record(direction, occupancy, confidence) {
            if (direction === 'IN') state.counts.in += 1;
            if (direction === 'OUT') state.counts.out += 1;

            state.events.push({
                direction,
                occupancy,
                confidence: Math.round(confidence * 100) / 100,
                occurred_at: new Date().toISOString(),
            });

            if (state.events.length > 400) state.events.splice(0, state.events.length - 400);
        }

        /* ------------------------------------------------------- rendering */
        let raf = 0;
        let lastDraw = 0;

        function tick(now) {
            if (!state.running) return;
            raf = requestAnimationFrame(tick);

            if (now - lastDraw < FRAME_INTERVAL) return;
            lastDraw = now;

            draw();

            if (now - state.lastHud >= 250) {
                state.lastHud = now;
                updateHud();
            }
        }

        function draw() {
            const rect = host.getBoundingClientRect();
            const dpr = Math.min(window.devicePixelRatio || 1, 2);
            const width = Math.max(1, Math.round(rect.width));
            const height = Math.max(1, Math.round(rect.height));

            if (overlay.width !== Math.round(width * dpr) || overlay.height !== Math.round(height * dpr)) {
                overlay.width = Math.round(width * dpr);
                overlay.height = Math.round(height * dpr);
            }

            el.ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
            el.ctx.clearRect(0, 0, width, height);

            const proj = projector(viewport);
            if (!proj) return;

            drawTripwire(proj);
            drawZone(proj);
            drawDetections(proj);

            if (state.drawMode) drawDraft(proj);
        }

        function drawDetections(proj) {
            if (!state.config.enabled || !state.config.boxes) return;

            const ctx = el.ctx;
            const threshold = state.config.confidence;

            state.detections.forEach((box) => {
                if (box.confidence < threshold) return;

                const x = proj.x(box.x);
                const y = proj.y(box.y);
                const w = proj.w(box.w);
                const h = proj.h(box.h);
                if (w < 6 || h < 8) return;

                const confidence = Math.round(box.confidence * 100);
                const accent = confidence >= 90 ? '95, 227, 192' : confidence >= 70 ? '240, 196, 99' : '255, 125, 107';

                ctx.strokeStyle = `rgba(${accent}, 0.95)`;
                ctx.lineWidth = 1.6;
                ctx.strokeRect(x, y, w, h);

                // Corner ticks, so small tiles stay legible.
                ctx.lineWidth = 2;
                const tick = Math.max(4, Math.min(10, w * 0.18));
                [[x, y, 1, 1], [x + w, y, -1, 1], [x, y + h, 1, -1], [x + w, y + h, -1, -1]].forEach(([cx, cy, dx, dy]) => {
                    ctx.beginPath();
                    ctx.moveTo(cx, cy + dy * tick);
                    ctx.lineTo(cx, cy);
                    ctx.lineTo(cx + dx * tick, cy);
                    ctx.stroke();
                });

                // Centroid dot and stem ("skeleton" anchor for the track).
                const cxp = proj.x(box.x + box.w / 2);
                const cyp = proj.y(box.y + box.h / 2);
                ctx.fillStyle = `rgba(${accent}, 0.95)`;
                ctx.beginPath();
                ctx.arc(cxp, cyp, 2.2, 0, Math.PI * 2);
                ctx.fill();
                ctx.beginPath();
                ctx.moveTo(cxp, y + h);
                ctx.lineTo(cxp, cyp);
                ctx.globalAlpha = 0.45;
                ctx.strokeStyle = `rgba(${accent}, 0.8)`;
                ctx.lineWidth = 1;
                ctx.stroke();
                ctx.globalAlpha = 1;

                // Label plate above the box.
                const label = `PERSON ${confidence}%`;
                const idLabel = `#ID-${String(box.id).padStart(2, '0')}`;
                ctx.font = '600 9px ui-monospace, SFMono-Regular, Menlo, monospace';
                const labelWidth = ctx.measureText(label).width + 8;
                const idWidth = ctx.measureText(idLabel).width + 6;
                const plateY = Math.max(0, y - 13);

                ctx.fillStyle = `rgba(${accent}, 0.92)`;
                ctx.fillRect(x, plateY, labelWidth, 12);
                ctx.fillStyle = '#04110d';
                ctx.fillText(label, x + 4, plateY + 9);

                ctx.fillStyle = 'rgba(6, 10, 13, 0.82)';
                ctx.fillRect(x + labelWidth + 2, plateY, idWidth, 12);
                ctx.fillStyle = `rgba(${accent}, 1)`;
                ctx.fillText(idLabel, x + labelWidth + 5, plateY + 9);
            });
        }

        function drawTripwire(proj) {
            const segment = state.config.tripwire;
            if (!segment || (state.config.mode !== 'tripwire' && state.config.mode !== 'both')) return;

            const ctx = el.ctx;
            const ax = proj.x(segment.a.x);
            const ay = proj.y(segment.a.y);
            const bx = proj.x(segment.b.x);
            const by = proj.y(segment.b.y);

            ctx.save();
            ctx.strokeStyle = 'rgba(95, 227, 192, 0.95)';
            ctx.lineWidth = 2;
            ctx.setLineDash([7, 5]);
            ctx.beginPath();
            ctx.moveTo(ax, ay);
            ctx.lineTo(bx, by);
            ctx.stroke();
            ctx.setLineDash([]);

            const mx = (ax + bx) / 2;
            const my = (ay + by) / 2;
            let nx = -(by - ay);
            let ny = bx - ax;
            const length = Math.hypot(nx, ny) || 1;
            nx /= length;
            ny /= length;
            if (state.config.flip) {
                nx *= -1;
                ny *= -1;
            }

            arrow(ctx, mx, my, nx, ny, 22);
            arrow(ctx, mx, my, -nx, -ny, 22);

            ctx.font = '600 9px ui-monospace, monospace';
            ctx.fillStyle = 'rgba(95, 227, 192, 0.95)';
            ctx.fillText('IN', mx + nx * 30 - 5, my + ny * 30 + 3);
            ctx.fillStyle = 'rgba(255, 125, 107, 0.95)';
            ctx.fillText('OUT', mx - nx * 34 - 6, my - ny * 34 + 3);

            [ [ax, ay], [bx, by] ].forEach(([px, py]) => {
                ctx.fillStyle = '#04070a';
                ctx.strokeStyle = 'rgba(95, 227, 192, 0.95)';
                ctx.lineWidth = 2;
                ctx.beginPath();
                ctx.arc(px, py, 4, 0, Math.PI * 2);
                ctx.fill();
                ctx.stroke();
            });
            ctx.restore();
        }

        function arrow(ctx, x, y, dx, dy, size) {
            ctx.save();
            ctx.strokeStyle = 'rgba(95, 227, 192, 0.9)';
            ctx.fillStyle = 'rgba(95, 227, 192, 0.9)';
            ctx.lineWidth = 1.6;
            ctx.beginPath();
            ctx.moveTo(x, y);
            ctx.lineTo(x + dx * size, y + dy * size);
            ctx.stroke();
            ctx.beginPath();
            ctx.moveTo(x + dx * size, y + dy * size);
            ctx.lineTo(x + dx * (size - 5) - dy * 4, y + dy * (size - 5) + dx * 4);
            ctx.lineTo(x + dx * (size - 5) + dy * 4, y + dy * (size - 5) - dx * 4);
            ctx.closePath();
            ctx.fill();
            ctx.restore();
        }

        function drawZone(proj) {
            const zone = state.config.zone;
            if (!zone || (state.config.mode !== 'zone' && state.config.mode !== 'both')) return;

            const ctx = el.ctx;
            const x = proj.x(zone.x);
            const y = proj.y(zone.y);
            const w = proj.w(zone.w);
            const h = proj.h(zone.h);
            const exceeded = state.counts.inside > state.config.zone_threshold;

            ctx.save();
            ctx.fillStyle = exceeded ? 'rgba(255, 125, 107, 0.14)' : 'rgba(95, 227, 192, 0.1)';
            ctx.strokeStyle = exceeded ? 'rgba(255, 125, 107, 0.95)' : 'rgba(95, 227, 192, 0.85)';
            ctx.lineWidth = 1.8;
            ctx.setLineDash([6, 4]);
            ctx.fillRect(x, y, w, h);
            ctx.strokeRect(x, y, w, h);
            ctx.setLineDash([]);

            ctx.font = '600 9px ui-monospace, monospace';
            const label = `ZONE ${state.counts.inside}/${state.config.zone_threshold}`;
            const plateWidth = ctx.measureText(label).width + 8;
            ctx.fillStyle = exceeded ? 'rgba(255, 125, 107, 0.9)' : 'rgba(95, 227, 192, 0.9)';
            ctx.fillRect(x, Math.max(0, y - 13), plateWidth, 12);
            ctx.fillStyle = '#04110d';
            ctx.fillText(label, x + 4, Math.max(9, y - 4));
            ctx.restore();
        }

        function drawDraft(proj) {
            const ctx = el.ctx;
            ctx.save();
            ctx.strokeStyle = 'rgba(240, 196, 99, 0.95)';
            ctx.lineWidth = 1.8;
            ctx.setLineDash([5, 4]);

            if (state.drawMode === 'tripwire' && state.tempA) {
                const a = state.tempA;
                const b = state.draft || a;
                ctx.beginPath();
                ctx.moveTo(proj.x(a.x), proj.y(a.y));
                ctx.lineTo(proj.x(b.x), proj.y(b.y));
                ctx.stroke();
            }

            if (state.drawMode === 'zone' && state.draft) {
                const rect = normaliseRect(state.tempA, state.draft);
                ctx.strokeRect(proj.x(rect.x), proj.y(rect.y), proj.w(rect.w), proj.h(rect.h));
            }

            ctx.restore();
        }

        function updateHud() {
            if (el.in) el.in.textContent = String(state.counts.in);
            if (el.out) el.out.textContent = String(state.counts.out);
            if (el.inside) el.inside.textContent = String(state.counts.inside);
            if (el.backend) {
                el.backend.textContent = state.backend === 'coco-ssd'
                    ? `COCO-SSD ${state.inferenceMs ? state.inferenceMs + ' ms' : ''}`.trim()
                    : 'simulated';
            }
        }

        /* --------------------------------------------------- interactions */
        el.counts.addEventListener('click', (event) => {
            event.stopPropagation();
            panel.hidden = false;
        });

        host.querySelector('[data-dt-open]')?.addEventListener('click', (event) => {
            event.stopPropagation();
            panel.hidden = !panel.hidden;
        });

        panel.querySelector('[data-dt-close]')?.addEventListener('click', (event) => {
            event.stopPropagation();
            panel.hidden = true;
        });

        panel.addEventListener('click', (event) => event.stopPropagation());
        panel.addEventListener('pointerdown', (event) => event.stopPropagation());

        panel.querySelector('[data-dt-enabled]')?.addEventListener('change', (event) => {
            state.config.enabled = event.target.checked;
            persist();
        });

        panel.querySelector('[data-dt-confidence]')?.addEventListener('input', (event) => {
            state.config.confidence = clamp(Number(event.target.value) / 100, 0.3, 0.95);
            if (el.conf) el.conf.textContent = `${event.target.value}%`;
            persist();
        });

        panel.querySelector('[data-dt-boxes]')?.addEventListener('change', (event) => {
            state.config.boxes = event.target.checked;
            persist();
        });

        panel.querySelector('[data-dt-threshold]')?.addEventListener('change', (event) => {
            state.config.zone_threshold = clamp(Number(event.target.value) || 5, 1, 999);
            persist();
        });

        panel.querySelector('[data-dt-flip]')?.addEventListener('click', () => {
            state.config.flip = !state.config.flip;
            persist();
        });

        panel.querySelector('[data-dt-clear]')?.addEventListener('click', () => {
            state.config.tripwire = null;
            state.config.zone = null;
            state.config.mode = 'off';
            state.config.enabled = false;
            const enabled = panel.querySelector('[data-dt-enabled]');
            if (enabled) enabled.checked = false;
            state.counts.in = 0;
            state.counts.out = 0;
            state.alarm = false;
            persist();
        });

        panel.querySelectorAll('[data-dt-draw]').forEach((button) => {
            button.addEventListener('click', () => beginDraw(button.dataset.dtDraw));
        });

        function beginDraw(mode) {
            if (!state.canManage) return;
            state.drawMode = mode;
            state.tempA = null;
            state.draft = null;
            overlay.classList.add('is-drawing');
            if (el.hint) {
                el.hint.textContent = mode === 'tripwire'
                    ? 'Click two points on the video to place the tripwire.'
                    : 'Drag a box on the video to define the occupancy zone.';
            }
            panel.hidden = true;
        }

        function endDraw() {
            state.drawMode = null;
            state.tempA = null;
            state.draft = null;
            overlay.classList.remove('is-drawing');
            if (el.hint) el.hint.textContent = 'Draw a line for a tripwire or a box for zone occupancy.';
        }

        function normalisePoint(event) {
            const proj = projector(viewport);
            if (!proj) return null;
            return proj.fromClient(event.clientX, event.clientY, overlay.getBoundingClientRect());
        }

        overlay.addEventListener('pointerdown', (event) => {
            if (!state.drawMode) return;
            event.preventDefault();
            event.stopPropagation();

            const point = normalisePoint(event);
            if (!point) return;

            if (state.drawMode === 'tripwire') {
                if (!state.tempA) {
                    state.tempA = point;
                    state.draft = point;
                } else {
                    state.config.tripwire = { a: state.tempA, b: point };
                    state.config.mode = state.config.zone ? 'both' : 'tripwire';
                    state.config.enabled = true;
                    const enabled = panel.querySelector('[data-dt-enabled]');
                    if (enabled) enabled.checked = true;
                    state.counts.in = 0;
                    state.counts.out = 0;
                    state.previous = new Map();
                    endDraw();
                    persist();
                }
                return;
            }

            state.tempA = point;
            state.draft = point;
            overlay.setPointerCapture?.(event.pointerId);
        });

        overlay.addEventListener('pointermove', (event) => {
            if (!state.drawMode) return;
            const point = normalisePoint(event);
            if (!point) return;

            if (state.drawMode === 'zone' && state.tempA) {
                state.draft = point;
            } else if (state.drawMode === 'tripwire' && state.tempA) {
                state.draft = point;
            }
        });

        overlay.addEventListener('pointerup', (event) => {
            if (!state.drawMode || state.drawMode !== 'zone' || !state.tempA || !state.draft) return;
            event.stopPropagation();

            const rect = normaliseRect(state.tempA, state.draft);
            if (rect.w < 0.04 || rect.h < 0.04) {
                endDraw();
                return;
            }

            state.config.zone = rect;
            state.config.mode = state.config.tripwire ? 'both' : 'zone';
            state.config.enabled = true;
            const enabled = panel.querySelector('[data-dt-enabled]');
            if (enabled) enabled.checked = true;
            state.alarm = false;
            endDraw();
            persist();
        });

        window.addEventListener('keydown', onKey);

        function onKey(event) {
            if (event.key === 'Escape' && state.drawMode) endDraw();
        }

        /* ------------------------------------------------- persistence/sync */
        let persistTimer = 0;
        let lastConfigPayload = '';

        function persist() {
            // The worker idles when detection is off, even for viewers.
            worker.postMessage({ type: 'active', active: state.config.enabled });

            if (!state.canManage) return;

            const payload = JSON.stringify(state.config);
            if (payload === lastConfigPayload) return;
            lastConfigPayload = payload;

            window.clearTimeout(persistTimer);
            persistTimer = window.setTimeout(async () => {
                try {
                    await Argus.postJson(`${baseUrl}api/analytics.php`, {
                        camera_id: Number(camera.id),
                        config: state.config,
                        events: [],
                    });
                } catch (error) {
                    Argus.toast?.(error.message || 'Could not save detection settings.', 'error');
                }
            }, 500);
        }

        const syncTimer = window.setInterval(flush, SYNC_INTERVAL);

        async function flush(force = false) {
            if (!state.running) return;

            const now = Date.now();
            const events = state.events.splice(0, 200);

            // Occupancy is sampled every 30 s even without crossings, so the
            // analytics panel has a trend rather than only events.
            if (events.length === 0 && (force || now - state.lastOccupancy > OCCUPANCY_INTERVAL)) {
                events.push({
                    direction: null,
                    occupancy: state.counts.inside,
                    confidence: state.config.confidence,
                    occurred_at: new Date().toISOString(),
                });
                state.lastOccupancy = now;
            }

            if (events.length === 0) return;

            try {
                await Argus.postJson(`${baseUrl}api/analytics.php`, {
                    camera_id: Number(camera.id),
                    events,
                });
            } catch (error) {
                // Put the samples back once, so a transient failure is not data loss.
                if (state.events.length < 200) state.events.unshift(...events);
            }
        }

        raf = requestAnimationFrame(tick);

        const instance = {
            key,
            config: state.config,
            counts: state.counts,
            detach() {
                state.running = false;
                cancelAnimationFrame(raf);
                window.clearInterval(captureTimer);
                window.clearInterval(syncTimer);
                window.clearTimeout(persistTimer);
                window.removeEventListener('keydown', onKey);
                worker.terminate();
                overlay.remove();
                counts.remove();
                cluster.remove();
                panel.remove();
                instances.delete(key);
            },
        };

        instances.set(key, instance);
        return instance;
    }

    function detach(key) {
        const instance = instances.get(String(key));
        if (instance) instance.detach();
    }

    function detachAll() {
        // Modal overlays survive a wall re-render; only tile instances are swept.
        Array.from(instances.keys())
            .filter((key) => !key.startsWith('modal-'))
            .forEach(detach);
    }

    function centre(box) {
        return { x: box.x + box.w / 2, y: box.y + box.h / 2 };
    }

    function normaliseRect(a, b) {
        const x = Math.min(a.x, b.x);
        const y = Math.min(a.y, b.y);
        return {
            x,
            y,
            w: Math.max(0, Math.abs(b.x - a.x)),
            h: Math.max(0, Math.abs(b.y - a.y)),
        };
    }

    Argus.detector = { attach, detach, detachAll };
})();
