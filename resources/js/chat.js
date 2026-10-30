// The customer chat (Phase 7C): one component for the floating panel ("compact") and the full page ("full"), with the
// tabs Ask Lumi and Studio. Everything shown comes from the server; messages are only ever rendered with x-text.

const LUMI_RETRIES = 5;
const LUMI_RETRY_MS = 2000;
const UNREAD_MS = 30000;
const OPEN_FOR_MS = 2 * 60 * 1000;
const IDLE_FOR_MS = 10 * 60 * 1000;
const RECOVER_WITHIN_MS = 10 * 60 * 1000;
const NEAR_BOTTOM_PX = 120;
const COUNTER_FROM = 800;
const SHEET_BELOW_PX = 640;
const BANNER_KEY = 'chat-policy-dismissed';
const FOCUSABLE = 'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])';

const newThread = () => ({
    conversation: null,
    messages: [],
    state: null,
    loaded: false,
    loading: false,
    typing: false,
    sending: false,
    earlier: false,
    recovered: false,
});

const clientId = () => {
    if (window.crypto?.randomUUID) {
        return window.crypto.randomUUID();
    }

    return `c-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`;
};

const readSession = (key) => {
    try {
        return sessionStorage.getItem(key);
    } catch (error) {
        return null;
    }
};

const writeSession = (key, value) => {
    try {
        sessionStorage.setItem(key, value);
    } catch (error) {
        // storage unavailable: the choice lasts for this page view only
    }
};

