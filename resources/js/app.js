

import Alpine from 'alpinejs';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import './api.js';

window.Alpine = Alpine;

const echoConfig = import.meta.env;
if (echoConfig.VITE_REVERB_APP_KEY && echoConfig.VITE_REVERB_HOST) {
    window.Pusher = Pusher;
    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: echoConfig.VITE_REVERB_APP_KEY,
        authEndpoint: '/broadcasting/auth',
        auth: {
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
        },
        wsHost: echoConfig.VITE_REVERB_HOST,
        wsPort: Number(echoConfig.VITE_REVERB_PORT || 8080),
        wssPort: Number(echoConfig.VITE_REVERB_PORT || 443),
        forceTLS: (echoConfig.VITE_REVERB_SCHEME || 'http') === 'https',
        enabledTransports: ['ws', 'wss'],
    });
}

const enforcePhilippinePhoneInput = (input) => {
    if (!(input instanceof HTMLInputElement)) {
        return;
    }

    const formatValue = (value) => {
        const trimmed = value.trim();
        if (! trimmed) {
            return '';
        }

        const digitsOnly = trimmed.replace(/\D/g, '');
        const hasPlus = trimmed.startsWith('+');

        if (hasPlus) {
            return '+' + digitsOnly.slice(0, 12);
        }

        return digitsOnly.slice(0, 11);
    };

    input.setAttribute('inputmode', 'numeric');
    input.setAttribute('autocomplete', 'tel');
    input.setAttribute('pattern', '^(09\\d{9}|\\+639\\d{9})$');

    input.addEventListener('input', () => {
        input.value = formatValue(input.value);
    });
};

const showGlobalToast = (message, type = 'info') => {
    if (!message) {
        return;
    }

    const existing = document.getElementById('global-his-toast');
    if (existing) {
        existing.remove();
    }

    const toast = document.createElement('div');
    toast.id = 'global-his-toast';
    toast.setAttribute('role', 'alert');
    toast.setAttribute('aria-live', 'polite');
    toast.className = 'fixed right-4 top-4 z-[9999] max-w-sm rounded-xl border px-4 py-3 text-sm font-medium shadow-lg backdrop-blur';

    const palette = {
        success: 'border-emerald-200 bg-emerald-50 text-emerald-700',
        danger: 'border-rose-200 bg-rose-50 text-rose-700',
        warning: 'border-amber-200 bg-amber-50 text-amber-700',
        info: 'border-sky-200 bg-sky-50 text-sky-700',
    };

    toast.className += ` ${palette[type] || palette.info}`;
    toast.textContent = message;

    document.body.appendChild(toast);

    setTimeout(() => {
        toast.remove();
    }, type === 'danger' ? 10000 : 3500);
};

window.hisToast = showGlobalToast;

const listenForEmergencyAlerts = () => {
    if (!document.querySelector('[data-emergency-alerts]') || !window.Echo) {
        return;
    }

    window.Echo.private('er-alerts')
        .listen('.emergency.queue.alert', (alert) => {
            showGlobalToast(alert.message || 'Emergency queue update.', 'danger');
        });
};

