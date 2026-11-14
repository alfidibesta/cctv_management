/*
 * Argus — digital camera viewport.
 *
 * A canvas compositor that draws the camera frame (an <img> MJPEG/snapshot
 * source or a <video> HLS/WebRTC source) at a locked 30 FPS, and applies a
 * purely digital zoom (1.0x – 8.0x), cursor-centred wheel zoom, drag panning
 * and a corner picture-in-picture mini-map.
 *
 * The camera hardware is never touched: panning and zooming happen here, in
 * the browser. Delayed frames are dropped instead of queued, so the picture
 * stays live.
 */
(() => {
    'use strict';

    const TARGET_FPS = 30;
    const FRAME_INTERVAL = 1000 / TARGET_FPS;
    const Argus = (window.Argus = window.Argus || {});

    const clamp = (value, min, max) => Math.min(max, Math.max(min, value));

    /**
     * @param {HTMLElement} host  element that becomes the viewport
     * @param {object} options    { latencyTarget, hud, pip, minZoom, maxZoom }
     */
    function create(host, options = {}) {
        if (!host) return null;

        const settings = Object.assign(
            {
                latencyTarget: 300,
                minZoom: 1,
                maxZoom: 8,
                hud: true,
                pip: true,
                fps: TARGET_FPS,
            },
            options
        );

        host.classList.add('vp');
        host.innerHTML = `
            <canvas class="vp__canvas" data-vp-canvas></canvas>
            <div class="vp__source" data-vp-source></div>
            <div class="vp__empty" data-vp-empty>waiting for signal</div>
            <div class="vp__hud" data-vp-hud ${settings.hud ? '' : 'hidden'}>
                <span class="vp__hud-item"><b data-vp-lat>—</b><i>ms</i></span>
                <span class="vp__hud-item"><b data-vp-jit>—</b><i>jitter</i></span>
                <span class="vp__hud-item"><b data-vp-buf>—</b><i>buffer</i></span>
                <span class="vp__hud-item"><b data-vp-fps>0.0</b><i>fps</i></span>
                <span class="vp__hud-item"><b data-vp-ft>—</b><i>ms</i></span>
            </div>
            <canvas class="vp__pip" data-vp-pip width="352" height="198" ${settings.pip ? '' : 'hidden'}></canvas>
            <div class="vp__bar" data-vp-bar>
                <button type="button" class="vp__bar-btn" data-vp-out aria-label="Zoom out">−</button>
                <input class="vp__slider" type="range" min="1" max="8" step="0.1" value="1"
                       aria-label="Digital zoom" data-vp-slider>
                <button type="button" class="vp__bar-btn" data-vp-in aria-label="Zoom in">+</button>
                <span class="vp__level" data-vp-level>1.0x</span>
                <button type="button" class="vp__bar-btn vp__bar-btn--text" data-vp-reset title="Reset digital zoom">Reset 1.0x</button>
            </div>`;

        const els = {
            canvas: host.querySelector('[data-vp-canvas]'),
            stage: host,
            source: host.querySelector('[data-vp-source]'),
            empty: host.querySelector('[data-vp-empty]'),
            lat: host.querySelector('[data-vp-lat]'),
            jit: host.querySelector('[data-vp-jit]'),
            buf: host.querySelector('[data-vp-buf]'),
            fps: host.querySelector('[data-vp-fps]'),
            ft: host.querySelector('[data-vp-ft]'),
            pip: host.querySelector('[data-vp-pip]'),
            slider: host.querySelector('[data-vp-slider]'),
            level: host.querySelector('[data-vp-level]'),
        };

        const ctx = els.canvas.getContext('2d', {
            alpha: false,
            // Hardware-accelerated compositing: no pixel readback in the loop.
            willReadFrequently: false,
            desynchronized: true,
        });

        const pipCtx = els.pip ? els.pip.getContext('2d', { alpha: false }) : null;

        const state = {
            source: null,
            kind: 'image',
            zoom: settings.minZoom,
            cx: 0.5,
            cy: 0.5,
            width: 0,
            height: 0,
            dpr: Math.min(window.devicePixelRatio || 1, 2),
            nightVision: false,
            running: false,
            raf: 0,
            lastTs: 0,
            accumulator: 0,
            draws: 0,
            frameTime: 16.7,
            jitter: 0,
            fps: 0,
            latencyTarget: Number(settings.latencyTarget) || 300,
            measureLatency: null,
            lastHudUpdate: 0,
            dirtyPip: true,
            deltas: [],
            drawTimes: [],
            lastDrawTs: 0,
            drag: null,
            sourceReady: false,
            patternAt: 0,
            testPattern: false,
            destroyed: false,
        };

        els.slider.max = String(settings.maxZoom);
        els.slider.min = String(settings.minZoom);

        /* ------------------------------------------------------- sizing */
        const resize = () => {
            const rect = host.getBoundingClientRect();
            state.width = Math.max(1, Math.round(rect.width));
            state.height = Math.max(1, Math.round(rect.height));
            state.dpr = Math.min(window.devicePixelRatio || 1, 2);

            els.canvas.width = Math.round(state.width * state.dpr);
            els.canvas.height = Math.round(state.height * state.dpr);

            if (ctx) ctx.setTransform(state.dpr, 0, 0, state.dpr, 0, 0);
            state.dirtyPip = true;
        };

        const observer = 'ResizeObserver' in window ? new ResizeObserver(resize) : null;
        observer?.observe(host);
        resize();

        /* ------------------------------------------------------ geometry */
        function sourceSize() {
            const source = state.source;
            if (!source) return { w: 0, h: 0 };

            if (state.kind === 'video') {
                return { w: source.videoWidth || 0, h: source.videoHeight || 0 };
            }

            return { w: source.naturalWidth || 0, h: source.naturalHeight || 0 };
        }

        function geometry() {
            const { w: sw, h: sh } = sourceSize();
            const W = state.width;
            const H = state.height;

            if (!sw || !sh || !W || !H) return null;

            // Cover the viewport: no letterboxing, the overflow is what panning reveals.
            const base = Math.max(W / sw, H / sh);
            const scale = base * state.zoom;

            return { sw, sh, W, H, scale };
        }

        function clampCentre(frame) {
            const { sw, sh, W, H, scale } = frame;
            const halfX = W / (2 * scale * sw);
            const halfY = H / (2 * scale * sh);

            state.cx = clamp(state.cx, halfX, Math.max(halfX, 1 - halfX));
            state.cy = clamp(state.cy, halfY, Math.max(halfY, 1 - halfY));
        }

        /* ---------------------------------------------------------- zoom */
        function setZoom(value, anchor = null) {
            const previous = state.zoom;
            const next = clamp(Number(value) || settings.minZoom, settings.minZoom, settings.maxZoom);
            const frame = geometry();

            if (frame && anchor && previous > 0) {
                // Keep the point under the cursor stationary while zooming.
                const scaleBefore = frame.scale;
                const dx = state.width / 2 - state.cx * frame.sw * scaleBefore;
                const dy = state.height / 2 - state.cy * frame.sh * scaleBefore;
                const sx = (anchor.x - dx) / (frame.sw * scaleBefore);
                const sy = (anchor.y - dy) / (frame.sh * scaleBefore);

                const scaleAfter = Math.max(frame.W / frame.sw, frame.H / frame.sh) * next;
                state.cx = (state.width / 2 - (anchor.x - sx * frame.sw * scaleAfter)) / (frame.sw * scaleAfter);
                state.cy = (state.height / 2 - (anchor.y - sy * frame.sh * scaleAfter)) / (frame.sh * scaleAfter);
            }

            state.zoom = next;

            const zoomed = geometry();
            if (zoomed) clampCentre(zoomed);

            if (state.zoom <= settings.minZoom + 0.001) {
                // Fully zoomed out: recentre so the frame is never left off-axis.
                state.cx = 0.5;
                state.cy = 0.5;
            }

            els.slider.value = state.zoom.toFixed(1);
            els.level.textContent = `${state.zoom.toFixed(1)}x`;
            host.classList.toggle('is-zoomed', state.zoom > settings.minZoom + 0.001);
            state.dirtyPip = true;
            Argus.emit?.('viewport:zoom', { host, zoom: state.zoom });
        }

        function reset() {
            state.cx = 0.5;
            state.cy = 0.5;
            setZoom(settings.minZoom);
        }

        const zoomBy = (factor, anchor) => setZoom(state.zoom * factor, anchor);

        /* ----------------------------------------------------- test card */
        function drawTestPattern(W, H, now) {
            const t = now / 1000;
            const bars = ['#c0c0c0', '#c0c000', '#00c0c0', '#00c000', '#c000c0', '#c00000', '#0000c0'];
            const barWidth = W / bars.length;

            ctx.fillStyle = '#05090c';
            ctx.fillRect(0, 0, W, H);

            bars.forEach((colour, index) => {
                ctx.fillStyle = colour;
                ctx.fillRect(index * barWidth, 0, barWidth + 1, H * 0.62);
            });

            const sweep = ((t * 90) % (W + 120)) - 60;
            ctx.fillStyle = 'rgba(0,0,0,0.5)';
            ctx.fillRect(0, H * 0.62, W, H * 0.38);
            ctx.fillStyle = 'rgba(95,227,192,0.9)';
            ctx.fillRect(sweep, H * 0.62, 3, H * 0.38);

            ctx.fillStyle = '#e8eef2';
            ctx.font = `600 ${Math.max(11, Math.round(H * 0.045))}px ui-monospace, SFMono-Regular, Menlo, monospace`;
            ctx.fillText('NO SIGNAL — TEST PATTERN', 14, H * 0.72);
            ctx.fillStyle = '#5f8f83';
            ctx.font = `500 ${Math.max(9, Math.round(H * 0.032))}px ui-monospace, monospace`;
            ctx.fillText(new Date().toISOString().replace('T', ' ').slice(0, 19) + ' UTC', 14, H * 0.80);
        }

        /* -------------------------------------------------------- render */
        function draw(now) {
            const W = state.width;
            const H = state.height;
            if (!W || !H || !ctx) return;

            const started = performance.now();
            const source = state.source;

            // Some MJPEG decoders never fire a second load event; a decoded
            // first frame is enough to treat the source as live.
            if (source && !state.sourceReady) {
                const ready = state.kind === 'video' ? source.videoWidth > 0 : source.naturalWidth > 0;
                if (ready) {
                    state.sourceReady = true;
                    if (els.empty) els.empty.hidden = true;
                }
            }

            const frame = geometry();

            ctx.globalCompositeOperation = 'source-over';
            ctx.filter = 'none';
            ctx.fillStyle = '#04070a';
            ctx.fillRect(0, 0, W, H);

            if (state.testPattern || !frame || !source || !state.sourceReady) {
                if (!state.sourceReady && state.patternAt === 0) state.patternAt = now;

                // Give a slow camera a moment before showing the fallback card.
                if (state.testPattern || !source || (state.patternAt > 0 && now - state.patternAt > 900)) {
                    drawTestPattern(W, H, now);
                }
            } else {
                const { sw, sh, scale } = frame;
                const dw = sw * scale;
                const dh = sh * scale;
                const dx = W / 2 - state.cx * dw;
                const dy = H / 2 - state.cy * dh;

                try {
                    if (state.nightVision) {
                        ctx.filter = 'grayscale(1) contrast(1.15) brightness(1.12)';
                    }

                    ctx.drawImage(source, dx, dy, dw, dh);

                    if (state.nightVision) {
                        ctx.filter = 'none';
                        ctx.globalCompositeOperation = 'screen';
                        ctx.fillStyle = 'rgba(28, 140, 78, 0.38)';
                        ctx.fillRect(0, 0, W, H);
                        ctx.globalCompositeOperation = 'source-over';
                    }
                } catch (error) {
                    // A not-yet-decodable frame: keep the previous one on screen.
                }

                if (els.empty) els.empty.hidden = true;
            }

            if (frame) {
                // Projection shared with the detection overlay so boxes stay
                // glued to the video while zooming and panning.
                state.projection = {
                    sw: frame.sw,
                    sh: frame.sh,
                    scale: frame.scale,
                    dx: W / 2 - state.cx * frame.sw * frame.scale,
                    dy: H / 2 - state.cy * frame.sh * frame.scale,
                    W,
                    H,
                    zoom: state.zoom,
                };
            } else {
                state.projection = null;
            }

            if (state.pip && pipCtx && els.pip && !els.pip.hidden) {
                drawPip(frame, now);
            }

            const elapsed = performance.now() - started;
            state.frameTime = state.frameTime * 0.9 + elapsed * 0.1;
        }

        function drawPip(frame, now) {
            const pw = els.pip.width;
            const ph = els.pip.height;

            pipCtx.fillStyle = '#04070a';
            pipCtx.fillRect(0, 0, pw, ph);

            if (!frame || !state.source || !state.sourceReady) {
                drawPipPlaceholder(pw, ph);
                return;
            }

            const { sw, sh } = frame;
            const cover = Math.max(pw / sw, ph / sh);
            const px = (pw - sw * cover) / 2;
            const py = (ph - sh * cover) / 2;

            pipCtx.drawImage(state.source, px, py, sw * cover, sh * cover);
            pipCtx.fillStyle = 'rgba(2, 5, 8, 0.45)';
            pipCtx.fillRect(0, 0, pw, ph);

            const halfX = state.width / (2 * frame.scale * sw);
            const halfY = state.height / (2 * frame.scale * sh);

            const rx = px + (state.cx - halfX) * sw * cover;
            const ry = py + (state.cy - halfY) * sh * cover;
            const rw = Math.min(pw, halfX * 2 * sw * cover);
            const rh = Math.min(ph, halfY * 2 * sh * cover);

            pipCtx.strokeStyle = 'rgba(95, 227, 192, 0.95)';
            pipCtx.lineWidth = 3;
            pipCtx.strokeRect(rx, ry, rw, rh);
            pipCtx.fillStyle = 'rgba(95, 227, 192, 0.12)';
            pipCtx.fillRect(rx, ry, rw, rh);
        }

        function drawPipPlaceholder(pw, ph) {
            pipCtx.strokeStyle = 'rgba(255,255,255,0.12)';
            pipCtx.lineWidth = 2;
            pipCtx.strokeRect(4, 4, pw - 8, ph - 8);
        }

        /* ------------------------------------------------------ scheduler */
        function tick(now) {
            if (!state.running) return;
            state.raf = requestAnimationFrame(tick);

            const previous = state.lastTs || now;
            const delta = Math.min(250, now - previous);
            state.lastTs = now;

            // Frame pacing: only draw when a full 33.33 ms interval elapsed.
            state.accumulator += delta;
            if (state.accumulator < FRAME_INTERVAL) return;

            // Drop delayed frames instead of building a backlog: keep the rest
            // of the accumulator, discard the excess.
            state.accumulator = state.accumulator % FRAME_INTERVAL;

            if (state.lastDrawTs > 0) {
                state.deltas.push(now - state.lastDrawTs);
                if (state.deltas.length > 30) state.deltas.shift();
            }
            state.lastDrawTs = now;

            draw(now);

            state.drawTimes.push(now);
            while (state.drawTimes.length > 0 && state.drawTimes[0] < now - 1200) {
                state.drawTimes.shift();
            }

            if (now - state.lastHudUpdate >= 250) {
                updateTelemetry(now);
                state.lastHudUpdate = now;
            }
        }

        function updateTelemetry(now) {
            if (state.deltas.length > 3) {
                const mean = state.deltas.reduce((sum, value) => sum + value, 0) / state.deltas.length;
                const variance =
                    state.deltas.reduce((sum, value) => sum + (value - mean) ** 2, 0) / state.deltas.length;
                state.jitter = Math.sqrt(variance);
            }

            // Measured FPS from real draw timestamps, not a guess.
            const times = state.drawTimes;
            const span = times.length > 1 ? times[times.length - 1] - times[0] : 0;
            state.fps = span > 0 ? ((times.length - 1) * 1000) / span : 0;

            const latency = Math.round(
                (typeof state.measureLatency === 'number' ? state.measureLatency : state.latencyTarget) +
                    state.frameTime
            );

            // Buffer health: stable frame pacing and a cheap draw mean a healthy buffer.
            const health = clamp(
                Math.round(100 - state.jitter * 2.4 - Math.max(0, state.frameTime - 8) * 3.2),
                0,
                100
            );

            state.telemetry = {
                latency,
                jitter: Number(state.jitter.toFixed(1)),
                buffer: health,
                fps: Number(state.fps.toFixed(1)),
                frameTime: Number(state.frameTime.toFixed(1)),
                zoom: Number(state.zoom.toFixed(1)),
            };

            if (settings.hud) {
                els.lat.textContent = String(latency);
                els.jit.textContent = state.jitter.toFixed(1);
                els.buf.textContent = `${health}%`;
                els.buf.parentElement.dataset.health = health >= 75 ? 'good' : health >= 45 ? 'fair' : 'poor';
                els.fps.textContent = state.fps.toFixed(1);
                els.ft.textContent = state.frameTime.toFixed(1);
            }

            settings.onTelemetry?.(state.telemetry);
            Argus.emit?.('viewport:telemetry', { host, telemetry: state.telemetry });
        }

        /* ---------------------------------------------------- interactions */
        host.addEventListener(
            'wheel',
            (event) => {
                if (event.target.closest('.vp__bar, .vp__pip')) return;
                event.preventDefault();

                const rect = host.getBoundingClientRect();
                const anchor = { x: event.clientX - rect.left, y: event.clientY - rect.top };
                zoomBy(event.deltaY < 0 ? 1.15 : 1 / 1.15, anchor);
            },
            { passive: false }
        );

        host.addEventListener('pointerdown', (event) => {
            if (event.target.closest('.vp__bar, .vp__pip, button, input')) return;
            if (state.zoom <= settings.minZoom + 0.001) return;

            state.drag = { x: event.clientX, y: event.clientY, cx: state.cx, cy: state.cy };
            host.setPointerCapture?.(event.pointerId);
            host.classList.add('is-panning');
        });

        host.addEventListener('pointermove', (event) => {
            if (!state.drag) return;
            const frame = geometry();
            if (!frame) return;

            const scale = frame.scale;
            state.cx = state.drag.cx - (event.clientX - state.drag.x) / (frame.sw * scale);
            state.cy = state.drag.cy - (event.clientY - state.drag.y) / (frame.sh * scale);
            clampCentre(frame);
            state.dirtyPip = true;
        });

        const endDrag = () => {
            state.drag = null;
            host.classList.remove('is-panning');
        };
        host.addEventListener('pointerup', endDrag);
        host.addEventListener('pointercancel', endDrag);
        host.addEventListener('pointerleave', () => {
            if (state.drag) endDrag();
        });

        host.addEventListener('dblclick', (event) => {
            if (event.target.closest('.vp__bar, .vp__pip')) return;
            reset();
        });

        host.querySelector('[data-vp-in]')?.addEventListener('click', () => zoomBy(1.5));
        host.querySelector('[data-vp-out]')?.addEventListener('click', () => zoomBy(1 / 1.5));
        host.querySelector('[data-vp-reset]')?.addEventListener('click', reset);
        els.slider?.addEventListener('input', () => setZoom(Number(els.slider.value)));

        /* --------------------------------------------------------- sources */
        function setSource(element, kind = 'image') {
            if (!element) return;

            state.source = element;
            state.kind = kind === 'video' ? 'video' : 'image';
            state.sourceReady = false;
            state.patternAt = 0;

            els.source.replaceChildren(element);
            element.classList.add('vp__media');

            const markReady = () => {
                if (state.source !== element) return;
                state.sourceReady = true;
                state.patternAt = 0;
                if (els.empty) els.empty.hidden = true;
                state.dirtyPip = true;
            };
            const markLost = () => {
                if (state.source !== element) return;
                state.sourceReady = false;
                state.patternAt = 0;
            };

            if (state.kind === 'video') {
                if (element.readyState >= 2) markReady();
                element.addEventListener('loadeddata', markReady);
                element.addEventListener('playing', markReady);
                element.addEventListener('stalled', markLost);
            } else {
                if (element.complete && element.naturalWidth > 0) markReady();
                element.addEventListener('load', markReady);
                element.addEventListener('error', markLost);
            }
        }

        function start() {
            if (state.destroyed || state.running) return;
            state.running = true;
            state.lastTs = 0;
            state.accumulator = 0;
            state.deltas = [];
            state.raf = requestAnimationFrame(tick);
        }

        function stop() {
            state.running = false;
            cancelAnimationFrame(state.raf);
        }

        function setNightVision(enabled) {
            state.nightVision = Boolean(enabled);
            host.classList.toggle('is-night', state.nightVision);
        }

        function setLatencyTarget(ms) {
            const value = Number(ms);
            if (Number.isFinite(value) && value > 0) state.latencyTarget = value;
        }

        function setMeasuredLatency(ms) {
            state.measureLatency = Number.isFinite(Number(ms)) && Number(ms) > 0 ? Number(ms) : null;
        }

        function setPip(visible) {
            settings.pip = Boolean(visible);
            if (els.pip) els.pip.hidden = !settings.pip;
        }

        /** PNG of the current canvas, including zoom, pan and night vision. */
        function snapshot() {
            try {
                return els.canvas.toDataURL('image/png');
            } catch (error) {
                return null;
            }
        }

        function destroy() {
            state.destroyed = true;
            stop();
            observer?.disconnect();
            state.source = null;
            host.innerHTML = '';
            host.classList.remove('vp', 'is-zoomed', 'is-panning', 'is-night');
        }

        const viewport = {
            host,
            element: host,
            setSource,
            start,
            stop,
            destroy,
            setZoom,
            zoomBy,
            reset,
            setNightVision,
            setLatencyTarget,
            setMeasuredLatency,
            setPip,
            snapshot,
            get zoom() {
                return state.zoom;
            },
            get telemetry() {
                return state.telemetry || { latency: state.latencyTarget, jitter: 0, buffer: 100, fps: 0, frameTime: 0, zoom: 1 };
            },
            /** Current source→display mapping, null until a frame is drawn. */
            getProjection() {
                return state.projection || null;
            },
            /** The hidden <img>/<video> the canvas is drawing from. */
            getSourceElement() {
                return state.source;
            },
            showTestPattern(visible = true) {
                state.testPattern = Boolean(visible);
                state.patternAt = 0;
            },
        };

        start();
        return viewport;
    }

    Argus.viewport = { create };
    Argus.createViewport = create;
    Argus.emit = Argus.emit || function emit(name, detail) {
        window.dispatchEvent(new CustomEvent(name, { detail }));
    };
})();
