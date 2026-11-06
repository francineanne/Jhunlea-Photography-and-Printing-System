// The owner's Ask Lumi (Phase 7E-3b): the floating panel on every owner page and the full page are this one Alpine
// component. It only talks to the read-only endpoints (ask, history, briefing); every text is drawn with x-text and
// every link is a relative owner path the server built.
import { apiRequest, clientId, scrollToEnd } from './chat-common';

const SHEET_BELOW_PX = 640;
const FOCUSABLE = 'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])';

// Only "/owner/..." paths the server built: anything else is dropped, never followed
export const ownerPath = (url) => (typeof url === 'string' && /^\/owner(\/[A-Za-z0-9/_.-]*)?(\?[^\s<>"']*)?$/.test(url) && !url.includes('..') ? url : null);

const safeLinks = (items) => (Array.isArray(items) ? items : [])
    .map((item) => ({ label: String(item?.label ?? ''), url: ownerPath(item?.url) }))
    .filter((item) => item.label !== '' && item.url !== null);

// A history row or an answer as the list draws it
const toRow = (data) => ({
    key: data.id ? `m-${data.id}` : clientId(),
    id: data.id ?? null,
    role: data.role,
    text: String(data.text ?? ''),
    cards: safeLinks(data.cards),
    links: safeLinks(data.links),
    via_backup: data.via_backup === true,
    time: data.local ?? null,
    status: data.status ?? null,
    notice: data.notice ?? null,
});

export default ({ mode, urls, texts, prefill = null }) => ({
    mode,
    open: mode === 'full',
    loaded: false,
    loading: false,
    loadingOlder: false,
    older: null,
    rows: [],
    draft: '',
    waiting: false,
    fresh: false,
    banner: null,

    init() {
        if (this.mode === 'full') {
            this.loadHistory();
        }

        // "Ask Lumi about this" from the overview, or any page: open with a question ready to send
        window.addEventListener('owner-ask:open', (event) => {
            if (this.mode !== 'compact') return;

            this.show();
            this.draft = String(event.detail?.text ?? '').slice(0, texts.maxChars);
            this.$nextTick(() => this.$refs.input?.focus());
        });

        if (prefill) {
            this.draft = String(prefill).slice(0, texts.maxChars);
        }
    },

    get canSend() {
        return !this.waiting && this.draft.trim() !== '' && this.draft.length <= texts.maxChars;
    },

    get showChips() {
        return !this.loading && !this.waiting && !this.rows.some((row) => row.role === 'owner');
    },

    // ---- The panel ----

    show() {
        this.open = true;
        this.lockPage(true);

        if (!this.loaded) {
            this.loadHistory();
        }

        this.$nextTick(() => {
            this.scrollDown();
            this.$refs.input?.focus();
        });
    },

    close() {
        if (this.mode !== 'compact' || !this.open) return;

        this.open = false;
        this.lockPage(false);
        this.$nextTick(() => this.$refs.launcher?.focus());
    },

    isSheet() {
        return this.mode === 'compact' && window.innerWidth < SHEET_BELOW_PX;
    },

    lockPage(locked) {
        document.documentElement.classList.toggle('overflow-hidden', locked && this.isSheet());
    },

    // The phone sheet keeps Tab inside it
    trap(event) {
        if (!this.isSheet() || !this.open) return;

        const items = [...this.$refs.panel.querySelectorAll(FOCUSABLE)].filter((el) => el.offsetParent !== null);

        if (items.length === 0) return;

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

    // ---- Talking to the server ----

    // What went wrong, in plain words; never a raw error
    fail(status, retry = null) {
        if (status === 419) {
            this.banner = { kind: 'session', text: texts.expired };
        } else if (status === 401 || status === 403 || status === 404) {
            this.banner = { kind: 'session', text: texts.signedOut };
        } else if (status === 429) {
            this.banner = { kind: 'calm', text: texts.minute, retry };
        } else if (status === 0) {
            this.banner = { kind: 'error', text: texts.offline, retry };
        } else {
            this.banner = { kind: 'error', text: texts.generic, retry };
        }
    },

    async call(url, method = 'GET', body = null) {
        try {
            return await apiRequest(url, method, body);
        } catch (error) {
            // fetch itself failed: no connection
            return { ok: false, status: 0, data: null };
        }
    },

    async loadHistory() {
        this.loading = true;
        this.banner = null;
        const response = await this.call(urls.history);
        this.loading = false;

        if (!response.ok) {
            this.fail(response.status, () => this.loadHistory());

            return;
        }

        this.loaded = true;
        this.rows = (response.data.messages ?? []).map(toRow);
        this.older = response.data.older ?? null;
        this.$nextTick(() => this.scrollDown());
    },

    async loadOlder() {
        if (!this.older || this.loadingOlder) return;

        this.loadingOlder = true;
        const response = await this.call(`${urls.history}?before=${encodeURIComponent(this.older)}`);
        this.loadingOlder = false;

        if (!response.ok) {
            this.fail(response.status, () => this.loadOlder());

            return;
        }

        const log = this.$refs.log;
        const height = log?.scrollHeight ?? 0;
        this.rows = [...(response.data.messages ?? []).map(toRow), ...this.rows];
        this.older = response.data.older ?? null;
        // Keep the reader where they were
        this.$nextTick(() => {
            if (log) log.scrollTop = log.scrollHeight - height;
        });
    },

    ask(text) {
        this.draft = text;
        this.submit();
    },

    submit() {
        if (!this.canSend) return;

        const text = this.draft.trim();
        this.draft = '';
        this.resize();
        this.rows.push(toRow({ role: 'owner', text, status: 'sending' }));
        // The reactive copy, so a status change redraws the bubble
        this.deliver(this.rows[this.rows.length - 1]);
    },

    async deliver(row) {
        this.waiting = true;
        this.banner = null;
        row.status = 'sending';
        this.$nextTick(() => this.scrollDown());

        const response = await this.call(urls.ask, 'POST', { message: row.text, fresh: this.fresh });
        this.waiting = false;

        if (!response.ok) {
            row.status = 'failed';
            this.fail(response.status, () => this.retryMessage(row));
            this.$nextTick(() => this.$refs.input?.focus());

            return;
        }

        this.fresh = false;
        row.status = null;
        const data = response.data;

        // A limit or an unavailable provider is a calm notice, not an answer
        this.rows.push(toRow(data.notice
            ? { role: 'notice', text: data.reply, notice: data.notice }
            : { role: 'lumi', text: data.reply, cards: data.cards, links: data.links, via_backup: data.via_backup }));
        this.$nextTick(() => {
            this.scrollDown();
            this.$refs.input?.focus();
        });
    },

    retryMessage(row) {
        if (this.waiting) return;

        this.banner = null;
        this.deliver(row);
    },

    discardMessage(row) {
        this.rows = this.rows.filter((item) => item !== row);
        this.banner = null;
    },

    // The next question starts without the earlier turns; the list is cleared from view (the history keeps them)
    newQuestion() {
        if (this.waiting) return;

        this.fresh = true;
        this.rows = [];
        this.older = null;
        this.banner = null;
        this.draft = '';
        this.$nextTick(() => this.$refs.input?.focus());
    },

    runRetry() {
        const retry = this.banner?.retry;
        this.banner = null;
        retry?.();
    },

    onKeydown(event) {
        if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
            event.preventDefault();
            this.submit();
        }
    },

    resize() {
        const input = this.$refs.input;

        if (!input) return;

        input.style.height = 'auto';
        input.style.height = `${Math.min(input.scrollHeight, 144)}px`;
    },

    scrollDown() {
        const log = this.$refs.log;

        if (log) scrollToEnd(log, !this.loaded);
    },

    // ---- How the list is drawn (7F-2): a date line when the day changes, the avatar and name once per group ----

    dayOf(row) {
        return row?.time?.match(/^[A-Za-z]+ \d+, \d+/)?.[0] ?? null;
    },

    // The date to show above this row, or null when the day did not change
    dayLine(row) {
        const index = this.rows.indexOf(row);
        const day = this.dayOf(row);

        if (day === null) return null;

        for (let i = index - 1; i >= 0; i -= 1) {
            const earlier = this.dayOf(this.rows[i]);

            if (earlier !== null) return earlier === day ? null : day;
        }

        return day;
    },

    isFirst(row) {
        const previous = this.rows[this.rows.indexOf(row) - 1];

        return !previous || previous.role !== row.role || row.role === 'notice' || this.dayLine(row) !== null;
    },

    clock(row) {
        const day = this.dayOf(row);

        return day ? row.time.slice(day.length).trim() : row.time;
    },
});

// "Today at a glance" on the overview (7E-3b): written by code on the server, so it works when the AI is down. A failure
// hides the lines behind a short note; the page never breaks.
export const ownerBriefing = (url) => ({
    state: 'loading',
    items: [],

    async init() {
        try {
            const response = await apiRequest(url);

            if (!response.ok || !Array.isArray(response.data?.items)) {
                this.state = 'failed';

                return;
            }

            this.items = response.data.items.map((item) => ({ text: String(item?.text ?? ''), url: ownerPath(item?.url) }));
            this.state = 'ready';
        } catch (error) {
            this.state = 'failed';
        }
    },

    askAbout(text) {
        window.dispatchEvent(new CustomEvent('owner-ask:open', { detail: { text } }));
    },
});
