import Alpine from 'alpinejs';

window.Alpine = Alpine;

const root = document.documentElement;

document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
        const dark = root.classList.toggle('dark');

        try {
            localStorage.setItem('theme', dark ? 'dark' : 'light');
        } catch (e) {
            // storage unavailable; theme still applies for this page view
        }
    });
});

const FOCUSABLE =
    'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

// Open with $dispatch('open-modal', name), close with $dispatch('close-modal').
Alpine.data('uiModal', (name) => ({
    open: false,
    trigger: null,

    focusables() {
        return [...this.$refs.panel.querySelectorAll(FOCUSABLE)].filter((el) => el.offsetParent !== null);
    },

    show(event) {
        if (event.detail !== name || this.open) return;

        // Safari and Firefox on Mac do not focus a button on click, so prefer the element that fired the event
        this.trigger = event.target instanceof HTMLElement ? event.target : document.activeElement;
        this.open = true;
        root.classList.add('overflow-hidden');
        this.$nextTick(() => (this.focusables()[0] ?? this.$refs.panel).focus());
    },

    hide() {
        if (!this.open) return;

        this.open = false;
        root.classList.remove('overflow-hidden');
        this.trigger?.focus();
    },

    // Pull focus back if it ends up behind the dialog
    guard(event) {
        if (this.open && !this.$refs.panel.contains(event.target)) {
            (this.focusables()[0] ?? this.$refs.panel).focus();
        }
    },

    trap(event) {
        if (!this.open) return;

        const items = this.focusables();

        if (items.length === 0) {
            event.preventDefault();
            return;
        }

        const first = items[0];
        const last = items[items.length - 1];

        if (event.shiftKey && (document.activeElement === first || document.activeElement === this.$refs.panel)) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    },
}));

// Add a toast with $dispatch('toast', { message, variant: 'success|error|info', timeout }).
Alpine.data('uiToasts', (initial = []) => ({
    items: [],
    nextId: 0,

    init() {
        initial.forEach((toast) => this.add(toast));
    },

    add({ message, variant = 'info', timeout = 5000 }) {
        const id = ++this.nextId;

        this.items.push({ id, message, variant, open: false });
        // Flip to open on the next tick so the enter transition runs
        this.$nextTick(() => {
            const toast = this.items.find((item) => item.id === id);
            if (toast) toast.open = true;
        });
        setTimeout(() => this.dismiss(id), timeout);
    },

    dismiss(id) {
        const toast = this.items.find((item) => item.id === id);
        if (!toast || !toast.open) return;

        toast.open = false;
        setTimeout(() => (this.items = this.items.filter((item) => item.id !== id)), 300);
    },
}));

Alpine.data('uiPageLoader', () => ({
    show: true,

    init() {
        // JS is running, so cancel the CSS failsafe that hides the loader when scripts fail
        this.$el.style.animation = 'none';

        const hide = () => setTimeout(() => (this.show = false), 700);

        if (document.readyState === 'complete') {
            hide();
        } else {
            window.addEventListener('load', hide, { once: true });
        }
    },

    replay() {
        // Restart the opening animation, which has already run once
        this.$el.querySelectorAll('.animate-aperture-open, .animate-fade-up').forEach((el) => {
            el.style.animation = 'none';
            void el.offsetWidth;
            el.style.animation = '';
        });
        this.show = true;
        setTimeout(() => (this.show = false), 1400);
    },
}));

Alpine.start();
