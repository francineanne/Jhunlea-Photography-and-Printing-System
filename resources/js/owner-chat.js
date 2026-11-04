// The owner's studio inbox (Phase 7D): the list of studio threads, the open thread with its composer, Suggest reply and
// Summarize (drafts only), and the presence control. Everything shown comes from the server and is drawn with x-text.

import { apiRequest, buildRows, clientId, MAX_REFRESH_PAGES, NEAR_BOTTOM_PX } from './chat-common';

const LIST_POLL_MS = 30000;
const PRESENCE_POLL_MS = 60000;
const SEARCH_DELAY_MS = 300;
const COUNTER_FROM = 800;
const OPEN_FOR_MS = 2 * 60 * 1000;
const IDLE_FOR_MS = 10 * 60 * 1000;

export const ownerInbox = ({ conversationId, filter, search, maxChars, urls, texts }) => ({
    conversationId,
    maxChars,
    // <x-chat.rows> reads these; the customer-only actions never show for the owner
    tab: 'studio',
    viewer: 'owner',
    list: { items: [], page: 1, lastPage: 1, total: 0, loading: false, loaded: false, filter, search: search ?? '', unanswered: 0 },
    thread: { conversation: null, messages: [], state: null, loaded: false, loading: false, sending: false, earlier: false },
    draft: '',
    banner: null,
    assist: { busy: null, notice: null, summary: [] },
    presence: { mode: null, away: false, reason: '', saving: false },
    lastActivity: Date.now(),
    localKey: 0,
    timers: { list: null, thread: null, presence: null, search: null },
    polling: false,

    init() {
        this.loadList();
        this.loadPresence();

        if (this.conversationId) {
            this.loadThread();
        }

        this.timers.list = setInterval(() => this.visible() && this.loadList(true), LIST_POLL_MS);
        this.timers.presence = setInterval(() => this.visible() && this.loadPresence(), PRESENCE_POLL_MS);

        document.addEventListener('visibilitychange', () => {
            if (this.visible()) {
                this.loadList(true);
                this.scheduleThreadPoll(true);
            }
        });
    },

    // ---- What the template reads ----

    get rows() {
        return buildRows(this.thread.messages);
    },

    get canSend() {
        return this.thread.loaded && !this.thread.sending && this.draft.trim() !== '' && this.draft.length <= this.maxChars;
    },

    get showCounter() {
        return this.draft.length >= COUNTER_FROM;
    },

    get aboutLinks() {
        const seen = new Map();

        this.thread.messages.forEach((message) => {
            if (message.about?.url) {
                seen.set(message.about.url, message.about);
            }
        });

        return [...seen.values()];
    },

    visible() {
        return document.visibilityState === 'visible';
    },

    request(url, method = 'GET', body = null) {
        return apiRequest(url, method, body);
    },

    threadUrl(key, id = this.conversationId) {
        return urls[key].replace('999999999', id);
    },

    fail(response, retry = null) {
        const { status, data } = response;

        if (status === 401 || status === 419) {
            this.banner = { kind: 'session', text: status === 401 ? texts.signedOut : (data?.message ?? texts.expired) };
        } else if (status === 429 && !data?.code) {
            this.banner = { kind: 'error', text: texts.rateLimited, retry };
        } else {
            this.banner = { kind: 'error', text: data?.message ?? texts.generic, retry };
        }
    },

    failOffline(retry) {
        this.banner = { kind: 'offline', text: texts.offline, retry };
    },

    runRetry() {
        const retry = this.banner?.retry;

        this.banner = null;
        retry?.();
    },

    // ---- The list ----

    listUrl(poll) {
        const query = new URLSearchParams();

        if (this.list.filter && this.list.filter !== 'all') query.set('filter', this.list.filter);
        if (this.list.search.trim() !== '') query.set('q', this.list.search.trim());
        if (this.list.page > 1) query.set('page', this.list.page);
        if (poll) query.set('poll', '1');

        return `${urls.inbox}${query.toString() ? `?${query}` : ''}`;
    },

    // The address bar keeps the filter, search and page, so a reload or a shared link shows the same list
    rememberList() {
        const query = new URLSearchParams(this.listUrl(false).split('?')[1] ?? '');
        const path = this.conversationId ? this.threadUrl('page') : urls.inbox;

        window.history.replaceState(null, '', `${path}${query.toString() ? `?${query}` : ''}`);
    },

    async loadList(poll = false) {
        if (this.list.loading && poll) return;

        this.list.loading = true;

        try {
            const response = await this.request(this.listUrl(poll));

            if (!response.ok) {
                if (!poll) this.fail(response, () => this.loadList());

                return;
            }

            this.list.items = response.data.conversations;
            this.list.page = response.data.current_page;
            this.list.lastPage = response.data.last_page;
            this.list.total = response.data.total;
            this.list.unanswered = response.data.unanswered;
            this.list.loaded = true;
        } catch (error) {
            if (!poll) this.failOffline(() => this.loadList());
        } finally {
            this.list.loading = false;
        }
    },

    setFilter(next) {
        this.list.filter = next;
        this.list.page = 1;
        this.rememberList();
        this.loadList();
    },

    onSearch() {
        clearTimeout(this.timers.search);
        this.timers.search = setTimeout(() => {
            this.list.page = 1;
            this.rememberList();
            this.loadList();
        }, SEARCH_DELAY_MS);
    },

    goToPage(page) {
        if (page < 1 || page > this.list.lastPage) return;

        this.list.page = page;
        this.rememberList();
        this.loadList();
    },

    // A row opens the thread page; the list keeps its filter, search and page
    rowUrl(item) {
        const query = new URLSearchParams(this.listUrl(false).split('?')[1] ?? '');

        return `${item.url}${query.toString() ? `?${query}` : ''}`;
    },

    // ---- The thread ----

    async loadThread() {
        this.thread.loading = true;

        try {
            const response = await this.request(this.threadUrl('thread'));

            if (!response.ok) {
                this.fail(response, () => this.loadThread());

                return;
            }

            this.thread.conversation = response.data.conversation;
            this.thread.messages = response.data.messages;
            this.thread.state = response.data.state;
            this.thread.loaded = true;
            this.$nextTick(() => this.scrollDown(true));
            this.markRead();
            this.scheduleThreadPoll();
        } catch (error) {
            this.failOffline(() => this.loadThread());
        } finally {
            this.thread.loading = false;
        }
    },

    pollDelay() {
        const poll = this.thread.state?.poll;
        const quiet = Date.now() - this.lastActivity;

        if (!poll) return 5000;
        if (quiet < OPEN_FOR_MS) return poll.open * 1000;

        return (quiet < IDLE_FOR_MS ? poll.idle : poll.long_idle) * 1000;
    },

    scheduleThreadPoll(now = false) {
        clearTimeout(this.timers.thread);

        if (!this.thread.loaded || !this.visible()) return;

        this.timers.thread = setTimeout(async () => {
            await this.refreshThread();
            this.scheduleThreadPoll();
        }, now ? 0 : this.pollDelay());
    },

    // Every message after the last one known; asks again while more are waiting, up to MAX_REFRESH_PAGES pages
    async refreshThread() {
        if (this.polling || !this.thread.conversation) return;

        this.polling = true;

        try {
            let more = true;
            let pages = 0;

            while (more && pages < MAX_REFRESH_PAGES) {
                pages += 1;

                const last = [...this.thread.messages].reverse().find((message) => message.id);
                const response = await this.request(`${this.threadUrl('thread')}?after=${last?.id ?? 0}`);

                if (!response.ok) {
                    if (response.status === 401 || response.status === 419) this.fail(response);

                    return;
                }

                const before = this.thread.messages.length;

                this.thread.state = { ...response.data.state, has_more_before: this.thread.state?.has_more_before ?? false };
                this.merge(response.data.messages);
                more = response.data.state.has_more_after;

                if (this.thread.messages.length > before) {
                    this.lastActivity = Date.now();
                    this.markRead();
                }
            }

            if (this.banner?.kind === 'offline') this.banner = null;
        } catch (error) {
            this.failOffline(() => this.scheduleThreadPoll(true));
        } finally {
            this.polling = false;
        }
    },

    merge(messages) {
        const known = new Set(this.thread.messages.map((message) => message.id).filter(Boolean));
        const fresh = messages.filter((message) => !known.has(message.id));

        if (fresh.length === 0) return;

        // Only follow new messages when the owner is already at the bottom: reading older ones is never interrupted
        const near = this.isNearBottom();

        this.thread.messages.push(...fresh);
        this.$nextTick(() => this.scrollDown(near));
    },

    async loadEarlier() {
        const first = this.thread.messages.find((message) => message.id);

        if (!first || this.thread.earlier) return;

        this.thread.earlier = true;

        const log = this.$refs.log;
        const height = log.scrollHeight;

        try {
            const response = await this.request(`${this.threadUrl('thread')}?before=${first.id}`);

            if (!response.ok) {
                this.fail(response, () => this.loadEarlier());

                return;
            }

            this.thread.messages = [...response.data.messages, ...this.thread.messages];
            this.thread.state.has_more_before = response.data.state.has_more_before;
            this.$nextTick(() => {
                log.scrollTop = log.scrollHeight - height;
            });
        } catch (error) {
            this.failOffline(() => this.loadEarlier());
        } finally {
            this.thread.earlier = false;
        }
    },

    async markRead() {
        if (!this.visible() || !this.thread.conversation) return;

        try {
            await this.request(this.threadUrl('read'), 'POST', {});
            const item = this.list.items.find((row) => row.id === this.conversationId);

            if (item) item.unread_count = 0;
        } catch (error) {
            // Read again on the next poll
        }
    },

    // ---- Sending ----

    onKeydown(event) {
        this.lastActivity = Date.now();

        if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
            event.preventDefault();
            this.submit();
        }
    },

    resize() {
        const input = this.$refs.input;

        if (!input) return;

        input.style.height = 'auto';
        input.style.height = `${Math.min(input.scrollHeight, 192)}px`;
    },

    submit() {
        if (!this.canSend) return;

        const body = this.draft.trim();

        this.draft = '';
        this.banner = null;
        this.lastActivity = Date.now();
        this.$nextTick(() => this.resize());

        this.thread.messages.push({
            key: `local-${++this.localKey}`,
            local: true,
            status: 'sending',
            sender_type: 'owner',
            sender_label: texts.you,
            mine: true,
            body,
            cards: [],
            created_at: null,
            payload: { body, client_id: clientId() },
        });
        this.$nextTick(() => this.scrollDown(true));

        return this.deliver(this.thread.messages[this.thread.messages.length - 1]);
    },

    // Also the retry of a failed bubble: the same client_id, so the server stores it once
    async deliver(local) {
        local.status = 'sending';
        this.thread.sending = true;

        try {
            const response = await this.request(this.threadUrl('thread'), 'POST', local.payload);

            if (!response.ok) {
                local.status = 'failed';
                this.fail(response);

                return;
            }

            const stored = response.data.message;

            this.thread.messages = this.thread.messages.some((message) => message.id === stored.id)
                ? this.thread.messages.filter((message) => message !== local)
                : this.thread.messages.map((message) => (message === local ? stored : message));
            this.thread.state = { ...this.thread.state, ...response.data.state };
            this.loadList(true);
        } catch (error) {
            local.status = 'failed';
            this.failOffline(() => this.retryMessage(local));
        } finally {
            this.thread.sending = false;
        }
    },

    retryMessage(local) {
        this.banner = null;
        this.deliver(local);
    },

    discardMessage(local) {
        this.thread.messages = this.thread.messages.filter((message) => message !== local);

        if (this.banner?.kind !== 'session') this.banner = null;
    },

    // The customer-only actions of <x-chat.rows> never show here
    switchTab() {},
    contactOwner() {},

    // ---- Resolve, reopen ----

    async setResolved(resolved) {
        try {
            const response = await this.request(this.threadUrl(resolved ? 'resolve' : 'reopen'), 'POST', {});

            if (!response.ok) {
                this.fail(response);

                return;
            }

            this.thread.conversation = response.data.conversation;
            this.thread.state = { ...this.thread.state, ...response.data.state };
            this.loadList(true);
        } catch (error) {
            this.failOffline(() => this.setResolved(resolved));
        }
    },

    // ---- Owner assist: drafts only ----

    async runAssist(kind) {
        if (this.assist.busy) return;

        this.assist.busy = kind;
        this.assist.notice = null;

        if (kind === 'summarize') this.assist.summary = [];

        try {
            const response = await this.request(this.threadUrl(kind), 'POST', {});

            if (!response.ok) {
                this.assist.notice = response.status === 429 ? texts.assistBusy : (response.data?.message ?? texts.assistUnavailable);

                return;
            }

            if (!response.data.available) {
                this.assist.notice = response.data.text;

                return;
            }

            if (kind === 'summarize') {
                this.assist.summary = response.data.lines;

                return;
            }

            // The draft goes into the composer for the owner to read and edit; nothing is sent
            this.draft = this.draft.trim() === '' ? response.data.text : `${this.draft.trim()}\n\n${response.data.text}`;
            this.assist.notice = texts.draftReady;
            this.$nextTick(() => {
                this.resize();
                this.$refs.input?.focus();
            });
        } catch (error) {
            this.assist.notice = texts.assistUnavailable;
        } finally {
            this.assist.busy = null;
        }
    },

    // ---- Presence ----

    async loadPresence() {
        try {
            const response = await this.request(urls.presence);

            if (response.ok) this.applyPresence(response.data);
        } catch (error) {
            // Shown again on the next refresh
        }
    },

    async setPresence(mode) {
        if (this.presence.saving || this.presence.mode === mode) return;

        this.presence.saving = true;

        try {
            const response = await this.request(urls.presence, 'POST', { mode });

            if (!response.ok) {
                this.fail(response);

                return;
            }

            this.applyPresence(response.data);
            // The open thread's "Lumi is answering" line follows the new mode at once, not at the next poll
            this.refreshThread();
        } catch (error) {
            this.failOffline(() => this.setPresence(mode));
        } finally {
            this.presence.saving = false;
        }
    },

    applyPresence(data) {
        this.presence.mode = data.mode;
        this.presence.away = data.away;
        this.presence.reason = data.reason;
    },

    // ---- Scrolling ----

    isNearBottom() {
        const log = this.$refs.log;

        return !log || log.scrollHeight - log.scrollTop - log.clientHeight < NEAR_BOTTOM_PX;
    },

    scrollDown(force = false) {
        const log = this.$refs.log;

        if (log && (force || this.isNearBottom())) {
            log.scrollTop = log.scrollHeight;
        }
    },
});

// Keeps "Auto" presence honest on every owner page: one light ping a minute, only while this tab is visible and the owner
// moved the mouse, typed, touched or scrolled during that minute. Background polling never counts as activity
export const ownerHeartbeat = (url) => ({
    lastInput: 0,

    init() {
        const mark = () => {
            this.lastInput = Date.now();
        };

        ['pointerdown', 'pointermove', 'keydown', 'wheel', 'touchstart', 'scroll'].forEach((name) => window.addEventListener(name, mark, { passive: true }));

        setInterval(() => {
            if (document.visibilityState === 'visible' && Date.now() - this.lastInput < 60000) {
                apiRequest(url, 'POST', {}).catch(() => {});
            }
        }, 60000);
    },
});
