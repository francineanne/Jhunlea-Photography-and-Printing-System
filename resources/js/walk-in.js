// The owner's counter forms (Phase 8A): pick or add a walk-in customer, the live catalog price of a walk-in order, the
// change due for cash (a calculator only: nothing of it is saved), and the free start times of a walk-in booking.
// Every value is checked again by the server; without JavaScript the forms still post (the customer is then typed in).

const pesos = (cents) => '₱' + (cents / 100).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const toCents = (text) => (/^\d{1,8}(\.\d{1,2})?$/.test(String(text ?? '').trim()) ? Math.round(parseFloat(text) * 100) : null);

async function getJson(url) {
    const response = await fetch(url, { headers: { Accept: 'application/json' } });

    return response.ok ? response.json() : null;
}

// The customer picker: search by name or phone, pick one, or add a new walk-in (name and optional phone)
export const walkInCustomer = ({ url, picked = null, mode = 'search' }) => ({
    mode,
    query: '',
    results: [],
    searching: false,
    picked,
    timer: null,

    search() {
        clearTimeout(this.timer);
        const term = this.query.trim();
        if (term.length < 2) {
            this.results = [];

            return;
        }
        this.timer = setTimeout(async () => {
            this.searching = true;
            const data = await getJson(url + '?q=' + encodeURIComponent(term)).catch(() => null);
            this.results = data?.customers ?? [];
            this.searching = false;
        }, 250);
    },

    pick(customer) {
        this.picked = customer;
        this.results = [];
        this.query = '';
        this.$dispatch('walk-in-customer', customer);
    },

    clear() {
        this.picked = null;
        this.$nextTick(() => this.$refs.search?.focus());
    },
});

// The walk-in order: service, options, live catalog price, custom price, method, cash tendered and change due
export const walkInOrder = ({ priceUrl, perPage, initial }) => ({
    service: initial.service ?? '',
    quantity: initial.quantity ?? 1,
    pages: initial.pages ?? 1,
    priceChoice: initial.priceChoice ?? 'catalog',
    customPrice: initial.customPrice ?? '',
    method: initial.method ?? 'cash',
    tendered: initial.tendered ?? '',
    catalog: null,
    catalogText: '',
    loading: false,
    timer: null,

    init() {
        this.refresh();
        this.$watch('service', () => this.refresh());
        this.$watch('quantity', () => this.refresh());
        this.$watch('pages', () => this.refresh());
    },

    isPerPage() {
        return perPage.includes(Number(this.service));
    },

    refresh() {
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.fetchPrice(), 250);
    },

    async fetchPrice() {
        if (!this.service) {
            this.catalog = null;
            this.catalogText = '';

            return;
        }
        const form = this.$root.closest('form') ?? this.$root;
        const params = new URLSearchParams({ service: this.service, quantity: this.quantity || 1, pages: this.pages || 1 });
        form.querySelectorAll(`[name^="options[${this.service}]"]`).forEach((field) => {
            if (field.value) params.append(field.name, field.value);
        });
        this.loading = true;
        const data = await getJson(priceUrl + '?' + params.toString()).catch(() => null);
        this.loading = false;
        this.catalog = data && !data.quoted && data.total ? toCents(data.total) : null;
        this.catalogText = data ? data.total_text : 'Choose every option to see the catalog price.';
        if (this.catalog === null && this.priceChoice === 'catalog' && data?.quoted) {
            this.priceChoice = 'custom';
        }
    },

    dueCents() {
        return this.priceChoice === 'catalog' ? this.catalog : toCents(this.customPrice);
    },

    dueText() {
        const due = this.dueCents();

        return due === null ? '—' : pesos(due);
    },

    changeText() {
        const due = this.dueCents();
        const given = toCents(this.tendered);
        if (due === null || given === null) return '—';
        if (given < due) return 'Short by ' + pesos(due - given);

        return pesos(given - due);
    },
});

// The walk-in booking: the free start times of the chosen package on the chosen day
export const walkInBooking = ({ slotsUrl, initial }) => ({
    package: initial.package ?? '',
    date: initial.date ?? '',
    time: initial.time ?? '',
    times: [],
    loading: false,
    checked: false,

    init() {
        this.load();
        this.$watch('package', () => this.load());
        this.$watch('date', () => this.load());
    },

    async load() {
        this.checked = false;
        if (!this.package || !this.date) {
            this.times = [];

            return;
        }
        this.loading = true;
        const data = await getJson(slotsUrl + '?' + new URLSearchParams({ package: this.package, date: this.date })).catch(() => null);
        this.loading = false;
        this.times = data?.times ?? [];
        this.checked = true;
    },
});
