/* Argus — shared UI behaviour: clock, navigation, modals, forms, toasts. */
(() => {
    'use strict';

    const meta = document.querySelector('meta[name="csrf-token"]');
    const csrf = meta ? meta.content : '';
    const baseUrl = (path) => path;

    const Argus = (window.Argus = window.Argus || {});
    Argus.csrf = csrf;

    Argus.post = async (url, payload = {}) => {
        const body = new URLSearchParams();
        Object.entries(payload).forEach(([key, value]) => {
            if (Array.isArray(value)) {
                value.forEach((item) => body.append(`${key}[]`, item));
            } else if (value !== undefined && value !== null) {
                body.append(key, value);
            }
        });

        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                'X-CSRF-Token': csrf,
                'X-Requested-With': 'XMLHttpRequest',
                Accept: 'application/json',
            },
            body,
        });

        const data = await response.json().catch(() => ({ error: 'Unexpected server response.' }));
        if (!response.ok) {
            const error = new Error(data.error || `Request failed (${response.status})`);
            error.payload = data;
            error.status = response.status;
            throw error;
        }

        return data;
    };

    /** JSON POST, used for SDP offers and other structured payloads. */
    Argus.postJson = async (url, payload = {}) => {
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json;charset=UTF-8',
                'X-CSRF-Token': csrf,
                'X-Requested-With': 'XMLHttpRequest',
                Accept: 'application/json',
            },
            body: JSON.stringify(payload),
        });

        const data = await response.json().catch(() => ({ error: 'Unexpected server response.' }));
        if (!response.ok) {
            const error = new Error(data.error || `Request failed (${response.status})`);
            error.payload = data;
            error.status = response.status;
            throw error;
        }

        return data;
    };

    Argus.get = async (url) => {
        const response = await fetch(url, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const data = await response.json().catch(() => ({ error: 'Unexpected server response.' }));
        if (!response.ok) {
            throw new Error(data.error || `Request failed (${response.status})`);
        }

        return data;
    };

    /* --------------------------------------------------------- appearance */
    const html = document.documentElement;
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
                /* private mode: session-only preference */
            }
        },
    };

    const applyTheme = (theme) => {
        if (theme === 'light') {
            html.dataset.theme = 'light';
        } else {
            delete html.dataset.theme;
        }
        document.querySelector('meta[name="color-scheme"]')?.setAttribute('content', theme === 'light' ? 'light' : 'dark');
        document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
            button.setAttribute('aria-pressed', theme === 'light' ? 'true' : 'false');
            button.title = theme === 'light' ? 'Switch to dark mode' : 'Switch to light mode';
        });
    };

    const applyDensity = (density) => {
        if (density === 'compact') {
            html.dataset.density = 'compact';
        } else {
            delete html.dataset.density;
        }
        document.querySelectorAll('[data-density-toggle]').forEach((button) => {
            button.setAttribute('aria-pressed', density === 'compact' ? 'true' : 'false');
            button.title = density === 'compact' ? 'Switch to comfortable view' : 'Switch to compact view';
        });
    };

    const applyRail = (collapsed) => {
        if (collapsed) {
            html.dataset.rail = '1';
        } else {
            delete html.dataset.rail;
        }
        document.querySelectorAll('[data-rail-toggle]').forEach((button) => {
            button.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        });
    };

    applyTheme(pref.get('theme') === 'light' ? 'light' : 'dark');
    applyDensity(pref.get('density') === 'compact' ? 'compact' : 'comfortable');
    applyRail(pref.get('rail') !== '0');

    document.addEventListener('click', (event) => {
        if (event.target.closest('[data-theme-toggle]')) {
            const next = html.dataset.theme === 'light' ? 'dark' : 'light';
            applyTheme(next);
            pref.set('theme', next);
            return;
        }

        if (event.target.closest('[data-density-toggle]')) {
            const next = html.dataset.density === 'compact' ? 'comfortable' : 'compact';
            applyDensity(next);
            pref.set('density', next);
            window.dispatchEvent(new CustomEvent('argus:density'));
            return;
        }

        if (event.target.closest('[data-rail-toggle]')) {
            const next = html.dataset.rail === '1' ? '0' : '1';
            applyRail(next === '1');
            pref.set('rail', next);
        }
    });

    /* ------------------------------------------------ collapsible cards */
    const collapseStores = '[data-collapse]';

    function setCollapsed(card, collapsed) {
        card.classList.toggle('is-collapsed', collapsed);
        const button = card.querySelector('[data-collapse-toggle]');
        if (button) {
            button.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            button.querySelector('.collapse-label')?.replaceChildren(document.createTextNode(collapsed ? 'Show' : 'Hide'));
        }
    }

    document.querySelectorAll(collapseStores).forEach((card) => {
        const key = `argus.collapse.${card.dataset.collapse}`;
        let saved = null;
        try {
            saved = window.localStorage.getItem(key);
        } catch (error) {
            saved = null;
        }

        setCollapsed(card, saved === '1' || card.dataset.collapsed === 'true');

        card.querySelector('[data-collapse-toggle]')?.addEventListener('click', () => {
            const collapsed = !card.classList.contains('is-collapsed');
            setCollapsed(card, collapsed);
            try {
                window.localStorage.setItem(key, collapsed ? '1' : '0');
            } catch (error) {
                /* ignore */
            }
        });
    });

    /* ------------------------------------------------------------- toasts */
    function toast(message, type = 'success') {
        const container = document.querySelector('[data-toasts]');
        if (!container) return;

        const el = document.createElement('div');
        el.className = `toast toast--${type}`;
        el.setAttribute('data-toast', '');
        el.innerHTML = `
            <span class="toast__icon">${type === 'error' ? '!' : '✓'}</span>
            <p></p>
            <button type="button" class="icon-btn icon-btn--sm" data-toast-close aria-label="Dismiss">×</button>`;
        el.querySelector('p').textContent = message;
        container.appendChild(el);
        dismissLater(el);
    }

    function dismissLater(el, delay = 5200) {
        const timer = window.setTimeout(() => dismiss(el), delay);
        el.addEventListener('mouseenter', () => window.clearTimeout(timer), { once: true });
    }

    function dismiss(el) {
        if (!el || !el.isConnected) return;
        el.classList.add('is-leaving');
        window.setTimeout(() => el.remove(), 210);
    }

    document.addEventListener('click', (event) => {
        const close = event.target.closest('[data-toast-close]');
        if (close) {
            dismiss(close.closest('[data-toast]'));
        }
    });

    document.querySelectorAll('[data-toast]').forEach((el) => dismissLater(el, 6500));
    Argus.toast = toast;

    /* -------------------------------------------------------------- clock */
    const clockTime = document.querySelector('[data-clock-time]');
    const clockDate = document.querySelector('[data-clock-date]');

    if (clockTime) {
        const tick = () => {
            const now = new Date();
            clockTime.textContent = now.toLocaleTimeString([], {
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                hour12: false,
            });
            if (clockDate) {
                clockDate.textContent = now
                    .toLocaleDateString([], { weekday: 'short', day: '2-digit', month: 'short' })
                    .toUpperCase();
            }
        };
        tick();
        window.setInterval(tick, 1000);
    }

    /* ------------------------------------------------------------ sidebar */
    document.querySelectorAll('[data-sidebar-toggle]').forEach((button) => {
        button.addEventListener('click', () => document.body.classList.toggle('sidebar-open'));
    });

    /* -------------------------------------------------------------- modals */
    function openModal(modal) {
        if (!modal) return;
        modal.hidden = false;
        document.body.style.overflow = 'hidden';
        const focusable = modal.querySelector('input, select, textarea, button');
        if (focusable) window.setTimeout(() => focusable.focus({ preventScroll: true }), 40);
    }

    function closeModal(modal) {
        if (!modal) return;
        modal.hidden = true;
        if (!document.querySelector('.modal:not([hidden])')) {
            document.body.style.overflow = '';
        }
    }

    Argus.openModal = openModal;
    Argus.closeModal = closeModal;

    document.addEventListener('click', (event) => {
        const opener = event.target.closest('[data-modal-open]');
        if (opener) {
            openModal(document.querySelector(`[data-modal="${opener.dataset.modalOpen}"]`));
            return;
        }

        const closer = event.target.closest('[data-modal-close]');
        if (closer) {
            closeModal(closer.closest('.modal'));
            return;
        }

        if (event.target.classList.contains('modal')) {
            closeModal(event.target);
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            document.querySelectorAll('.modal:not([hidden])').forEach(closeModal);
            document.body.classList.remove('sidebar-open');
        }
    });

    /* ------------------------------------------------------------ confirm */
    document.addEventListener('submit', (event) => {
        const form = event.target.closest('form[data-confirm]');
        if (form && !window.confirm(form.dataset.confirm)) {
            event.preventDefault();
        }
    });

    /* ------------------------------------------------- connection testing */
    function renderTestResult(target, ok, message, steps = []) {
        if (!target) return;
        target.hidden = false;
        target.classList.toggle('is-ok', ok);
        target.classList.toggle('is-bad', !ok);
        target.replaceChildren();

        const line = document.createElement('p');
        line.textContent = message;
        target.appendChild(line);

        if (Array.isArray(steps) && steps.length) {
            const list = document.createElement('ul');
            list.className = 'test-result__steps';
            steps.forEach((step) => {
                const item = document.createElement('li');
                item.textContent = String(step);
                list.appendChild(item);
            });
            target.appendChild(list);
        }
    }

    document.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-test-camera]');
        if (!button) return;

        const url = button.dataset.testUrl;
        if (!url) return;

        const resultBox =
            button.closest('[data-camera-card]')?.querySelector('[data-card-result]') ||
            document.querySelector('[data-test-result]');
        const original = button.innerHTML;
        button.disabled = true;
        button.innerHTML = '<span class="diag">Connecting…</span>';

        try {
            const data = await Argus.post(url, {});
            const latency = data.latency_ms ? ` (${data.latency_ms} ms)` : '';
            renderTestResult(
                resultBox,
                true,
                data.detail ? `${data.headline} — ${data.detail}` : (data.headline || `Camera answered${latency}.`),
                data.steps || []
            );
            toast(`Camera is online${latency}`, 'success');
            updateCardStatus(button, data.status);
        } catch (error) {
            const payload = error.payload || {};
            renderTestResult(
                resultBox,
                false,
                payload.headline || error.message || 'The camera did not respond.',
                payload.steps || []
            );
            toast(payload.headline || error.message || 'Camera unreachable', 'error');
            updateCardStatus(button, 'offline');
        } finally {
            button.disabled = false;
            button.innerHTML = original;
        }
    });

    function updateCardStatus(button, status) {
        const card = button.closest('[data-camera-card]');
        if (!card) return;
        const id = card.dataset.cameraCard;
        document.querySelectorAll(`[data-status-dot="${id}"]`).forEach((dot) => {
            dot.classList.remove('dot--online', 'dot--offline', 'dot--unknown');
            dot.classList.add(`dot--${status || 'unknown'}`);
        });
        const text = card.querySelector('[data-status-text]');
        if (text) {
            text.textContent = status === 'online' ? 'Online' : status === 'offline' ? 'Offline' : 'Unknown';
        }
    }

    /* -------------------------------------------------------- camera form */
    const cameraForm = document.querySelector('[data-camera-form]');
    if (cameraForm) {
        const presetsNode = document.getElementById('driver-presets');
        const presets = presetsNode ? JSON.parse(presetsNode.textContent) : {};
        const driverSelect = cameraForm.querySelector('[data-driver-select]');
        const hint = cameraForm.querySelector('[data-driver-hint]');
        const pathInputs = Array.from(cameraForm.querySelectorAll('[data-path]'));
        let currentDriver = driverSelect ? driverSelect.value : '';

        const applyPreset = (driver, force = false) => {
            const preset = presets[driver];
            if (!preset) return;

            pathInputs.forEach((input) => {
                const kind = input.dataset.path;
                const previous = presets[currentDriver] ? presets[currentDriver][kind] : '';
                const untouched = input.value.trim() === '' || input.value === previous;
                if (force || untouched) {
                    input.value = preset[kind] || '';
                }
            });

            if (hint) hint.textContent = preset.hint || '';
            currentDriver = driver;
        };

        if (driverSelect) {
            driverSelect.addEventListener('change', () => applyPreset(driverSelect.value));
        }

        // Start from the selected driver's defaults for a brand new camera.
        if (cameraForm.querySelector('[data-path="snapshot"]')?.value === '' && presets[currentDriver]) {
            applyPreset(currentDriver, true);
        }
    }

    /* ----------------------------------------------------------- user modal */
    const userForm = document.querySelector('[data-user-form]');
    if (userForm) {
        const createAction = userForm.getAttribute('action');
        const title = userForm.querySelector('[data-user-title]');
        const subtitle = userForm.querySelector('[data-user-subtitle]');
        const password = userForm.querySelector('[data-user-field="password"]');
        const passwordHint = userForm.querySelector('[data-password-hint]');

        const openFor = (user) => {
            userForm.reset();
            userForm.setAttribute('action', user ? `${createAction}/${user.id}` : createAction);
            if (title) title.textContent = user ? 'Edit user' : 'New user';
            if (subtitle) {
                subtitle.textContent = user
                    ? `Update ${user.name}'s access.`
                    : 'Create an account for a colleague.';
            }
            if (password) password.required = !user;
            if (passwordHint) passwordHint.textContent = user ? '(optional)' : '';

            if (user) {
                userForm.querySelector('[data-user-field="name"]').value = user.name || '';
                userForm.querySelector('[data-user-field="email"]').value = user.email || '';
                userForm.querySelector('[data-user-field="role"]').value = user.role || 'viewer';
                userForm.querySelector('[data-user-field="active"]').checked = Number(user.active) === 1;
                userForm.querySelectorAll('[name="groups[]"]').forEach((checkbox) => {
                    checkbox.checked = (user.groups || []).includes(Number(checkbox.value));
                });
            } else {
                userForm.querySelector('[data-user-field="role"]').value = 'viewer';
                userForm.querySelector('[data-user-field="active"]').checked = true;
            }

            openModal(document.querySelector('[data-modal="user-modal"]'));
        };

        document.querySelectorAll('[data-user-new]').forEach((button) => {
            button.addEventListener('click', () => openFor(null));
        });

        document.querySelectorAll('[data-user-edit]').forEach((button) => {
            button.addEventListener('click', () => {
                try {
                    openFor(JSON.parse(button.dataset.userEdit));
                } catch (error) {
                    toast('Unable to load that user.', 'error');
                }
            });
        });
    }

    /* ------------------------------------------------- camera page polling */
    const camerasData = document.getElementById('cameras-data');
    if (camerasData) {
        const config = JSON.parse(camerasData.textContent);
        if (Array.isArray(config.ids) && config.ids.length) {
            const poll = async () => {
                if (document.hidden) return;
                try {
                    const data = await Argus.get(`${config.statusUrl}?ids=${config.ids.join(',')}`);
                    (data.cameras || []).forEach((camera) => {
                        document.querySelectorAll(`[data-status-dot="${camera.id}"]`).forEach((dot) => {
                            dot.classList.remove('dot--online', 'dot--offline', 'dot--unknown');
                            dot.classList.add(`dot--${camera.status}`);
                        });
                        const card = document.querySelector(`[data-camera-card="${camera.id}"]`);
                        const text = card?.querySelector('[data-status-text]');
                        if (text) {
                            text.textContent =
                                camera.status === 'online' ? 'Online' : camera.status === 'offline' ? 'Offline' : 'Unknown';
                        }
                    });
                } catch (error) {
                    /* transient network errors are ignored */
                }
            };

            window.setTimeout(poll, 4000);
            window.setInterval(poll, 45000);
        }
    }

    /* --------------------------------------------- latency / jitter buffer */
    document.querySelectorAll('[data-latency-control]').forEach((control) => {
        const slider = control.querySelector('[data-latency-slider]');
        const output = control.querySelector('[data-latency-value]');
        const presets = Array.from(control.querySelectorAll('[data-latency-preset]'));

        const sync = () => {
            if (!slider) return;
            if (output) output.textContent = `${slider.value} ms`;
            presets.forEach((button) => {
                button.classList.toggle('is-active', Number(button.dataset.latencyPreset) === Number(slider.value));
            });
        };

        slider?.addEventListener('input', sync);
        presets.forEach((button) => {
            button.addEventListener('click', () => {
                if (!slider) return;
                slider.value = button.dataset.latencyPreset;
                sync();
            });
        });

        sync();
    });

    /* ------------------------------------------------ credential inspector */
    const credentialPanel = document.querySelector('[data-credential-panel]');
    if (credentialPanel) {
        const url = credentialPanel.dataset.credentialUrl;
        const body = credentialPanel.querySelector('[data-credential-body]');
        const plainInput = credentialPanel.querySelector('[data-credential-plain]');
        const encoded = credentialPanel.querySelector('[data-credential-encoded]');
        const uriInput = credentialPanel.querySelector('[data-credential-uri]');
        const status = credentialPanel.querySelector('[data-credential-status]');
        const issues = credentialPanel.querySelector('[data-credential-issues]');
        const eye = credentialPanel.querySelector('[data-credential-eye]');
        const check = credentialPanel.querySelector('[data-credential-check]');

        eye?.addEventListener('click', () => {
            if (!plainInput) return;
            const reveal = plainInput.type === 'password';
            plainInput.type = reveal ? 'text' : 'password';
            eye.setAttribute('aria-pressed', reveal ? 'true' : 'false');
            eye.title = reveal ? 'Mask password' : 'Reveal password';
        });

        check?.addEventListener('click', async () => {
            if (!url) return;
            const original = check.innerHTML;
            check.disabled = true;
            check.innerHTML = '<span class="diag">Checking…</span>';

            try {
                const report = await Argus.post(url, {});

                body.hidden = false;
                plainInput.value = report.plaintext || '';
                plainInput.type = 'password';
                if (encoded) encoded.textContent = report.encoded_password || '(none)';
                uriInput.value = report.uri || '(no RTSP path configured)';

                status.textContent = report.status;
                status.classList.toggle('status-pill--ok', report.status === 'AUTH_VERIFIED');
                status.classList.toggle('status-pill--bad', report.status === 'AUTH_ENCODING_ERROR');

                if (issues) {
                    issues.textContent = report.issues && report.issues.length
                        ? report.issues.join(' ')
                        : report.status === 'AUTH_VERIFIED'
                            ? `Round-trip OK${report.special_characters && report.special_characters.length ? ' — special characters: ' + report.special_characters.join(' ') : ''}`
                            : 'No credentials stored.';
                }

                toast(
                    report.status === 'AUTH_VERIFIED' ? 'Credentials verified: RTSP URI encoding is correct.' : `Credential check: ${report.status}`,
                    report.status === 'AUTH_VERIFIED' ? 'success' : 'error'
                );
            } catch (error) {
                toast(error.message || 'Could not inspect the credentials.', 'error');
            } finally {
                check.disabled = false;
                check.innerHTML = original;
            }
        });
    }

    /* ------------------------------------------------- dismiss check panel */
    document.addEventListener('click', (event) => {
        if (event.target.closest('[data-dismiss-check]')) {
            event.target.closest('[data-check-result]')?.remove();
        }
    });

    Argus.updateCardStatus = updateCardStatus;
    Argus.baseUrl = baseUrl;
    Argus.pref = pref;
})();
