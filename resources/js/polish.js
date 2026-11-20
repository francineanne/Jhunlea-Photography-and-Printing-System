import { linkify } from './chat-common';
// Phase 10D: the small interface helpers added in the final polish pass. Everything here is an enhancement: with
// JavaScript off every page still shows all its content and every form still posts.

const root = document.documentElement;
const reduced = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

const store = {
    get(key) {
        try {
            return localStorage.getItem(key);
        } catch (e) {
            return null;
        }
    },
    set(key, value) {
        try {
            localStorage.setItem(key, value);
        } catch (e) {
            // storage unavailable: the choice simply lasts for this page
        }
    },
};

const toast = (detail) => window.dispatchEvent(new CustomEvent('toast', { detail }));

// Phase 10K: a short, soft two-note chime when a new notification arrives (the live poll sees the unread count go up).
// Web Audio only, no file. Browsers allow sound only after the person has clicked, tapped or pressed a key, so the
// audio is unlocked then; before that, or wherever Web Audio is missing or blocked, it simply stays silent. ON by
// default; the switch in the bell and on the notifications page keeps the choice in localStorage. At most one chime
// every 10 seconds.
const chime = {
    KEY: 'notification-sound',
    context: null,
    last: 0,

    enabled() {
        return store.get(this.KEY) !== 'off';
    },

    setEnabled(on) {
        store.set(this.KEY, on ? 'on' : 'off');
        window.dispatchEvent(new CustomEvent('notification-sound', { detail: { on } }));
    },

    unlock() {
        try {
            const Context = window.AudioContext || window.webkitAudioContext;

            if (!Context) return;
            this.context ??= new Context();
            if (this.context.state === 'suspended') this.context.resume().catch(() => {});
        } catch (e) {
            this.context = null;
        }
    },

    play() {
        const now = Date.now();

        if (!this.enabled() || now - this.last < 10000) return false;

        try {
            const context = this.context;

            if (!context || context.state !== 'running') return false;
            this.last = now;
            const start = context.currentTime + 0.01;

            // Two notes (E6 then A5), each a quick soft sine with a gentle fade, about 0.3 s in all
            [[1318.5, 0], [880, 0.12]].forEach(([frequency, offset]) => {
                const oscillator = context.createOscillator();
                const gain = context.createGain();

                oscillator.type = 'sine';
                oscillator.frequency.value = frequency;
                gain.gain.setValueAtTime(0.0001, start + offset);
                gain.gain.exponentialRampToValueAtTime(0.05, start + offset + 0.015);
                gain.gain.exponentialRampToValueAtTime(0.0001, start + offset + 0.18);
                oscillator.connect(gain).connect(context.destination);
                oscillator.start(start + offset);
                oscillator.stop(start + offset + 0.2);
            });

            window.dispatchEvent(new CustomEvent('notification-chime'));

            return true;
        } catch (e) {
            return false;
        }
    },
};

['pointerdown', 'keydown', 'touchstart'].forEach((type) => document.addEventListener(type, () => chime.unlock(), { capture: true, passive: true }));

// The on/off switch (bell dropdown and the notifications page); every copy on the page follows the same choice
const soundSwitch = () => ({
    on: chime.enabled(),

    init() {
        window.addEventListener('notification-sound', (event) => {
            this.on = Boolean(event.detail?.on);
        });
    },

    toggle() {
        chime.setEnabled(!this.on);
        if (!this.on) return;
        chime.unlock();
    },
});

