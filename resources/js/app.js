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

// The address (slug) for a name, as the server's Str::slug would make it (accents removed)
const slugify = (text) => text
    .toLowerCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');

// Fills the address (slug) from the name until the owner edits the address by hand; an existing service keeps its address.
Alpine.data('serviceForm', ({ name, slug, locked }) => ({
    // A new service has no name or address yet (the server passes null), so the live preview must not see null
    name: name ?? '',
    slug: slug ?? '',
    locked,

    // What the live preview shows. Read from the form itself, so the preview never keeps a second copy of the data.
    preview: { category: '', description: '', pricing: '', pricingLabel: '', price: '', types: [], published: false },

    init() {
        this.syncPreview();
    },

    syncPreview() {
        const form = this.$root;
        const pricing = form.querySelector('[name="pricing_type"]:checked');

        this.preview = {
            category: form.elements.category?.value.trim() ?? '',
            description: form.elements.description?.value.trim() ?? '',
            pricing: pricing?.value ?? '',
            pricingLabel: pricing?.dataset.label ?? '',
            price: form.elements.base_price?.value ?? '',
            types: [...form.querySelectorAll('[name="allowed_file_types[]"]:checked')].map((box) => box.value),
            published: form.elements.is_published?.checked ?? false,
        };
    },

    // Display only. The server's App\Support\Money is the one place that formats stored money.
    money(value) {
        const amount = parseFloat(value);

        return Number.isFinite(amount) ? new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(amount) : '';
    },

    fillSlug() {
        if (this.locked) return;

        this.slug = slugify(this.name);
    },
}));

// Same idea as serviceForm, for the package form: fills the address from the name and drives the live preview
// from the form's own fields. Display only: the server validates and stores everything.
Alpine.data('packageForm', ({ name, slug, locked, coverUrl, coverAlt }) => ({
    name: name ?? '',
    slug: slug ?? '',
    locked,
    coverUrl,
    coverAlt,
    preview: { category: '', shortDescription: '', price: '', requiresConfirmation: false, duration: '', people: '', inclusions: [], themes: [], published: false, featured: false },

    init() {
        this.syncPreview();
    },

    syncPreview() {
        const form = this.$root;

        this.preview = {
            category: form.elements.category?.value.trim() ?? '',
            shortDescription: form.elements.short_description?.value.trim() ?? '',
            price: form.elements.price?.value ?? '',
            requiresConfirmation: form.elements.price_requires_confirmation?.checked ?? false,
            duration: form.elements.duration_minutes?.value ?? '',
            people: form.elements.max_people?.value ?? '',
            inclusions: (form.elements.inclusions?.value ?? '').split(/\r?\n/).map((line) => line.trim()).filter(Boolean),
            themes: [...form.querySelectorAll('[name="theme_ids[]"]:checked')].map((box) => ({ name: box.dataset.name, swatches: JSON.parse(box.dataset.swatches || '[]') })),
            published: form.elements.is_published?.checked ?? false,
            featured: form.elements.is_featured?.checked ?? false,
        };
    },

    // Display only. The wording mirrors PhotographyPackage::priceText() on the server, which is what customers see;
    // the peso format here is Intl, while the server's App\Support\Money formats stored money.
    priceText() {
        const amount = parseFloat(this.preview.price);

        if (! Number.isFinite(amount)) return 'Price on request';

        const price = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(amount);

        return this.preview.requiresConfirmation ? `From ${price} — final price confirmed after review` : price;
    },

    fillSlug() {
        if (this.locked) return;

        this.slug = slugify(this.name);
    },
}));

// The theme form: the color slots and the live preview of the swatches. The color picker and the hex field of a slot
// stay in step; blank slots are ignored by the server. Display only: the server validates every color.
Alpine.data('themeForm', ({ name, colors, imageUrl }) => ({
    name: name ?? '',
    colors,
    imageUrl,
    description: '',
    published: false,

    init() {
        this.sync();
    },

    sync() {
        const form = this.$root;

        this.description = form.elements.description?.value.trim() ?? '';
        this.published = form.elements.is_published?.checked ?? false;
    },

    // A hex field that is not a full #RRGGBB yet shows nothing in the preview and gives the picker a neutral value
    valid(color) {
        return /^#[0-9a-fA-F]{6}$/.test((color ?? '').trim());
    },

    get swatches() {
        return this.colors.filter((color) => this.valid(color)).map((color) => color.trim().toUpperCase());
    },
}));