export default ({ mode, guest, tab, about, name, pageUrl, intro, maxChars, urls, texts }) => ({
    mode,
    guest,
    tab,
    about,
    name,
    pageUrl,
    maxChars,
    open: mode === 'full',
    threads: { ask: newThread(), studio: newThread() },
    drafts: { ask: '', studio: '' },
    fromAssistant: null,
    banner: null,
    policyHidden: readSession(BANNER_KEY) === '1',
    unread: 0,
    lastActivity: Date.now(),
    pollTimer: null,
    polling: false,
    localKey: 0,

    init() {
        if (!this.guest) {
            this.refreshUnread();
            setInterval(() => this.refreshUnread(), UNREAD_MS);
        }

        document.addEventListener('visibilitychange', () => this.onVisibility());

        window.visualViewport?.addEventListener('resize', () => this.fitSheet());
        window.visualViewport?.addEventListener('scroll', () => this.fitSheet());

        if (this.open) {
            this.loadTab();
        }
    },

    // ---- What the template reads ----

    get thread() {
        return this.threads[this.tab];
    },

    get draft() {
        return this.drafts[this.tab];
    },

    set draft(value) {
        this.drafts[this.tab] = value;
    },

    get studioState() {
        return this.threads.studio.state?.studio ?? null;
    },

    get signInNeeded() {
        return this.guest && this.tab === 'studio';
    },

    get canSend() {
        return this.thread.loaded && !this.thread.sending && this.draft.trim() !== '' && this.draft.length <= this.maxChars;
    },

    get showCounter() {
        return this.draft.length >= COUNTER_FROM;
    },

    // Suggested prompts belong to a conversation nobody has written in yet
    get showPrompts() {
        return this.tab === 'ask' && this.thread.loaded && !this.thread.messages.some((message) => message.sender_type === 'customer');
    },

    // The list as it is drawn: a date line whenever the day changes, then each message
    get rows() {
        const rows = [];
        let day = null;

        this.thread.messages.forEach((message) => {
            const local = message.created_at?.local ?? null;
            const date = local?.match(/^[A-Za-z]+ \d+, \d+/)?.[0] ?? null;

            if (date && date !== day) {
                day = date;
                rows.push({ key: `date-${date}`, type: 'date', text: date });
            }

            rows.push({
                key: message.key ?? `m-${message.id}`,
                type: message.sender_type === 'system' ? 'notice' : 'bubble',
                time: local && date ? local.slice(date.length).trim() : null,
                message,
            });
        });

        return rows;
    },

    // ---- Opening and closing the panel ----

    toggle() {
        this.open ? this.close() : this.show();
    },

    show() {
        this.open = true;
        this.lockPage(true);
        this.loadTab();
        this.$nextTick(() => {
            this.fitSheet();
            this.scrollDown(true);
            this.$refs.input?.focus();
        });
    },

    close() {
        if (this.mode !== 'compact' || !this.open) return;

        this.open = false;
        this.lockPage(false);
        clearTimeout(this.pollTimer);
        this.$nextTick(() => this.$refs.launcher?.focus());
    },

    isSheet() {
        return this.mode === 'compact' && window.innerWidth < SHEET_BELOW_PX;
    },

    lockPage(locked) {
        document.documentElement.classList.toggle('overflow-hidden', locked && this.isSheet());
    },

    // On phones the sheet follows the visible area, so the keyboard never covers the composer
    fitSheet() {
        const panel = this.$refs.panel;
        const viewport = window.visualViewport;

        if (!panel || !viewport || !this.open || !this.isSheet()) {
            panel?.style.removeProperty('--chat-height');
            panel?.style.removeProperty('--chat-top');

            return;
        }

        panel.style.setProperty('--chat-height', `${viewport.height}px`);
        panel.style.setProperty('--chat-top', `${viewport.offsetTop}px`);
    },

    // The mobile sheet keeps Tab inside it; on larger screens the panel sits beside the page and does not trap it
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

    switchTab(next) {
        if (this.tab === next) return;

        this.tab = next;
        this.banner = null;
        this.loadTab();
        this.$nextTick(() => this.scrollDown(true));
    },

    dismissPolicy() {
        this.policyHidden = true;
        writeSession(BANNER_KEY, '1');
    },

    // ---- Talking to the server ----

    async request(url, method = 'GET', body = null) {
        const response = await fetch(url, {
            method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(body === null ? {} : { 'Content-Type': 'application/json' }),
                ...(method === 'GET' ? {} : { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' }),
            },
            body: body === null ? undefined : JSON.stringify(body),
        });

        let data = null;

        try {
            data = await response.json();
        } catch (error) {
            // Not JSON (for example a redirect to a page): handled as a failure below
        }

        return { ok: response.ok && data !== null, status: response.status, data };
    },

    threadUrl(key, id) {
        return urls[key].replace('999999999', id);
    },

    // Shows what went wrong in plain words: the server's own text when it sent one, never a raw error
    fail(response, retry = null) {
        const { status, data } = response;

        if (status === 401 || status === 419) {
            this.banner = { kind: 'session', text: status === 401 ? texts.signedOut : (data?.message ?? texts.expired) };
        } else if (data?.code === 'conversation_full') {
            this.banner = { kind: 'full', text: data.message };
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

    // ---- Loading ----

    async loadTab() {
        const thread = this.thread;
        const tab = this.tab;

        if (this.signInNeeded) return;

        if (thread.loaded) {
            if (tab === 'studio') {
                this.markRead();
                this.schedulePoll(true);
            }

            return;
        }

        if (thread.loading) return;

        if (this.guest) {
            thread.messages = [{ key: 'intro', sender_type: 'assistant', sender_label: `${this.name} (AI)`, body: intro, cards: [], created_at: null }];
            thread.loaded = true;

            return;
        }

        thread.loading = true;

        try {
            const response = tab === 'studio' ? await this.request(urls.studio) : await this.loadLatestAsk();

            if (!response.ok) {
                this.fail(response, () => this.loadTab());

                return;
            }

            this.applyThread(thread, response.data);
            thread.loaded = true;
            this.$nextTick(() => this.scrollDown(true));

            if (tab === 'studio') {
                this.markRead();
                this.schedulePoll();
            }

            this.recover(tab);
        } catch (error) {
            this.failOffline(() => this.loadTab());
        } finally {
            thread.loading = false;
        }
    },

    // The customer's latest Ask Lumi conversation, or a new one when there is none
    async loadLatestAsk() {
        const list = await this.request(urls.assistantIndex);

        if (!list.ok) return list;

        const latest = list.data.conversations[0];

        return latest ? this.request(this.threadUrl('thread', latest.id)) : this.request(urls.assistantStore, 'POST', {});
    },

    applyThread(thread, data) {
        thread.conversation = data.conversation;
        thread.messages = data.messages;
        thread.state = data.state;
    },

    // The page may have closed before Lumi was asked: ask once for a recent customer message with no reply
    recover(tab) {
        const thread = this.threads[tab];
        const last = thread.messages[thread.messages.length - 1];

        if (thread.recovered || last?.sender_type !== 'customer') return;

        thread.recovered = true;

        if (Date.now() - new Date(last.created_at.iso).getTime() < RECOVER_WITHIN_MS) {
            this.askLumi(tab);
        }
    },

    async loadEarlier() {
        const thread = this.thread;
        const first = thread.messages.find((message) => message.id);

        if (!first || thread.earlier) return;

        thread.earlier = true;

        const log = this.$refs.log;
        const height = log.scrollHeight;

        try {
            const response = await this.request(`${this.threadUrl('thread', thread.conversation.id)}?before=${first.id}`);

            if (!response.ok) {
                this.fail(response, () => this.loadEarlier());

                return;
            }

            thread.messages = [...response.data.messages, ...thread.messages];
            thread.state.has_more_before = response.data.state.has_more_before;
            this.$nextTick(() => {
                log.scrollTop = log.scrollHeight - height;
            });
        } catch (error) {
            this.failOffline(() => this.loadEarlier());
        } finally {
            thread.earlier = false;
        }
    },

    // ---- Sending ----

    submit() {
        if (!this.canSend) return;

        const body = this.draft.trim();

        this.draft = '';
        this.banner = null;
        this.lastActivity = Date.now();
        this.$nextTick(() => this.resize());

        return this.guest ? this.sendGuest(body) : this.send(body);
    },

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
        input.style.height = `${Math.min(input.scrollHeight, 144)}px`;
    },

    addLocal(thread, body, payload) {
        const local = {
            key: `local-${++this.localKey}`,
            local: true,
            status: 'sending',
            sender_type: 'customer',
            sender_label: texts.you,
            mine: true,
            body,
            cards: [],
            created_at: null,
            payload,
        };

        thread.messages.push(local);
        this.$nextTick(() => this.scrollDown(true));

        return thread.messages[thread.messages.length - 1];
    },

    send(body) {
        const tab = this.tab;
        const payload = { body, client_id: clientId() };

        if (tab === 'studio' && this.about) {
            payload.about = { type: this.about.type, id: this.about.id };
        }

        if (tab === 'studio' && this.fromAssistant) {
            payload.from_assistant_conversation = this.fromAssistant;
        }

        // Each is used for one message only
        if (tab === 'studio') {
            this.about = null;
            this.fromAssistant = null;
        }

        return this.deliver(tab, this.addLocal(this.threads[tab], body, payload));
    },

    // Also the retry of a failed bubble: the same client_id, so the server stores it once however often it arrives
    async deliver(tab, local) {
        const thread = this.threads[tab];

        local.status = 'sending';
        thread.sending = true;

        try {
            const response = await this.request(this.threadUrl('thread', thread.conversation.id), 'POST', local.payload);

            if (!response.ok) {
                local.status = 'failed';
                this.fail(response);

                return;
            }

            this.replaceLocal(thread, local, response.data.message);
            thread.state = { ...thread.state, ...response.data.state };
            this.askLumi(tab);
        } catch (error) {
            local.status = 'failed';
            this.failOffline(() => this.retryMessage(local));
        } finally {
            thread.sending = false;
        }
    },

    retryMessage(local) {
        this.banner = null;

        if (this.guest) {
            this.thread.messages = this.thread.messages.filter((message) => message !== local);
            this.sendGuest(local.body);

            return;
        }

        this.deliver(this.tab, local);
    },

    replaceLocal(thread, local, stored) {
        // A poll may have brought the stored message first
        thread.messages = thread.messages.some((message) => message.id === stored.id)
            ? thread.messages.filter((message) => message !== local)
            : thread.messages.map((message) => (message === local ? stored : message));
    },

    // A reply is expected in Ask Lumi always, and in the Studio only while the owner is away
    expectsReply(tab) {
        const studio = this.threads.studio.state?.studio;

        return tab === 'ask' || (studio?.owner_away && !studio?.needs_owner);
    },

    async askLumi(tab, attempt = 0) {
        const thread = this.threads[tab];

        thread.typing = this.expectsReply(tab);
        this.$nextTick(() => this.scrollDown());

        try {
            const response = await this.request(this.threadUrl('lumi', thread.conversation.id), 'POST', {});

            if (!response.ok) {
                thread.typing = false;
                this.fail(response, () => this.askLumi(tab));

                return;
            }

            if (response.data.status === 'in_progress' && attempt < LUMI_RETRIES) {
                setTimeout(() => this.askLumi(tab, attempt + 1), LUMI_RETRY_MS);

                return;
            }

            thread.typing = false;
            thread.state = response.data.state;
            this.merge(thread, response.data.messages);
            this.lastActivity = Date.now();

            if (tab === 'studio') {
                this.markRead();
            }

            // Lumi stayed quiet or its answer was dropped: whatever the studio wrote instead arrives with the refresh
            if (['not_allowed', 'nothing_to_do', 'discarded', 'in_progress'].includes(response.data.status)) {
                this.refresh(tab);
            }
        } catch (error) {
            thread.typing = false;
            this.failOffline(() => this.askLumi(tab));
        }
    },

    async sendGuest(body) {
        const thread = this.threads.ask;
        const history = thread.messages
            .filter((message) => message.sender_type === 'customer' && message.status !== 'failed')
            .slice(-4)
            .map((message) => message.body);
        const local = this.addLocal(thread, body, null);

        thread.sending = true;
        thread.typing = true;

        try {
            const response = await this.request(urls.guest, 'POST', { message: body, history });

            if (!response.ok) {
                local.status = 'failed';
                this.fail(response);

                return;
            }

            local.status = 'sent';

            const fromModel = ['answered', 'handoff', 'refused'].includes(response.data.outcome);

            thread.messages.push({
                key: `local-${++this.localKey}`,
                sender_type: fromModel ? 'assistant' : 'system',
                sender_label: `${this.name} (AI)`,
                body: response.data.reply,
                cards: response.data.cards,
                created_at: null,
            });
        } catch (error) {
            local.status = 'failed';
            this.failOffline(() => this.retryMessage(local));
        } finally {
            thread.sending = false;
            thread.typing = false;
            this.$nextTick(() => this.scrollDown());
        }
    },

    // ---- Conversations ----

    async newConversation() {
        this.banner = null;

        if (this.guest) {
            this.threads.ask = newThread();
            this.drafts.ask = '';
            this.loadTab();

            return;
        }

        try {
            const response = await this.request(urls.assistantStore, 'POST', {});

            if (!response.ok) {
                this.fail(response);

                return;
            }

            const thread = newThread();

            this.applyThread(thread, response.data);
            thread.loaded = true;
            this.threads.ask = thread;
            this.drafts.ask = '';
            this.tab = 'ask';
            this.$nextTick(() => {
                this.scrollDown(true);
                this.$refs.input?.focus();
            });
        } catch (error) {
            this.failOffline(() => this.newConversation());
        }
    },

    // Nothing is sent here: the customer reads and edits the draft, then presses send
    async contactOwner() {
        this.banner = null;

        if (this.guest) {
            this.tab = 'studio';

            return;
        }

        const ask = this.threads.ask;
        const asked = ask.messages.some((message) => message.sender_type === 'customer' && message.id);

        if (!ask.conversation || !asked) {
            this.switchTab('studio');

            return;
        }

        try {
            const response = await this.request(this.threadUrl('handoff', ask.conversation.id));

            if (!response.ok) {
                this.fail(response);

                return;
            }

            this.drafts.studio = response.data.draft;
            this.fromAssistant = response.data.from_assistant_conversation;
            this.switchTab('studio');
            this.$nextTick(() => {
                this.resize();
                this.$refs.input?.focus();
            });
        } catch (error) {
            this.failOffline(() => this.contactOwner());
        }
    },

    // ---- Keeping the studio thread current ----

    visible() {
        return document.visibilityState === 'visible' && this.open;
    },

    onVisibility() {
        if (!this.guest && document.visibilityState === 'visible') {
            this.refreshUnread();
            this.schedulePoll(true);

            return;
        }

        clearTimeout(this.pollTimer);
    },

    // The server's hints: fast while in use, slower when quiet, slowest after a long quiet time
    pollDelay() {
        const poll = this.threads.studio.state?.poll;
        const quiet = Date.now() - this.lastActivity;

        if (!poll) return 5000;
        if (quiet < OPEN_FOR_MS) return poll.open * 1000;

        return (quiet < IDLE_FOR_MS ? poll.idle : poll.long_idle) * 1000;
    },

    schedulePoll(now = false) {
        clearTimeout(this.pollTimer);

        if (this.guest || !this.threads.studio.loaded || this.tab !== 'studio' || !this.visible()) return;

        this.pollTimer = setTimeout(() => this.poll(), now ? 0 : this.pollDelay());
    },

    async poll() {
        await this.refresh('studio');
        this.schedulePoll();
    },

    // Fetches every message after the last one known, asking again while the server says more are waiting
    async refresh(tab) {
        const thread = this.threads[tab];

        if (this.polling || !thread.conversation) return;

        this.polling = true;

        try {
            let more = true;

            while (more) {
                const last = [...thread.messages].reverse().find((message) => message.id);
                const response = await this.request(`${this.threadUrl('thread', thread.conversation.id)}?after=${last?.id ?? 0}`);

                if (!response.ok) {
                    if (response.status === 401 || response.status === 419) {
                        this.fail(response);
                    }

                    return;
                }

                const before = thread.messages.length;

                thread.state = { ...response.data.state, has_more_before: thread.state?.has_more_before ?? false };
                this.merge(thread, response.data.messages);
                more = response.data.state.has_more_after;

                if (thread.messages.length > before) {
                    this.lastActivity = Date.now();

                    if (tab === 'studio') {
                        this.markRead();
                    }
                }
            }

            if (this.banner?.kind === 'offline') {
                this.banner = null;
            }
        } catch (error) {
            this.failOffline(() => this.schedulePoll(true));
        } finally {
            this.polling = false;
        }
    },

    merge(thread, messages) {
        const known = new Set(thread.messages.map((message) => message.id).filter(Boolean));
        const fresh = messages.filter((message) => !known.has(message.id));

        if (fresh.length === 0) return;

        const near = this.isNearBottom();

        thread.messages.push(...fresh);
        this.$nextTick(() => this.scrollDown(near));
    },

    async markRead() {
        const thread = this.threads.studio;

        if (this.tab !== 'studio' || !this.visible() || !thread.conversation) return;

        this.unread = 0;

        try {
            await this.request(this.threadUrl('read', thread.conversation.id), 'POST', {});
        } catch (error) {
            // Read again on the next poll: nothing the customer needs to know about
        }
    },

    async refreshUnread() {
        if (document.visibilityState !== 'visible' || (this.tab === 'studio' && this.visible())) return;

        try {
            const response = await this.request(urls.unread);

            if (response.ok) {
                this.unread = response.data.studio_unread;
            }
        } catch (error) {
            // The badge simply stays as it was until the next try
        }
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