// A time field without a scrolling wheel: a button and a grid of times. The <select> stays the form control.
const uiTimePicker = (options, value) => ({
    options,
    value: value ?? '',
    open: false,
    pm: false,
    flip: false,

    init() {
        this.pm = this.value ? Number(this.value.slice(0, 2)) >= 12 : false;
        this.$watch('value', (now) => {
            if (this.$refs.select.value !== now) {
                this.$refs.select.value = now;
                this.$refs.select.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
    },

    get shown() {
        return this.options.filter((option) => option.pm === this.pm);
    },

    text(value) {
        return this.options.find((option) => option.value === value)?.label ?? value;
    },

    toggle() {
        this.open ? this.close(true) : this.show();
    },

    show() {
        if (this.$refs.select.disabled) return;
        this.pm = this.value ? Number(this.value.slice(0, 2)) >= 12 : this.pm;
        // Keep the popover inside the window when the field sits near the right edge
        this.flip = this.$refs.button.getBoundingClientRect().left + 300 > window.innerWidth;
        this.open = true;
        this.$nextTick(() => (this.$refs.grid.querySelector('[tabindex="0"]') ?? this.$refs.grid.querySelector('button'))?.focus());
    },

    close(refocus) {
        if (!this.open) return;
        this.open = false;
        if (refocus) this.$refs.button.focus();
    },

    choose(value) {
        this.value = value;
        this.close(true);
    },

    move(event) {
        const cells = [...this.$refs.grid.querySelectorAll('button')];
        const at = cells.indexOf(document.activeElement);
        const step = { ArrowRight: 1, ArrowLeft: -1, ArrowDown: 4, ArrowUp: -4 }[event.key];
        let next = null;

        if (step !== undefined && at !== -1) next = at + step;
        if (event.key === 'Home') next = 0;
        if (event.key === 'End') next = cells.length - 1;
        if (next === null) return;

        event.preventDefault();
        cells[Math.max(0, Math.min(cells.length - 1, next))]?.focus();
    },
});

// Filters that fold into a "Filters" button and a bottom sheet on phones; from 640px the panel is simply on the page.
const uiFilterSheet = () => ({
    open: false,
    trigger: null,

    show() {
        this.trigger = document.activeElement;
        this.open = true;
        root.classList.add('overflow-hidden', 'modal-open');
        this.$nextTick(() => this.$refs.sheet?.querySelector('a[href], button, input, select')?.focus());
    },

    hide() {
        if (!this.open) return;
        this.open = false;
        root.classList.remove('overflow-hidden', 'modal-open');
        this.trigger?.focus?.();
    },
});

// The one confirmation dialog (<x-ui.confirm>). A form or submit button with data-confirm asks first.
const uiConfirm = () => ({
    open: false,
    title: '',
    text: '',
    detail: '',
    okLabel: 'Continue',
    cancelLabel: 'Go back',
    danger: false,
    form: null,
    submitter: null,
    trigger: null,
    pushed: false,

    init() {
        // The browser's back button closes the dialog instead of leaving the page
        window.addEventListener('popstate', () => {
            if (this.open) {
                this.pushed = false;
                this.close();
            }
        });
    },

    ask({ form, submitter }) {
        if (this.open) return;

        const read = (name) => submitter?.dataset[name] ?? form.dataset[name] ?? '';

        this.form = form;
        this.submitter = submitter ?? null;
        this.title = read('confirm');
        this.text = read('confirmText');
        this.detail = read('confirmDetail');
        this.okLabel = read('confirmOk') || 'Continue';
        this.cancelLabel = read('confirmCancel') || 'Go back';
        this.danger = (submitter && 'confirmDanger' in submitter.dataset) || 'confirmDanger' in form.dataset;
        this.trigger = document.activeElement;
        this.open = true;
        root.classList.add('overflow-hidden', 'modal-open');
        try {
            history.pushState({ confirmDialog: true }, '');
            this.pushed = true;
        } catch (e) {
            this.pushed = false;
        }
        this.$nextTick(() => this.$refs.ok.focus());
    },

    close() {
        if (!this.open) return;
        this.open = false;
        // Another dialog may still be open under this one (a form inside a modal)
        if (!document.querySelector('[role="dialog"][aria-modal="true"]:not([style*="display: none"])')) {
            root.classList.remove('overflow-hidden', 'modal-open');
        }
        this.trigger?.focus?.();
    },

    cancel() {
        if (!this.open) return;
        if (this.pushed) {
            this.pushed = false;
            history.back();
        }
        this.close();
    },

    accept() {
        const { form, submitter } = this;

        if (this.pushed) {
            this.pushed = false;
            history.back();
        }
        this.close();
        if (!form) return;
        form.dataset.confirmed = '1';
        // After history.back() has settled, so the post is not cancelled by the history move
        setTimeout(() => {
            if (submitter && submitter.isConnected) form.requestSubmit(submitter);
            else form.requestSubmit();
            delete form.dataset.confirmed;
        }, 60);
    },

    trap(event) {
        if (!this.open) return;
        const items = [...this.$refs.panel.querySelectorAll('button')];
        const first = items[0];
        const last = items[items.length - 1];

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    },
});

// A form or submit button with data-confirm: stop the post and ask in the one dialog first
document.addEventListener('submit', (event) => {
    const form = event.target;

    if (!(form instanceof HTMLFormElement) || form.dataset.confirmed === '1') return;
    if (!(event.submitter?.dataset.confirm || form.dataset.confirm)) return;
    if (!document.querySelector('[data-confirm-dialog]')) return;

    event.preventDefault();
    event.stopImmediatePropagation();
    window.dispatchEvent(new CustomEvent('confirm-ask', { detail: { form, submitter: event.submitter } }));
}, true);

// Every button that submits shows a pressed, busy state and cannot be pressed twice. The server's own idempotency
// stays the real protection; this only stops the double click. A page restored from the back cache is reset.
const busyForms = new Set();

const settle = (form) => {
    form.querySelectorAll('[data-busy]').forEach((button) => {
        button.disabled = false;
        button.removeAttribute('aria-busy');
        delete button.dataset.busy;
    });
    busyForms.delete(form);
};

document.addEventListener('submit', (event) => {
    const form = event.target;

    if (!(form instanceof HTMLFormElement) || event.defaultPrevented || 'noBusy' in form.dataset) return;
    if (busyForms.has(form)) {
        event.preventDefault();
        return;
    }

    const button = event.submitter ?? form.querySelector('button[type="submit"], button:not([type])');
    // A download leaves the page where it is, so its button must stay usable
    const download = /csv|download|export|receipt/i.test(form.getAttribute('action') ?? '') || form.target === '_blank';

    if (!button || download) return;

    busyForms.add(form);
    // After this turn: a disabled button would not send its own name and value
    setTimeout(() => {
        button.dataset.busy = '1';
        button.setAttribute('aria-busy', 'true');
        button.disabled = true;
    }, 0);
    // Never leave a page locked when the request fails without a new page
    setTimeout(() => settle(form), 10000);
});

window.addEventListener('pageshow', () => [...busyForms].forEach(settle));

// The one photo viewer (<x-ui.lightbox>). Links with data-lightbox="group" open in it; the group is stepped through.
const uiLightbox = () => ({
    open: false,
    items: [],
    index: 0,
    trigger: null,
    startX: null,

    get current() {
        return this.items[this.index] ?? { src: '', alt: '' };
    },

    // { group, index } from a button, or { link } from a clicked photo link
    openAt({ group, index = 0, link = null }) {
        const links = [...document.querySelectorAll(`a[data-lightbox="${CSS.escape(group ?? link?.dataset.lightbox ?? '')}"]`)];
        // The same photo may be linked twice (a thumbnail and the large view): keep each address once
        const seen = new Set();
        const items = [];

        links.forEach((a) => {
            const src = a.getAttribute('href');
            if (!src || seen.has(src)) return;
            seen.add(src);
            items.push({ src, alt: a.dataset.alt ?? '' });
        });
        if (items.length === 0) return;

        const wanted = link ? items.findIndex((item) => item.src === link.getAttribute('href')) : index;

        this.items = items;
        this.index = Math.max(0, Math.min(items.length - 1, wanted));
        this.trigger = document.activeElement;
        this.open = true;
        root.classList.add('overflow-hidden', 'modal-open');
        this.$nextTick(() => this.$refs.close.focus());
    },

    close() {
        if (!this.open) return;
        this.open = false;
        root.classList.remove('overflow-hidden', 'modal-open');
        this.trigger?.focus?.();
    },

    step(by) {
        if (!this.open || this.items.length < 2) return;
        this.index = (this.index + by + this.items.length) % this.items.length;
    },

    touchStart(event) {
        this.startX = event.changedTouches[0]?.clientX ?? null;
    },

    touchEnd(event) {
        if (this.startX === null) return;
        const moved = (event.changedTouches[0]?.clientX ?? this.startX) - this.startX;
        this.startX = null;
        if (Math.abs(moved) > 40) this.step(moved < 0 ? 1 : -1);
    },

    trap(event) {
        if (!this.open) return;
        const items = [...this.$el.querySelectorAll('button')].filter((el) => el.offsetParent !== null);
        const first = items[0];
        const last = items[items.length - 1];

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    },
});

// A photo link opens in the viewer when the page has one; otherwise (or with a modifier key) it is a normal link
document.addEventListener('click', (event) => {
    const link = event.target instanceof Element ? event.target.closest('a[data-lightbox]') : null;

    if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    if (!document.querySelector('[data-lightbox-viewer]')) return;

    event.preventDefault();
    window.dispatchEvent(new CustomEvent('lightbox-open', { detail: { link } }));
});

// A live countdown to a deadline; the server already wrote the same sentence, this keeps it current.
const uiCountdown = (iso, prefix) => ({
    text: '',
    urgent: false,
    timer: null,

    init() {
        this.text = this.$el.textContent.trim();
        this.tick();
        this.timer = setInterval(() => this.tick(), 30000);
    },

    destroy() {
        clearInterval(this.timer);
    },

    tick() {
        const seconds = Math.floor((new Date(iso).getTime() - Date.now()) / 1000);

        if (Number.isNaN(seconds)) return;
        this.urgent = seconds < 86400;
        if (seconds <= 0) {
            this.text = 'The deadline has passed';
            return;
        }

        const days = Math.floor(seconds / 86400);
        const hours = Math.floor((seconds % 86400) / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const plural = (n, word) => `${n} ${word}${n === 1 ? '' : 's'}`;
        const parts = days > 0 ? [plural(days, 'day'), plural(hours, 'hour')] : hours > 0 ? [plural(hours, 'hour'), `${minutes} min`] : [`${Math.max(minutes, 1)} min`];

        this.text = `${prefix} in ${parts.join(' ')}`;
    },
});

// Icon-only buttons and links say what they do on hover too: the tooltip is their own accessible name
const addTooltips = (scope = document) => {
    scope.querySelectorAll('button[aria-label]:not([title]), a[aria-label]:not([title])').forEach((el) => {
        if (el.textContent.replace(/\s+/g, '').length <= 24 && !el.querySelector('span:not(.material-symbols-outlined):not(.sr-only):not([aria-hidden])')) {
            el.title = el.getAttribute('aria-label');
        }
    });
};

// Gentle checks while typing (the server still validates): fields marked data-live-check="email|mobile"
const LIVE = {
    email: [(value) => /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(value), 'This does not look like an email address yet, for example name@example.com.'],
    mobile: [(value) => /^(?:\+?63|0)9\d{9}$/.test(value.replace(/[\s().-]/g, '')), 'Type a Philippine mobile number, for example 0917 123 4567.'],
};

const liveCheck = (field) => {
    const rule = LIVE[field.dataset.liveCheck];

    if (!rule) return;

    const note = document.createElement('p');
    note.className = 'mt-1.5 hidden text-sm text-status-amber';
    note.setAttribute('aria-live', 'polite');
    (field.closest('.ui-field') ?? field.parentElement).appendChild(note);

    let touched = false;
    const check = () => {
        const value = field.value.trim();
        const bad = touched && value !== '' && !rule[0](value);

        note.textContent = bad ? rule[1] : '';
        note.classList.toggle('hidden', !bad);
    };

    field.addEventListener('blur', () => {
        touched = true;
        check();
    });
    field.addEventListener('input', check);
};

const whenReady = (run) => (document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', run) : run());

whenReady(() => {
    addTooltips();
    document.querySelectorAll('[data-live-check]').forEach(liveCheck);
});

// Phase 10M: a sidebar menu that scrolls without a visible scrollbar (.sidebar-scroll in app.css). A soft fade shows at
// the top or the bottom only while there is more menu in that direction (data-fade), and the current page's item is
// brought into view when the page opens. Wheel, touchpad, touch and keyboard scrolling are the browser's own.
// x-init="$sidebarScroll($el)": an x-init helper, not x-data, so it adds no Alpine scope (the slide-in menu's panel is
// the drawer's own x-ref and must stay in the drawer's scope)
const sidebarScroll = (el) => {
    let centered = false;

    // The current page's item: scrolled to the middle when it is out of view, once, as soon as the menu has a size
    // (at once for the sidebar; when it first opens for the slide-in menu, which is hidden on page load)
    const center = () => {
        const current = el.querySelector('[aria-current="page"]');

        centered = true;
        if (!current) return;
        const box = el.getBoundingClientRect();
        const item = current.getBoundingClientRect();

        if (item.bottom > box.bottom || item.top < box.top) {
            el.scrollTop += item.top - box.top - (box.height - item.height) / 2;
        }
    };

    const update = () => {
        if (!centered && el.clientHeight > 0) center();
        const fades = [];

        if (el.scrollTop > 1) fades.push('top');
        if (el.scrollTop + el.clientHeight < el.scrollHeight - 1) fades.push('bottom');
        el.dataset.fade = fades.join(' ');
    };

    el.addEventListener('scroll', update, { passive: true });
    if ('ResizeObserver' in window) new ResizeObserver(update).observe(el);
    window.addEventListener('resize', update, { passive: true });
    update();
};

// The owner sidebar: folded to icons or open, remembered in this browser only (a convenience, nothing more)
const sidebarToggle = () => ({
    collapsed: root.classList.contains('sidebar-collapsed'),

    toggle() {
        this.collapsed = !this.collapsed;
        root.classList.toggle('sidebar-collapsed', this.collapsed);
        store.set('owner-sidebar', this.collapsed ? 'collapsed' : 'open');
    },
});

// Live updates by polling (no WebSockets). Every 15 seconds while the tab is visible the page asks its role's cheap
// endpoint for a version stamp. When the stamp changed: the bell is refreshed, the newest notification is shown as a
// toast, and the parts of the page marked data-live are fetched again from the SAME address and swapped in. A page
// that is being typed in, or has a dialog open, is never swapped: it gets "Updated - Refresh" instead.
const liveUpdates = (url) => ({
    stamp: null,
    unread: null,
    wait: 15000,
    timer: null,
    dirty: false,
    busy: false,

    init() {
        // Typing anywhere in the page content marks it as being edited (search boxes and reply forms included)
        document.addEventListener('input', (event) => {
            if (event.target instanceof Element && event.target.closest('main')) this.dirty = true;
        });
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') this.poll();
        });
        this.schedule();
        this.poll();
    },

    schedule() {
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.poll(), this.wait);
    },

    async poll() {
        if (document.visibilityState !== 'visible' || this.busy) {
            this.schedule();
            return;
        }

        this.busy = true;
        try {
            const response = await fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', cache: 'no-store' });

            if (!response.ok) throw new Error(String(response.status));

            const data = await response.json();

            this.wait = 15000;
            // Phase 10K: a new notification since the last poll (never on the first poll of a page, so a reload is quiet)
            if (this.unread !== null && data.unread > this.unread) chime.play();
            if (this.stamp !== null && data.stamp !== this.stamp) {
                await this.changed(data);
            }
            this.stamp = data.stamp;
            this.unread = data.unread;
        } catch (error) {
            // Signed out, offline or throttled: ask less often, up to every two minutes, and never show an error
            this.wait = Math.min(this.wait * 2, 120000);
        } finally {
            this.busy = false;
            this.schedule();
        }
    },

    async changed(data) {
        if (this.unread !== null && data.unread > this.unread && data.latest?.message) {
            toast({ message: data.latest.message, variant: 'info', timeout: 7000, link: data.latest.url ? { label: 'View', url: data.latest.url } : null });
        }

        const regions = [...document.querySelectorAll('[data-live]')];
        const page = regions.find((region) => region.dataset.live === 'page');
        const editing = this.dirty || root.classList.contains('modal-open') || (document.activeElement instanceof Element && document.activeElement.matches('input, textarea, select'));

        if (page && editing) {
            toast({ message: 'This page was updated.', variant: 'info', timeout: 12000, link: { label: 'Refresh', url: window.location.pathname + window.location.search } });
        }

        const targets = regions.filter((region) => region !== page || !editing).filter((region) => !region.querySelector('[aria-expanded="true"]'));

        if (targets.length === 0) return;

        targets.forEach((region) => region.classList.add('live-refreshing'));
        try {
            const response = await fetch(window.location.href, { headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', cache: 'no-store' });

            if (!response.ok || response.redirected) return;

            const fresh = new DOMParser().parseFromString(await response.text(), 'text/html');

            targets.forEach((region) => {
                const next = fresh.querySelector(`[data-live="${region.dataset.live}"]`);
                // Asked again here: someone may have started typing while the page was on its way
                const nowEditing = region === page && (this.dirty || root.classList.contains('modal-open'));

                if (next && !nowEditing && next.innerHTML !== region.innerHTML) {
                    region.innerHTML = next.innerHTML;
                    addTooltips(region);
                }
            });
        } catch (error) {
            // The next poll tries again
        } finally {
            targets.forEach((region) => region.classList.remove('live-refreshing'));
        }
    },
});

// A one-time hint: closed once, it stays closed in this browser
const uiHint = (name) => ({
    show: store.get(`hint-${name}`) !== 'seen',

    dismiss() {
        this.show = false;
        store.set(`hint-${name}`, 'seen');
    },
});

// Phase 10E: "Check your email" asks every 5 seconds (only while the tab is visible) whether the account was verified
// on another device, and moves on to the dashboard when it was. Errors simply wait longer.
const verifyWatch = (statusUrl, nextUrl) => ({
    timer: null,
    wait: 5000,

    init() {
        this.schedule();
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') this.check();
        });
    },

    schedule() {
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.check(), this.wait);
    },

    async check() {
        if (document.visibilityState !== 'visible') {
            this.schedule();
            return;
        }
        try {
            const response = await fetch(statusUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' });
            if (!response.ok) throw new Error(String(response.status));
            const data = await response.json();
            if (data.verified === true) {
                window.location.assign(nextUrl);
                return;
            }
            this.wait = 5000;
        } catch (error) {
            this.wait = Math.min(this.wait * 2, 60000);
        }
        this.schedule();
    },
});

// Phase 10F: the search box with suggestions (<x-ui.typeahead>). One request in flight at a time; an older answer
// never replaces a newer one; the highlight is added AFTER escaping, so nothing from the server becomes markup.
const uiTypeahead = (url) => ({
    open: false,
    loading: false,
    items: [],
    active: -1,
    term: '',
    timer: null,
    asked: 0,

    typed(focusOnly = false) {
        const term = this.$refs.input.value.trim();

        if (focusOnly && (term === '' || this.items.length === 0)) return;
        clearTimeout(this.timer);
        if (term === '') {
            this.close();
            this.items = [];
            return;
        }
        this.timer = setTimeout(() => this.ask(term), 200);
    },

    async ask(term) {
        const ticket = ++this.asked;
        this.loading = true;
        this.term = term;
        this.open = true;
        try {
            const response = await fetch(`${url}${url.includes('?') ? '&' : '?'}q=${encodeURIComponent(term)}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin',
            });
            if (ticket !== this.asked) return;
            const data = response.ok ? await response.json() : { items: [] };
            this.items = Array.isArray(data.items) ? data.items : [];
            this.active = -1;
        } catch (error) {
            if (ticket === this.asked) this.items = [];
        } finally {
            if (ticket === this.asked) this.loading = false;
        }
    },

    close() {
        this.open = false;
        this.active = -1;
    },

    key(event) {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            if (!this.open && this.items.length) this.open = true;
            if (!this.items.length) return;
            event.preventDefault();
            const step = event.key === 'ArrowDown' ? 1 : -1;
            this.active = (this.active + step + this.items.length) % this.items.length;
            this.$nextTick(() => document.getElementById(this.$refs.input.getAttribute('aria-controls') + '')?.children[this.active]?.scrollIntoView({ block: 'nearest' }));
        } else if (event.key === 'Enter' && this.open && this.active >= 0) {
            // Enter on a highlighted suggestion opens it; with nothing highlighted the form is submitted as always
            event.preventDefault();
            this.choose(this.items[this.active]);
        } else if (event.key === 'Escape' && this.open) {
            event.stopPropagation();
            this.close();
        }
    },

    choose(item) {
        if (!item) return;
        this.close();

        // Starting a conversation is a POST: a small form with the CSRF token, never a GET with side effects
        if (item.post && typeof item.post.url === 'string' && item.post.url.startsWith(window.location.origin + '/')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = item.post.url;
            const fields = { _token: csrf(), ...(item.post.fields ?? {}) };
            Object.entries(fields).forEach(([name, value]) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = String(value);
                form.appendChild(input);
            });
            document.body.appendChild(form);
            form.submit();
            return;
        }

        if (typeof item.url === 'string' && (item.url.startsWith('/') || item.url.startsWith(window.location.origin + '/')) && !item.url.startsWith('//')) {
            window.location.assign(item.url);
        }
    },

    // The typed text, highlighted, in escaped text
    mark(text) {
        const safe = String(text ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
        const term = this.term.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
        if (term === '') return safe;
        // The start of a word first (what one letter matched on the server), else anywhere
        const lower = safe.toLowerCase();
        const needle = term.toLowerCase();
        let at = -1;
        for (let i = lower.indexOf(needle); i >= 0; i = lower.indexOf(needle, i + 1)) {
            if (i === 0 || /[\s\-·(]/.test(lower[i - 1])) {
                at = i;
                break;
            }
        }
        if (at < 0) at = lower.indexOf(needle);
        if (at < 0) return safe;

        return `${safe.slice(0, at)}<mark class="rounded bg-brand/15 px-0.5 text-inherit dark:bg-[#ffb4ac]/20">${safe.slice(at, at + term.length)}</mark>${safe.slice(at + term.length)}`;
    },
});

// Phase 10N: a horizontal tab or pill bar without a visible scrollbar (.scroll-x-fade in app.css; the sidebarScroll idea
// of 10M turned sideways). A fade shows on the left or the right only while there are more tabs that way, and the current
// tab (aria-current or aria-selected) is brought into view once the bar has a size. x-init="$scrollFade($el)": no scope
const scrollFade = (el) => {
    let placed = false;

    const place = () => {
        const current = el.querySelector('[aria-current="page"], [aria-current="true"], [aria-selected="true"]');

        placed = true;
        if (!current) return;
        const box = el.getBoundingClientRect();
        const item = current.getBoundingClientRect();

        if (item.right > box.right || item.left < box.left) {
            el.scrollLeft += item.left - box.left - (box.width - item.width) / 2;
        }
    };

    const update = () => {
        if (!placed && el.clientWidth > 0) place();
        const fades = [];

        if (el.scrollLeft > 1) fades.push('left');
        if (el.scrollLeft + el.clientWidth < el.scrollWidth - 1) fades.push('right');
        el.dataset.fade = fades.join(' ');
    };

    el.addEventListener('scroll', update, { passive: true });
    if ('ResizeObserver' in window) new ResizeObserver(update).observe(el);
    requestAnimationFrame(update);
};

// Phase 10N: the chosen file name(s) under <x-ui.file-button> ("No files yet" before). One listener for the whole page,
// no Alpine scope (the wizards keep their own x-ref on the input); it reads the input that the button's label points at
const fileButtons = () => {
    document.addEventListener('change', (event) => {
        const input = event.target;
        if (!(input instanceof HTMLInputElement) || input.type !== 'file' || !input.id) return;
        const label = input.parentElement?.querySelector(`[data-file-button][for="${CSS.escape(input.id)}"]`);
        const line = label?.parentElement?.querySelector('[data-file-chosen]');
        if (!line) return;
        const names = [...(input.files ?? [])].map((file) => file.name);
        line.textContent = names.length === 0 ? line.dataset.empty : names.length === 1 ? names[0] : `${names.length} files chosen`;
        line.title = names.join(', ');
    });
};

export default function registerPolish(Alpine) {
    fileButtons();
    Alpine.magic('linkify', () => linkify);
    Alpine.data('uiTimePicker', uiTimePicker);
    Alpine.data('uiFilterSheet', uiFilterSheet);
    Alpine.data('uiConfirm', uiConfirm);
    Alpine.data('uiLightbox', uiLightbox);
    Alpine.data('uiCountdown', uiCountdown);
    Alpine.data('sidebarToggle', sidebarToggle);
    Alpine.magic('sidebarScroll', () => sidebarScroll);
    Alpine.magic('scrollFade', () => scrollFade);
    Alpine.data('liveUpdates', liveUpdates);
    Alpine.data('uiHint', uiHint);
    Alpine.data('soundSwitch', soundSwitch);
    Alpine.data('verifyWatch', verifyWatch);
    Alpine.data('uiTypeahead', uiTypeahead);
}

export { chime, csrf, reduced, root, store, toast };