// Live password hints for the register and reset pages. The rules (ids, labels, minimum) come from the server
// (App\Support\PasswordRules); only the checks are written here. Advisory only: the server still decides.
// Reads the inputs by name instead of x-model, so a value filled by a password manager is picked up and never overwritten.
Alpine.data('passwordStrength', (rules) => ({
    rules,
    password: '',
    confirm: '',

    // Same checks as Laravel's Password rule: length counts characters, letters and numbers are Unicode-aware
    checks: {
        length: (value, rule) => [...value].length >= rule.min,
        lower: (value) => /\p{Ll}/u.test(value),
        upper: (value) => /\p{Lu}/u.test(value),
        number: (value) => /\p{N}/u.test(value),
    },

    init() {
        const read = () => {
            this.password = this.$root.querySelector('[name="password"]')?.value ?? '';
            this.confirm = this.$root.querySelector('[name="password_confirmation"]')?.value ?? '';
        };

        this.$nextTick(read);
        this.$root.addEventListener('input', read);
        this.$root.addEventListener('change', read);
    },

    get results() {
        // An id without a check here counts as not met, so a rule added on the server never looks satisfied by mistake
        return this.rules.map((rule) => ({ ...rule, met: (this.checks[rule.id] ?? (() => false))(this.password, rule) }));
    },

    get metCount() {
        return this.results.filter((rule) => rule.met).length;
    },

    get tier() {
        if (this.metCount === this.rules.length) return 'strong';

        return this.metCount >= this.rules.length - 1 ? 'medium' : 'weak';
    },

    get summary() {
        const missing = this.rules.length - this.metCount;
        const left = `${missing} ${missing === 1 ? 'requirement' : 'requirements'} missing`;

        return { weak: `Weak password: ${left}`, medium: `Almost there: ${left}`, strong: 'Strong password: all requirements met' }[this.tier];
    },

    get matches() {
        return this.confirm !== '' && this.confirm === this.password;
    },
}));

const reducedMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

// The compare bar of the packages list. It is plain HTML first (the form works without scripts); once Alpine runs, the bar
// slides away while nothing is ticked and shows what is ticked. Names and photos are read from the ticked checkboxes.
Alpine.data('packageCompare', (max = 3) => ({
    max,
    picked: 0,
    names: [],
    covers: [],
    ready: false,

    init() {
        this.count();
        // Switch the slide transition on only after the first state is applied, so the bar does not fade out on every load
        setTimeout(() => (this.ready = true), 100);
    },

    count() {
        const ticked = [...this.$root.querySelectorAll('input[name="packages[]"]:checked')];

        this.picked = ticked.length;
        this.names = ticked.map((box) => box.dataset.name);
        this.covers = ticked.map((box) => box.dataset.cover).filter(Boolean).slice(0, 3);
    },

    clear() {
        this.$root.reset();
        this.count();
        // The button disappears with the selection, so keep keyboard focus on the list instead of dropping it to the page
        this.$root.querySelector('input[name="packages[]"]')?.focus();
    },
}));

// The photo gallery of a package. The first photo is plain HTML, so the page works without scripts. Switching photos
// dips the main photo to 70% for 150ms, as in the design, unless motion is reduced.
Alpine.data('packageGallery', (slides) => ({
    slides,
    i: 0,
    dim: false,
    dimTimer: null,

    go(n) {
        this.show((n + this.slides.length) % this.slides.length);
    },

    show(n) {
        if (n === this.i) return;

        this.i = n;

        if (reducedMotion()) return;

        this.dim = true;
        clearTimeout(this.dimTimer);
        this.dimTimer = setTimeout(() => (this.dim = false), 150);
    },
}));

Alpine.data('uiPageLoader', () => ({
    show: true,
    pct: 0,
    ticker: null,
    hider: null,

    get status() {
        return this.pct >= 90 ? 'Almost ready...' : 'Preparing your page...';
    },

    init() {
        // JS is running, so cancel the CSS failsafe that hides the loader when scripts fail
        this.$el.style.animation = 'none';

        if (root.classList.contains('loader-seen')) {
            this.show = false;

            return;
        }

        try {
            sessionStorage.setItem('jhunlea-loader-seen', '1');
        } catch (e) {
            // storage unavailable: the loader simply shows on every page
        }

        this.run(document.readyState === 'complete' ? Promise.resolve() : new Promise((done) => window.addEventListener('load', done, { once: true })));
    },

    // The bar eases toward 90% while the page loads, jumps to 100% when it has loaded, then the loader fades out
    run(loaded) {
        this.pct = 0;
        clearInterval(this.ticker);
        clearTimeout(this.hider);
        this.ticker = setInterval(() => (this.pct += (90 - this.pct) * 0.1), 80);

        // A stalled font or image must not keep the page covered: stop waiting after 4s
        Promise.race([loaded, new Promise((done) => setTimeout(done, 4000))]).then(() => {
            clearInterval(this.ticker);
            this.pct = 100;
            this.hider = setTimeout(() => (this.show = false), 600);
        });
    },

    replay() {
        root.classList.remove('loader-seen');
        this.show = true;
        this.run(new Promise((done) => setTimeout(done, 1200)));
    },
}));

Alpine.start();
