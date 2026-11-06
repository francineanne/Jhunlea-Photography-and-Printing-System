// The record-payment modal of the shop counter (Phase 8B, owner Payments page). Find an order or booking, check the
// amount (always the full confirmed price), pick cash or GCash, confirm, and save once. The server (PaymentService)
// decides everything; this only guides the owner. Cash received is a change calculator and is never stored.

const GCASH_PATTERN = /^[A-Za-z0-9-]{6,40}$/;
const AMOUNT_PATTERN = /^\d{1,8}(\.\d{1,2})?$/;

const toCents = (value) => {
    const text = String(value ?? '').trim();
    if (!AMOUNT_PATTERN.test(text)) return null;
    const [whole, part = ''] = text.split('.');
    return Number(whole) * 100 + Number(part.padEnd(2, '0'));
};

const peso = (cents) =>
    '₱' + (cents / 100).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export default function paymentCounter({ searchUrl, targetUrl, recordUrl, paymentsUrl, initial }) {
    // The component's own element, kept outside the reactive state (methods also run from nested scopes)
    let rootEl = null;
    const focus = (selector) => rootEl?.querySelector(selector)?.focus();

    return {
        step: 'find',
        query: '',
        results: [],
        searching: false,
        searched: false,
        loading: false,
        target: null,
        method: 'cash',
        gcash: '',
        tendered: '',
        collected: false,
        saving: false,
        error: '',
        done: null,
        timer: null,

        init() {
            rootEl = this.$el;
            if (initial) setTimeout(() => this.openFor(initial), 0);
        },

        // From the "Record payment" buttons: $dispatch('counter-open') or $dispatch('counter-open', { type, id })
        openFor(detail) {
            this.reset();
            rootEl.dispatchEvent(new CustomEvent('open-modal', { detail: 'counter', bubbles: true }));
            if (detail && detail.type && detail.id) {
                this.load(detail.type, detail.id);
            } else {
                // after the dialog's own first focus
                setTimeout(() => focus('[data-counter-search]'), 50);
            }
        },

        reset() {
            Object.assign(this, { step: 'find', query: '', results: [], searched: false, target: null, method: 'cash', gcash: '', tendered: '', collected: false, error: '', done: null });
        },

        onInput() {
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.search(), 250);
        },

        async search() {
            const q = this.query.trim();
            if (q.length < 2) {
                this.results = [];
                this.searched = false;
                return;
            }
            this.searching = true;
            this.error = '';
            try {
                const response = await fetch(`${searchUrl}?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } });
                const data = response.ok ? await response.json() : { results: [] };
                if (q === this.query.trim()) {
                    this.results = data.results ?? [];
                    this.searched = true;
                }
            } catch {
                this.error = 'Search is not available right now. Check the connection and try again.';
            } finally {
                this.searching = false;
            }
        },

        async load(type, id) {
            this.loading = true;
            try {
                const response = await fetch(`${targetUrl}?type=${encodeURIComponent(type)}&id=${encodeURIComponent(id)}`, { headers: { Accept: 'application/json' } });
                const data = await response.json().catch(() => ({}));
                if (response.ok && data.target) {
                    this.choose(data.target);
                } else {
                    this.error = data.message ?? 'That order or booking could not be opened. Search for it instead.';
                }
            } catch {
                this.error = 'That order or booking could not be opened. Search for it instead.';
            } finally {
                this.loading = false;
            }
        },

        choose(result) {
            this.target = result;
            this.step = 'pay';
            this.error = '';
            this.collected = false;
            setTimeout(() => focus('[data-counter-focus]'), 30);
        },

        back() {
            this.error = '';
            if (this.step === 'confirm') {
                this.step = 'pay';
            } else {
                this.step = 'find';
                this.target = null;
                setTimeout(() => focus('[data-counter-search]'), 30);
            }
        },

        get priceCents() {
            return this.target ? toCents(this.target.price) : null;
        },

        get tenderedCents() {
            return this.tendered.trim() === '' ? null : toCents(this.tendered);
        },

        get tenderedProblem() {
            if (this.method !== 'cash' || this.tendered.trim() === '') return '';
            if (this.tenderedCents === null) return 'Type the cash received as a number, for example 500 or 1250.50.';
            if (this.tenderedCents < this.priceCents) return 'That is less than the full amount. Collect the full amount; partial payments are not taken.';
            return '';
        },

        get changeText() {
            if (this.method !== 'cash' || this.tenderedCents === null || this.tenderedProblem) return '';
            return peso(this.tenderedCents - this.priceCents);
        },

        get gcashProblem() {
            if (this.method !== 'gcash' || this.gcash.trim() === '' || GCASH_PATTERN.test(this.gcash.trim())) return '';
            return 'The GCash reference must be 6 to 40 letters, digits or hyphens.';
        },

        get canReview() {
            if (!this.target?.payable || !this.collected || this.tenderedProblem) return false;
            return this.method === 'cash' || GCASH_PATTERN.test(this.gcash.trim());
        },

        review() {
            if (!this.canReview) return;
            this.step = 'confirm';
            setTimeout(() => focus('[data-counter-save]'), 30);
        },

        async save() {
            if (this.saving || !this.canReview) return;
            this.saving = true;
            this.error = '';
            try {
                const response = await fetch(recordUrl, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    },
                    body: JSON.stringify({
                        payable_type: this.target.type,
                        payable_id: this.target.id,
                        method: this.method,
                        gcash_reference: this.method === 'gcash' ? this.gcash.trim() : null,
                        expected_amount: this.target.price,
                        cash_tendered: this.method === 'cash' && this.tendered.trim() !== '' ? this.tendered.trim() : null,
                        collected: this.collected ? 1 : 0,
                    }),
                });
                const data = await response.json().catch(() => ({}));
                if (response.ok) {
                    this.done = data;
                    this.step = 'done';
                    setTimeout(() => focus('[data-counter-receipt]'), 30);
                } else if (response.status === 419) {
                    this.error = 'Your session expired. Reload the page and try again.';
                } else if (response.status === 429) {
                    this.error = 'Too many tries in a minute. Wait a moment and try again.';
                } else {
                    const errors = data.errors ?? {};
                    this.error = Object.values(errors)[0]?.[0] ?? data.message ?? 'The payment was not recorded. Try again.';
                    // Back to the field that needs fixing (a GCash reference already used is refused by PaymentService)
                    if (errors.gcash_reference || errors.cash_tendered || errors.collected || /GCash reference/i.test(this.error)) {
                        this.step = 'pay';
                    }
                }
            } catch {
                this.error = 'The payment may not have been saved: the connection failed. Check the ledger before trying again.';
            } finally {
                this.saving = false;
            }
        },

        // Done: reload the Payments page (without ?pay=) so the ledger, totals and queues show the new payment
        finish() {
            window.location.assign(paymentsUrl);
        },
    };
}
