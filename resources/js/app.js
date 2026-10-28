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

// The printing order wizard. The four steps are sections of one plain form, so it also works without scripts; here they
// open one at a time. The checks below only help the customer: the server checks every step again on submit. The
// estimate comes from the server (PricingService), never from arithmetic in the browser.
Alpine.data('printingWizard', ({ start, rules, estimateUrl, maxFiles, maxTotalMb, maxQuantity }) => ({
    step: start,
    reached: start,
    serviceId: null,
    perPage: false,
    files: [],
    dragging: false,
    problem: '',
    submitting: false,
    summary: { service: '', options: [], quantity: '', pages: '', notes: '' },
    estimate: null,
    estimateState: 'idle',
    estimateProblem: '',
    estimateTimer: null,
    estimateRequest: null,
    estimateSeq: 0,

    init() {
        this.sync();
    },

    get rule() {
        return rules[this.serviceId] ?? null;
    },

    // Reads the form again after any change: which service is ticked, the summary, the file list and the estimate
    sync() {
        const ticked = this.$root.querySelector('input[name="service"]:checked');

        this.serviceId = ticked ? Number(ticked.value) : null;
        this.perPage = Boolean(this.rule?.perPage);
        this.readFiles();
        // Options of other services are disabled through x-bind; wait for that before reading the form
        this.$nextTick(() => {
            this.collect();
            this.queueEstimate();
        });
    },

    collect() {
        const ticked = this.$root.querySelector('input[name="service"]:checked');
        const options = this.serviceId ? [...this.$root.querySelectorAll(`[data-service-options="${this.serviceId}"] input:checked`)] : [];

        this.summary = {
            service: ticked?.dataset.name ?? '',
            options: options.map((input) => input.dataset.label),
            quantity: this.$refs.quantity?.value ?? '',
            pages: this.$root.querySelector('#pages')?.value ?? '',
            notes: (this.$root.querySelector('#notes')?.value ?? '').trim(),
        };
    },

    changeCopies(by) {
        const input = this.$refs.quantity;
        const current = parseInt(input.value, 10) || 1;

        input.value = Math.min(Math.max(current + by, 1), maxQuantity);
        this.sync();
    },

    // Steps

    next() {
        if (this.check(this.step)) this.goTo(this.step + 1, true);
    },

    back() {
        this.goTo(this.step - 1);
    },

    goTo(n, checked = false) {
        if (n < 1 || n > 4) return;
        // A later step opens only through Continue, so every step before it has been checked
        if (n > this.reached && !checked) return;

        this.problem = '';
        this.step = n;
        this.reached = Math.max(this.reached, n);

        // x-show shows the new step a frame or two later (after the old one is hidden), so wait until it is on screen
        // before moving focus into it; give up after about half a second
        const section = this.$root.querySelector(`#step-${n}`);
        let tries = 30;
        const reveal = () => {
            if (this.step !== n) return;
            if (section.offsetParent === null && tries-- > 0) return requestAnimationFrame(reveal);

            if (section.getBoundingClientRect().top < 0) {
                section.scrollIntoView({ behavior: reducedMotion() ? 'auto' : 'smooth', block: 'start' });
            }

            section.querySelector(`#step-${n}-title`)?.focus({ preventScroll: true });
        };

        requestAnimationFrame(reveal);
    },

    check(step) {
        const quantity = parseInt(this.$refs.quantity.value, 10);
        const pages = parseInt(this.$root.querySelector('#pages')?.value ?? '', 10);
        const problems = {
            1: !this.serviceId ? 'Choose a printing service to continue.' : '',
            2: !(quantity >= 1 && quantity <= maxQuantity)
                ? `Enter a number of copies from 1 to ${maxQuantity}.`
                : this.perPage && !(pages >= 1)
                  ? 'Enter the number of pages in your file.'
                  : this.estimateState === 'invalid'
                    ? this.estimateProblem
                    : '',
            3: !this.files.length
                ? 'Add at least one file to print.'
                : this.files.length > maxFiles
                  ? `You can send up to ${maxFiles} files with one order.`
                  : this.files.some((file) => file.error)
                    ? 'Remove the files that cannot be sent first.'
                    : this.overTotal()
                      ? this.totalProblem()
                      : '',
        };

        this.problem = problems[step] ?? '';

        return this.problem === '';
    },

    // Enter in a field before the last step moves on instead of sending a half-filled order
    submit(event) {
        if (this.step < 4) {
            event.preventDefault();
            this.next();

            return;
        }

        for (const step of [1, 2, 3]) {
            if (!this.check(step)) {
                event.preventDefault();
                this.goTo(step);

                return;
            }
        }

        this.submitting = true;
    },

    // Files

    readFiles() {
        const picked = [...(this.$refs.files?.files ?? [])];

        this.files = picked.map((file) => ({ name: file.name, bytes: file.size, size: this.size(file.size), error: this.fileProblem(file) }));
    },

    totalBytes() {
        return this.files.reduce((sum, file) => sum + file.bytes, 0);
    },

    // Same limit and wording as the server, which checks it again on submit
    overTotal() {
        return this.totalBytes() > maxTotalMb * 1024 * 1024;
    },

    totalProblem() {
        const size = (Math.ceil((this.totalBytes() / 1024 / 1024) * 10) / 10).toFixed(1);

        return `Your files together are ${size} MB. The most one order can hold is ${maxTotalMb} MB. Please remove or compress some files.`;
    },

    fileProblem(file) {
        if (!this.rule) return 'Choose a service first.';

        const extension = file.name.includes('.') ? file.name.split('.').pop().toLowerCase() : '';

        if (!this.rule.extensions.includes(extension)) return `This service accepts only ${this.typesText()} files.`;
        if (file.size === 0) return 'The file is empty.';
        if (file.size > this.rule.maxKb * 1024) return `The file is too large. The most this service accepts is ${this.rule.maxKb / 1024} MB.`;

        return '';
    },

    // A file list can only be changed by building a new one
    replaceFiles(list) {
        const transfer = new DataTransfer();

        list.forEach((file) => transfer.items.add(file));
        this.$refs.files.files = transfer.files;
        this.sync();
    },

    removeFile(index) {
        this.replaceFiles([...this.$refs.files.files].filter((file, i) => i !== index));
        this.$refs.files.focus();
    },

    dropFiles(event) {
        this.dragging = false;
        this.replaceFiles([...this.$refs.files.files, ...event.dataTransfer.files]);
    },

    size(bytes) {
        return bytes >= 1024 * 1024 ? `${(bytes / 1024 / 1024).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;
    },

    typesText() {
        return [...new Set(this.rule?.extensions.filter((extension) => extension !== 'jpeg') ?? [])].map((extension) => extension.toUpperCase()).join(', ');
    },

    acceptList() {
        return this.rule ? this.rule.extensions.map((extension) => `.${extension}`).join(',') : this.$refs.files.getAttribute('accept');
    },

    acceptText() {
        return this.rule
            ? `Accepted: ${this.typesText()} · up to ${this.rule.maxKb / 1024} MB per file · up to ${maxFiles} files and ${maxTotalMb} MB in total.`
            : `Choose a service in step 1 to see which files it accepts. Up to ${maxFiles} files and ${maxTotalMb} MB in total per order.`;
    },

    // Estimate

    queueEstimate() {
        clearTimeout(this.estimateTimer);
        // An answer still on its way is for the old choices: drop it so it cannot show an outdated total
        this.estimateRequest?.abort();
        this.estimateSeq++;

        if (!this.serviceId) {
            this.estimate = null;
            this.estimateState = 'idle';

            return;
        }

        this.estimateState = 'loading';
        this.estimateTimer = setTimeout(() => this.fetchEstimate(), 250);
    },

    async fetchEstimate() {
        const params = new URLSearchParams({ service: this.serviceId, quantity: this.$refs.quantity.value });

        if (this.perPage) params.set('pages', this.$root.querySelector('#pages').value);
        this.$root.querySelectorAll(`[data-service-options="${this.serviceId}"] input:checked`).forEach((input) => params.append(input.name, input.value));

        const seq = this.estimateSeq;

        this.estimateRequest?.abort();
        this.estimateRequest = new AbortController();

        try {
            const response = await fetch(`${estimateUrl}?${params}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                signal: this.estimateRequest.signal,
            });
            const body = await response.json().catch(() => ({}));

            if (seq !== this.estimateSeq) return;

            if (response.ok) {
                this.estimate = body;
                this.estimateState = 'ready';
            } else if (response.status === 422) {
                this.estimate = null;
                this.estimateState = 'invalid';
                this.estimateProblem = Object.values(body.errors ?? {}).flat()[0] ?? 'Check your choices to see an estimate.';
            } else {
                this.estimateState = 'offline';
            }
        } catch (error) {
            if (error.name !== 'AbortError' && seq === this.estimateSeq) this.estimateState = 'offline';
        }
    },

    estimateHeadline() {
        if (this.estimate && this.estimateState !== 'invalid') return this.estimate.total_text;

        return this.estimateState === 'invalid' ? this.estimateProblem : 'Worked out when you send the order';
    },
}));

