/*
 * Argus — person-detection worker.
 *
 * Inference never blocks the wall: this worker receives frames at 5–10 FPS,
 * assigns tracking ids and posts normalised boxes back. The main thread draws
 * the overlay at 30 FPS.
 *
 * Backend is chosen by PHP:
 *   coco-ssd  — assets/vendor/tf.min.js + assets/vendor/coco-ssd.min.js
 *   simulate  — deterministic pedestrian targets (demo cameras and installs
 *               without the model), so counting works out of the box without
 *               shipping multi-megabyte weights.
 */
'use strict';

const DEFAULTS = { intervalMs: 166, maxBoxes: 8 };

const state = {
    backend: 'simulate',
    cameraId: 0,
    intervalMs: DEFAULTS.intervalMs,
    model: null,
    coco: null,
    walkers: [],
    tracks: new Map(),
    nextId: 1,
    rng: Math.random,
    lastTick: 0,
    inferTimes: [],
    frames: 0,
    lastPost: 0,
    active: false,
};

self.onmessage = async (event) => {
    const message = event.data || {};

    if (message.type === 'init') {
        state.backend = message.backend === 'coco-ssd' ? 'coco-ssd' : 'simulate';
        state.cameraId = Number(message.cameraId) || 0;
        state.intervalMs = Math.max(100, Math.min(200, Number(message.intervalMs) || DEFAULTS.intervalMs));
        state.rng = mulberry32((Number(message.seed) || 1) + state.cameraId * 7919);
        state.lastTick = 0;
        state.walkers = [];
        state.active = Boolean(message.active);

        if (state.backend === 'coco-ssd' && !(await loadModel())) {
            state.backend = 'simulate';
        }

        if (state.backend === 'simulate') {
            seedWalkers();
        }

        self.postMessage({ type: 'ready', backend: state.backend });
        return;
    }

    if (message.type === 'frame' && state.backend === 'coco-ssd' && state.active) {
        await inferFrame(message.frame, Number(message.width) || 0, Number(message.height) || 0);
        return;
    }

    if (message.type === 'active') {
        // Detection off: stop spending CPU and stop posting boxes.
        state.active = Boolean(message.active);
        if (!state.active) {
            self.postMessage({ type: 'detections', backend: state.backend, boxes: [], inferenceMs: 0, at: Date.now() });
        }
        return;
    }

    if (message.type === 'stop') {
        self.close();
    }
};

/* ------------------------------------------------------------ COCO-SSD */

async function loadModel() {
    try {
        // Local vendored copies only: the wall never phones home for a model.
        importScripts('../vendor/tf.min.js', '../vendor/coco-ssd.min.js');

        if (typeof cocoSsd === 'undefined' || typeof tf === 'undefined') {
            return false;
        }

        // wasm/webgl inside a worker: use the CPU backend when WebGL is absent.
        if (tf.getBackend && !tf.getBackend()) {
            await tf.setBackend('cpu');
        }
        if (tf.ready) {
            await tf.ready();
        }

        state.coco = cocoSsd;
        state.model = await cocoSsd.load({ base: 'lite_mobilenet_v2' });

        return Boolean(state.model);
    } catch (error) {
        return false;
    }
}

async function inferFrame(bitmap, width, height) {
    if (!bitmap) return;

    try {
        if (!state.model) return;

        const canvas = new OffscreenCanvas(320, Math.max(1, Math.round((height / Math.max(1, width)) * 320)));
        const ctx = canvas.getContext('2d');
        ctx.drawImage(bitmap, 0, 0, canvas.width, canvas.height);

        const started = performance.now();
        const raw = await state.model.detect(canvas, DEFAULTS.maxBoxes, 0.25);
        const elapsed = performance.now() - started;

        const detections = raw
            .filter((item) => item.class === 'person' && item.score >= 0.25)
            .map((item) => ({
                x: item.bbox[0] / canvas.width,
                y: item.bbox[1] / canvas.height,
                w: item.bbox[2] / canvas.width,
                h: item.bbox[3] / canvas.height,
                confidence: item.score,
            }));

        publish(track(detections), elapsed);
    } catch (error) {
        // A dropped frame must not stop the loop.
    } finally {
        if (bitmap && typeof bitmap.close === 'function') bitmap.close();
    }
}

/* ----------------------------------------------------------- simulation */

function seedWalkers() {
    const count = 3 + Math.floor(state.rng() * 4);

    for (let i = 0; i < count; i += 1) {
        const down = state.rng() > 0.5;
        state.walkers.push({
            id: 0,
            x: 0.08 + state.rng() * 0.84,
            y: state.rng(),
            vx: (state.rng() - 0.5) * 0.035,
            vy: (down ? 1 : -1) * (0.045 + state.rng() * 0.05),
            w: 0.045 + state.rng() * 0.03,
            h: 0.14 + state.rng() * 0.08,
            confidence: 0.64 + state.rng() * 0.3,
            wobble: state.rng() * Math.PI * 2,
        });
    }
}