const initializeFilterPanels = () => {
    const triggers = [...document.querySelectorAll('[data-filter-trigger]')];
    const panels = [...document.querySelectorAll('.filter-panel')];
    const hoverState = new Map();
    const closeDelayMs = 250;

    if (!triggers.length) {
        return;
    }

    const getPanel = (trigger) => document.getElementById(trigger.getAttribute('data-filter-target'));

    const positionPanel = (trigger, panel) => {
        const triggerRect = trigger.getBoundingClientRect();
        const panelHeight = panel.getBoundingClientRect().height;
        const panelWidth = panel.getBoundingClientRect().width;
        const spaceBelow = window.innerHeight - triggerRect.bottom;
        const left = Math.max(8, Math.min(triggerRect.left, window.innerWidth - panelWidth - 8));

        panel.style.left = `${left}px`;
        panel.style.right = 'auto';
        if (panelHeight > spaceBelow) {
            panel.style.top = `${Math.max(8, triggerRect.top - panelHeight - 8)}px`;
            panel.style.bottom = 'auto';
            panel.style.marginTop = '0';
            panel.style.marginBottom = '0';
        } else {
            panel.style.top = `${triggerRect.bottom + 8}px`;
            panel.style.bottom = 'auto';
            panel.style.marginTop = '0';
            panel.style.marginBottom = '0';
        }
    };

    const closePanels = () => {
        panels.forEach((panel) => panel.classList.add('hidden'));
        triggers.forEach((trigger) => trigger.setAttribute('aria-expanded', 'false'));
    };

    const openPanel = (trigger) => {
        const panel = getPanel(trigger);
        if (!panel) {
            return;
        }

        closePanels();
        if (panel.parentElement !== document.body) {
            document.body.appendChild(panel);
        }
        panel.style.position = 'fixed';
        panel.style.zIndex = '1080';
        panel.classList.remove('hidden');
        positionPanel(trigger, panel);
        trigger.setAttribute('aria-expanded', 'true');
    };

    const cancelClose = (trigger) => {
        const state = hoverState.get(trigger);
        if (state?.closeTimer) {
            clearTimeout(state.closeTimer);
            state.closeTimer = null;
        }
    };

    const scheduleClose = (trigger) => {
        const state = hoverState.get(trigger);
        if (!state || state.triggerHovered || state.panelHovered) {
            return;
        }

        cancelClose(trigger);
        state.closeTimer = setTimeout(() => {
            if (!state.triggerHovered && !state.panelHovered) {
                getPanel(trigger)?.classList.add('hidden');
                trigger.setAttribute('aria-expanded', 'false');
            }
        }, closeDelayMs);
    };

    triggers.forEach((trigger) => {
        const panel = getPanel(trigger);
        if (!panel) {
            return;
        }

        const state = { triggerHovered: false, panelHovered: false, closeTimer: null };
        hoverState.set(trigger, state);

        trigger.addEventListener('mouseenter', () => {
            state.triggerHovered = true;
            cancelClose(trigger);
            openPanel(trigger);
        });

        trigger.addEventListener('mouseleave', () => {
            state.triggerHovered = false;
            scheduleClose(trigger);
        });

        panel.addEventListener('mouseenter', () => {
            state.panelHovered = true;
            cancelClose(trigger);
        });

        panel.addEventListener('mouseleave', () => {
            state.panelHovered = false;
            scheduleClose(trigger);
        });

        trigger.addEventListener('click', (event) => {
            event.stopPropagation();
            const isOpen = !panel.classList.contains('hidden');

            closePanels();

            if (!isOpen) {
                openPanel(trigger);
            }
        });
    });

    document.addEventListener('click', (event) => {
        if (!event.target.closest('[data-filter-trigger]') && !event.target.closest('.filter-panel')) {
            closePanels();
        }
    });

    window.addEventListener('resize', () => {
        triggers.forEach((trigger) => {
            const panel = getPanel(trigger);
            if (panel && !panel.classList.contains('hidden')) {
                positionPanel(trigger, panel);
            }
        });
    });
};

const initializeAppointmentSlotPickers = () => {
    const requests = new WeakMap();

    document.addEventListener('change', async (event) => {
        if (event.target.matches('[data-slot-id]')) {
            const form = event.target.closest('[data-appointment-slot-picker]');
            if (form) {
                form.querySelector('[name="starts_at"]').value = event.target.dataset.startsAt;
            }
            return;
        }

        if (!event.target.matches('[data-slot-date], [data-slot-provider]')) {
            return;
        }

        const form = event.target.closest('[data-appointment-slot-picker]');
        if (!form) {
            return;
        }

        requests.get(form)?.abort();
        const results = form.querySelector('[data-slot-results]');
        const startsAt = form.querySelector('[name="starts_at"]');
        form.querySelector('[data-prefilled-slot]')?.remove();
        if (startsAt) {
            startsAt.value = '';
        }
        const providerId = form.dataset.providerId || form.querySelector('[data-slot-provider]')?.value;
        const date = form.querySelector('[data-slot-date]')?.value;
        results.replaceChildren();
        startsAt.value = '';

        if (!providerId || !date) {
            return;
        }

        const controller = new AbortController();
        requests.set(form, controller);

        try {
            const params = new URLSearchParams({ provider_id: providerId, date });
            const response = await fetch(`${form.dataset.slotsUrl}?${params}`, {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            });
            if (!response.ok) {
                throw new Error('Slot lookup failed.');
            }

            const payload = await response.json();
            if (!payload.data?.length) {
                results.textContent = 'No available slots for that date.';
                return;
            }

            payload.data.forEach((slot) => {
                const label = document.createElement('label');
                label.className = 'mr-2 inline-flex cursor-pointer items-center gap-1 rounded border px-2 py-1';
                const radio = document.createElement('input');
                radio.type = 'radio';
                radio.name = 'appointment_slot_id';
                radio.value = slot.id;
                radio.required = true;
                radio.dataset.slotId = '';
                radio.dataset.startsAt = slot.starts_at;
                const time = document.createElement('span');
                time.textContent = new Date(slot.starts_at).toLocaleTimeString([], {
                    hour: '2-digit',
                    minute: '2-digit',
                });
                label.append(radio, time);
                results.appendChild(label);
            });
        } catch (error) {
            if (error.name !== 'AbortError') {
                results.textContent = 'Could not load appointment slots. Please try again.';
            }
        }
    });
};

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('input[data-phone-input]').forEach(enforcePhilippinePhoneInput);
    initializeFilterPanels();
    initializeAppointmentSlotPickers();
    listenForEmergencyAlerts();
});

Alpine.start();