// The photoshoot booking wizard. The six steps are sections of one plain form, so it also works without scripts; here
// they open one at a time. Free times come from the server (AvailabilityService through the slots endpoint): the browser
// only draws them, it never works out availability. The checks below only help the customer, using the server's own
// words; the server checks every step again on submit.
const pad = (n) => String(n).padStart(2, '0');
const ymd = (y, m, d) => `${y}-${pad(m + 1)}-${pad(d)}`;
// Calendar days are plain dates (no time, no time zone), so they are read and named in UTC
const dayName = new Intl.DateTimeFormat('en-US', { weekday: 'long', month: 'long', day: 'numeric', timeZone: 'UTC' });
const monthName = new Intl.DateTimeFormat('en-US', { month: 'long', year: 'numeric', timeZone: 'UTC' });

Alpine.data('bookingWizard', ({ start, packages, slotsUrl, bookableFrom, bookableUntil, oldSlot, images, messages, atLimit }) => ({
    step: start,
    reached: start,
    packageId: null,
    problem: '',
    submitting: false,
    atLimit,
    lengths: { notes: 0, idea: 0 },
    summary: { package: '', price: '', minutes: '', date: '', time: '', people: '', contact: '', theme: '', idea: '', prints: '', notes: '' },

    // Calendar: the month on screen, the free times by package and month, and the chosen day and time
    month: { y: 0, m: 0 },
    days: {},
    slotState: 'idle',
    bookableFrom,
    bookableUntil,
    selectedDate: '',
    dayList: [],
    selected: '',
    selectedSlot: null,
    slotRequest: null,
    slotSeq: 0,

    images: [],
    dragging: false,

    init() {
        if (oldSlot) {
            this.selected = oldSlot.starts_at;
            this.selectedSlot = oldSlot;
            this.selectedDate = oldSlot.date;
        }

        const anchor = oldSlot?.date ?? this.bookableFrom;

        this.month = { y: Number(anchor.slice(0, 4)), m: Number(anchor.slice(5, 7)) - 1 };
        this.sync();
    },

    get pkg() {
        return packages[this.packageId] ?? null;
    },

    // Reads the form again after any change: the package, then the summary
    sync() {
        const ticked = this.$root.querySelector('input[name="package"]:checked');
        const id = ticked ? Number(ticked.value) : null;

        if (id !== this.packageId) {
            // The first read keeps a time chosen before a failed submit; a new package needs a new time
            if (this.packageId !== null) this.clearSlot();
            this.packageId = id;
            this.clampPeople();
            this.loadMonth();
        }

        // Backdrops of other packages are disabled through x-bind; wait for that before reading the form
        this.$nextTick(() => this.collect());
    },

    collect() {
        const form = this.$root;
        const pkg = this.pkg;
        const theme = this.packageId ? form.querySelector(`[data-themes-for="${this.packageId}"] input[name="theme"]:checked`) : null;
        const prints = form.querySelector('input[name="prints"]:checked');
        const people = parseInt(this.$refs.people?.value ?? '', 10);
        const notes = form.querySelector('#notes')?.value ?? '';
        const idea = form.querySelector('#custom_theme_text')?.value ?? '';

        this.lengths = { notes: notes.length, idea: idea.length };
        this.summary = {
            package: pkg?.name ?? '',
            price: pkg?.price ?? '',
            minutes: pkg ? `${pkg.minutes} mins` : '',
            date: this.selectedSlot?.date_text ?? '',
            time: this.selectedSlot?.time_text ?? '',
            people: people >= 1 ? `${people} ${people === 1 ? 'person' : 'people'}` : '',
            contact: (form.querySelector('#contact_number')?.value ?? '').trim(),
            theme: theme?.dataset.name ?? '',
            idea: idea.trim(),
            prints: prints?.dataset.name ?? '',
            notes: notes.trim(),
        };
    },

    // People

    maxPeople() {
        return this.pkg?.max ?? null;
    },

    groupRule() {
        return this.pkg?.groupRule ?? 'Each package says how many people it fits.';
    },

    changePeople(by) {
        const input = this.$refs.people;

        input.value = Math.max(1, (parseInt(input.value, 10) || 1) + by);
        this.clampPeople();
        this.collect();
    },

    clampPeople() {
        const input = this.$refs.people;
        const max = this.maxPeople();

        if (input && max && parseInt(input.value, 10) > max) input.value = max;
    },

    // Steps

    next() {
        if (this.check(this.step)) this.goTo(this.step + 1, true);
    },

    back() {
        this.goTo(this.step - 1);
    },

    goTo(n, checked = false) {
        if (n < 1 || n > 6) return;
        // A later step opens only through Continue, so every step before it has been checked
        if (n > this.reached && !checked) return;

        this.problem = '';
        this.step = n;
        this.reached = Math.max(this.reached, n);

        // x-show shows the new step a frame or two later, so wait until it is on screen before moving focus into it
        const section = this.$root.querySelector(`#step-${n}`);
        let tries = 30;
        const reveal = () => {
            if (this.step !== n) return;
            if (section.offsetParent === null && tries-- > 0) return requestAnimationFrame(reveal);

            if (section.getBoundingClientRect().top < 0) {
                section.scrollIntoView({ behavior: reducedMotion() ? 'auto' : 'smooth', block: 'start' });
            }

            section.querySelector(`#step-${n}-title`)?.focus({ preventScroll: true });
        };

        requestAnimationFrame(reveal);
    },

    check(step) {
        const people = parseInt(this.$refs.people.value, 10);
        const theme = this.packageId ? this.$root.querySelector(`[data-themes-for="${this.packageId}"] input[name="theme"]:checked`) : null;
        const problems = {
            1: !this.packageId ? messages.package : '',
            2: !(people >= 1)
                ? messages.groupSize
                : this.maxPeople() && people > this.maxPeople()
                  ? this.groupRule()
                  : !(this.$root.querySelector('#contact_number')?.value ?? '').trim()
                    ? messages.contact
                    : '',
            3: !this.selected ? messages.slot : '',
            4: !theme?.value && !this.summary.idea
                ? messages.theme
                : this.images.some((image) => image.error)
                  ? 'Remove the images that cannot be sent first.'
                  : this.imageTotalProblem(),
        };

        this.problem = problems[step] ?? '';

        return this.problem === '';
    },

    // Enter in a field before the last step moves on instead of sending a half-filled request
    submit(event) {
        if (this.step < 6) {
            event.preventDefault();
            this.next();

            return;
        }

        for (const step of [1, 2, 3, 4]) {
            if (!this.check(step)) {
                event.preventDefault();
                this.goTo(step);

                return;
            }
        }

        if (this.atLimit) {
            event.preventDefault();

            return;
        }

        this.submitting = true;
    },

    // Calendar

    monthKey() {
        return `${this.packageId}:${this.month.y}-${this.month.m}`;
    },

    monthSlots() {
        return this.days[this.monthKey()] ?? {};
    },

    monthHasSlots() {
        return Object.keys(this.monthSlots()).length > 0;
    },

    lastDay(y, m) {
        return new Date(Date.UTC(y, m + 1, 0)).getUTCDate();
    },

    monthLabel() {
        return monthName.format(new Date(Date.UTC(this.month.y, this.month.m, 1)));
    },

    // A month can be shown when part of it lies between the first and the last day one may book
    canMove(by) {
        const target = new Date(Date.UTC(this.month.y, this.month.m + by, 1));
        const y = target.getUTCFullYear();
        const m = target.getUTCMonth();

        return ymd(y, m, this.lastDay(y, m)) >= this.bookableFrom && ymd(y, m, 1) <= this.bookableUntil;
    },

    moveMonth(by) {
        if (!this.canMove(by)) return;

        const target = new Date(Date.UTC(this.month.y, this.month.m + by, 1));

        this.month = { y: target.getUTCFullYear(), m: target.getUTCMonth() };
        this.loadMonth();
    },

    async loadMonth(force = false) {
        if (!this.packageId) return;

        const key = this.monthKey();
        const { y, m } = this.month;
        const from = [ymd(y, m, 1), this.bookableFrom].sort()[1];
        const to = [ymd(y, m, this.lastDay(y, m)), this.bookableUntil].sort()[0];

        // An answer still on its way is for another month or package: drop it
        this.slotRequest?.abort();
        const seq = ++this.slotSeq;

        if (!force && this.days[key]) {
            this.slotState = 'ready';
            this.afterLoad();

            return;
        }

        if (from > to) {
            this.days[key] = {};
            this.slotState = 'ready';
            this.afterLoad();

            return;
        }

        this.slotState = 'loading';
        this.slotRequest = new AbortController();

        try {
            const response = await fetch(`${slotsUrl}?${new URLSearchParams({ package: this.packageId, from, to })}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                signal: this.slotRequest.signal,
            });
            const body = await response.json().catch(() => ({}));

            if (seq !== this.slotSeq) return;

            if (!response.ok) {
                this.slotState = 'error';

                return;
            }

            this.bookableFrom = body.bookable_from;
            this.bookableUntil = body.bookable_until;
            this.days[key] = body.days ?? {};
            this.slotState = 'ready';
            this.afterLoad();
        } catch (error) {
            if (error.name !== 'AbortError' && seq === this.slotSeq) this.slotState = 'error';
        }
    },

    // A chosen time that is no longer free (taken meanwhile) is let go, so it cannot be sent by mistake
    afterLoad() {
        if (!this.selectedDate || this.selectedDate.slice(0, 7) !== ymd(this.month.y, this.month.m, 1).slice(0, 7)) return;

        this.dayList = this.monthSlots()[this.selectedDate] ?? [];

        if (!this.dayList.length) {
            this.clearSlot();
        } else if (this.selected && !this.dayList.some((slot) => slot.starts_at === this.selected)) {
            this.selected = '';
            this.selectedSlot = null;
            this.collect();
        }
    },

    cells() {
        const { y, m } = this.month;
        const blanks = new Date(Date.UTC(y, m, 1)).getUTCDay();
        const free = this.monthSlots();
        const cells = [];

        for (let i = 0; i < blanks; i++) cells.push({ key: `blank-${i}`, state: 'blank', day: '', date: '', label: '' });

        for (let d = 1; d <= this.lastDay(y, m); d++) {
            const date = ymd(y, m, d);
            const count = free[date]?.length ?? 0;
            const inWindow = date >= this.bookableFrom && date <= this.bookableUntil;
            const state = !inWindow ? 'outside' : this.slotState !== 'ready' ? 'pending' : count > 0 ? 'free' : 'none';
            const name = dayName.format(new Date(Date.UTC(y, m, d)));
            const label = state === 'free' ? `${name}: ${count} free ${count === 1 ? 'time' : 'times'}` : state === 'outside' ? `${name}: cannot be booked` : `${name}: no free times`;

            cells.push({ key: date, state, day: d, date, label });
        }

        return cells;
    },

    dayClass(cell) {
        if (cell.state === 'blank') return 'invisible';
        if (cell.date === this.selectedDate && cell.state === 'free') return 'bg-brand font-bold text-white shadow-md ring-2 ring-brand';
        if (cell.state === 'free') return 'bg-app-card font-semibold hover:bg-brand/10 dark:bg-white/10 dark:hover:bg-white/15';
        if (cell.state === 'none') return 'text-app-muted line-through';

        return 'text-app-muted opacity-50';
    },

    pickDay(cell) {
        if (cell.state !== 'free') return;

        this.selectedDate = cell.date;
        this.dayList = this.monthSlots()[cell.date] ?? [];

        if (!this.dayList.some((slot) => slot.starts_at === this.selected)) {
            this.selected = '';
            this.selectedSlot = null;
        }

        this.problem = '';
        this.collect();
    },

    daySlots() {
        return this.dayList;
    },

    selectedDateText() {
        return this.dayList[0]?.date_text ?? this.selectedSlot?.date_text ?? '';
    },

    pickSlot(slot) {
        this.selected = slot.starts_at;
        this.selectedSlot = slot;
        this.problem = '';
        this.collect();
    },

    clearSlot() {
        this.selected = '';
        this.selectedSlot = null;
        this.selectedDate = '';
        this.dayList = [];
        this.collect();
    },

    // Images: courtesy checks with the server's limits and words; the server checks every image again

    readImages() {
        this.images.forEach((image) => image.url && URL.revokeObjectURL(image.url));

        this.images = [...(this.$refs.images?.files ?? [])].map((file, index) => {
            const error = this.imageProblem(file);

            return {
                key: `${index}-${file.name}-${file.size}-${file.lastModified}`,
                name: file.name,
                bytes: file.size,
                size: this.size(file.size),
                error,
                url: error ? '' : URL.createObjectURL(file),
            };
        });
    },

    imageProblem(file) {
        const extension = file.name.includes('.') ? file.name.split('.').pop().toLowerCase() : '';

        if (!images.extensions.includes(extension)) return images.wrongType;
        if (file.size === 0) return 'The file is empty.';
        if (file.size > images.maxMb * 1024 * 1024) return images.tooLarge;

        return '';
    },

    imageBytes() {
        return this.images.reduce((sum, image) => sum + image.bytes, 0);
    },

    imageTotalProblem() {
        if (this.images.length > images.max) return images.tooMany;

        if (this.imageBytes() > images.totalMb * 1024 * 1024) {
            const size = (Math.ceil((this.imageBytes() / 1024 / 1024) * 10) / 10).toFixed(1);

            return `Your files together are ${size} MB. The most one booking can hold is ${images.totalMb} MB. Please remove or compress some files.`;
        }

        return '';
    },

    // A file list can only be changed by building a new one
    replaceImages(list) {
        const transfer = new DataTransfer();

        list.forEach((file) => transfer.items.add(file));
        this.$refs.images.files = transfer.files;
        this.readImages();
        // A warning about the old list no longer applies
        this.problem = '';
        this.collect();
    },

    removeImage(index) {
        this.replaceImages([...this.$refs.images.files].filter((file, i) => i !== index));
        this.$refs.images.focus();
    },

    dropImages(event) {
        this.dragging = false;
        this.replaceImages([...this.$refs.images.files, ...event.dataTransfer.files]);
    },

    size(bytes) {
        return bytes >= 1024 * 1024 ? `${(bytes / 1024 / 1024).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;
    },
}));

// "Copy reference" on the order placed page: the label and a small tooltip say "Copied!" for 2 seconds, as in the design
Alpine.data('copyText', (text) => ({
    copied: false,
    timer: null,

    async copy() {
        if (!(await this.write())) return;

        this.copied = true;
        clearTimeout(this.timer);
        this.timer = setTimeout(() => (this.copied = false), 2000);
    },

    // The clipboard API needs permission (and https outside localhost); the older copy command is the fallback
    async write() {
        try {
            await navigator.clipboard.writeText(text);

            return true;
        } catch (error) {
            const field = document.createElement('textarea');

            field.value = text;
            field.setAttribute('readonly', '');
            field.style.cssText = 'position:fixed;opacity:0;pointer-events:none';
            document.body.appendChild(field);
            field.select();

            const done = document.execCommand('copy');

            field.remove();

            return done;
        }
    },
}));

Alpine.start();