function simulate(dt) {
    const detections = [];

    state.walkers.forEach((walker) => {
        walker.wobble += dt * 2.2;
        walker.x += (walker.vx + Math.sin(walker.wobble) * 0.012) * dt;
        walker.y += walker.vy * dt;

        // Re-enter from the opposite edge: repeated crossings keep the
        // tripwire counter moving instead of settling at one event.
        if (walker.y > 1.08) {
            walker.y = -0.08;
            walker.x = 0.08 + state.rng() * 0.84;
            walker.confidence = 0.64 + state.rng() * 0.3;
        } else if (walker.y < -0.08) {
            walker.y = 1.08;
            walker.x = 0.08 + state.rng() * 0.84;
            walker.confidence = 0.64 + state.rng() * 0.3;
        }

        if (walker.x < 0.04 || walker.x > 0.9) {
            walker.vx *= -1;
            walker.x = Math.max(0.04, Math.min(0.9, walker.x));
        }

        detections.push({
            id: walker.id,
            x: walker.x - walker.w / 2,
            y: walker.y - walker.h / 2,
            w: walker.w,
            h: walker.h,
            confidence: walker.confidence,
        });
    });

    return detections.map((box) => ({ ...box, id: 0 }));
}

/* -------------------------------------------------------------- tracking */

function iou(a, b) {
    const x1 = Math.max(a.x, b.x);
    const y1 = Math.max(a.y, b.y);
    const x2 = Math.min(a.x + a.w, b.x + b.w);
    const y2 = Math.min(a.y + a.h, b.y + b.h);
    const intersection = Math.max(0, x2 - x1) * Math.max(0, y2 - y1);
    const union = a.w * a.h + b.w * b.h - intersection;

    return union > 0 ? intersection / union : 0;
}

function track(detections) {
    const now = performance.now();
    const used = new Set();

    const tracked = detections.map((detection) => {
        let best = null;
        let bestScore = 0;

        state.tracks.forEach((candidate) => {
            if (used.has(candidate.id)) return;
            const score = iou(detection, candidate);
            if (score > bestScore) {
                bestScore = score;
                best = candidate;
            }
        });

        if (best && bestScore > 0.2) {
            used.add(best.id);
            best.x = detection.x;
            best.y = detection.y;
            best.w = detection.w;
            best.h = detection.h;
            best.confidence = detection.confidence;
            best.seen = now;

            return { ...detection, id: best.id };
        }

        const id = state.nextId++;
        const fresh = { ...detection, id, seen: now };
        state.tracks.set(id, fresh);

        return { ...detection, id };
    });

    // Forget tracks that have not been seen for 1.2 s.
    state.tracks.forEach((candidate, id) => {
        if (now - candidate.seen > 1200) state.tracks.delete(id);
    });

    return tracked;
}

/* --------------------------------------------------------------- output */

function publish(boxes, inferenceMs) {
    const now = performance.now();
    state.frames += 1;

    if (inferenceMs) {
        state.inferTimes.push(inferenceMs);
        if (state.inferTimes.length > 20) state.inferTimes.shift();
    }

    // Never flood the main thread: at most one message per inference interval.
    if (now - state.lastPost < state.intervalMs * 0.8) return;
    state.lastPost = now;

    const mean = state.inferTimes.length
        ? state.inferTimes.reduce((sum, value) => sum + value, 0) / state.inferTimes.length
        : 0;

    self.postMessage({
        type: 'detections',
        backend: state.backend,
        boxes: boxes.slice(0, 24),
        inferenceMs: Math.round(mean * 10) / 10,
        at: Date.now(),
    });
}

/* Simulation heartbeat: advance walkers on the worker's own clock. */
setInterval(() => {
    if (state.backend !== 'simulate' || !state.active) return;

    const now = performance.now();
    const dt = state.lastTick === 0 ? 0.05 : Math.min(0.4, (now - state.lastTick) / 1000);
    state.lastTick = now;

    publish(track(simulate(dt)), 0);
}, DEFAULTS.intervalMs);

/** Seeded PRNG so the same camera produces repeatable demo traffic. */
function mulberry32(seed) {
    let a = seed >>> 0;

    return function random() {
        a |= 0;
        a = (a + 0x6d2b79f5) | 0;
        let t = Math.imul(a ^ (a >>> 15), 1 | a);
        t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}
