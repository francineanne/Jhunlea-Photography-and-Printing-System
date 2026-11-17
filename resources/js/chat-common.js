// What the customer chat (chat.js) and the owner inbox (owner-chat.js) share (Phase 7D): talking to the server, client
// ids for idempotent sends, and turning messages into the rows <x-chat.rows> draws.

// refresh loops ask again while the server says more messages are waiting, but never more than this many pages in one go
export const MAX_REFRESH_PAGES = 10;

export const NEAR_BOTTOM_PX = 120;

// Every chat list scrolls to its end the same way (7F-2): at once when asked to (opening, sending), otherwise a short
// glide, never with reduced motion
export const scrollToEnd = (log, force = false) => {
    const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;

    log.scrollTo({ top: log.scrollHeight, behavior: force || reduced ? 'auto' : 'smooth' });
};

export const clientId = () => {
    if (window.crypto?.randomUUID) {
        return window.crypto.randomUUID();
    }

    return `c-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`;
};

// JSON in and out, with the CSRF token on every write. A reply that is not JSON (a page) counts as a failure
export const apiRequest = async (url, method = 'GET', body = null) => {
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
        // Not JSON: handled as a failure by the caller
    }

    return { ok: response.ok && data !== null, status: response.status, data };
};

// Phase 10E: a message body as safe HTML. Everything is escaped first; only https:// addresses then become links that open
// in a new tab with rel="noopener noreferrer nofollow". No other markup ever comes from a message.
const ESCAPES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
const escapeHtml = (text) => String(text ?? '').replace(/[&<>"']/g, (char) => ESCAPES[char]);

export const linkify = (text) => escapeHtml(text).replace(/https:\/\/[^\s<>"']+/g, (url) => {
    // Punctuation that ends a sentence is not part of the address
    const trail = url.match(/[.,;:!?)\]]+$/)?.[0] ?? '';
    const href = trail ? url.slice(0, -trail.length) : url;

    return `<a href="${href}" target="_blank" rel="noopener noreferrer nofollow" class="font-semibold underline underline-offset-2">${href}</a>${trail}`;
});

// Phase 10E: on a phone (touch, no hover) the Enter key makes a new line and the button sends
export const enterSends = () => !(window.matchMedia?.('(hover: none) and (pointer: coarse)').matches ?? false);

// Phase 10E: the date line says "Today", "Yesterday" or "Nov 15" (the year only when it is not this year), in Manila time
const manilaDay = (offsetDays = 0) => new Intl.DateTimeFormat('en-US', { timeZone: 'Asia/Manila', month: 'short', day: 'numeric', year: 'numeric' })
    .format(new Date(Date.now() - offsetDays * 86400000));

const dayLabel = (date) => {
    if (date === manilaDay(0)) return 'Today';
    if (date === manilaDay(1)) return 'Yesterday';

    return date.endsWith(`, ${manilaDay(0).slice(-4)}`) ? date.replace(/, \d{4}$/, '') : date;
};

// Bubbles of one sender closer together than this form one group: the avatar, name and time show once, at its start
const GROUP_WITHIN_MS = 5 * 60 * 1000;

// The list as it is drawn: a date line whenever the day changes (in the shop's time, from the server), then each message.
// `first` marks the start of a group of bubbles from the same sender, so the avatar and name are drawn once per group
export const buildRows = (messages, seenUpTo = 0) => {
    const rows = [];
    let day = null;
    let previous = null;
    // Phase 10E: "Seen" sits under the viewer's newest own message once the other PERSON has read it (Lumi never counts)
    const lastMine = [...messages].reverse().find((message) => message.mine && message.id)?.id ?? null;

    messages.forEach((message) => {
        const local = message.created_at?.local ?? null;
        const date = local?.match(/^[A-Za-z]+ \d+, \d+/)?.[0] ?? null;

        if (date && date !== day) {
            day = date;
            previous = null;
            rows.push({ key: `date-${date}`, type: 'date', text: dayLabel(date) });
        }

        const type = message.sender_type === 'system' ? 'notice' : 'bubble';
        const at = message.created_at?.iso ? new Date(message.created_at.iso).getTime() : null;
        const continues = type === 'bubble'
            && previous !== null
            && previous.sender_type === message.sender_type
            && previous.sender_label === message.sender_label
            && (at === null || previous.at === null || at - previous.at < GROUP_WITHIN_MS);

        rows.push({
            key: message.key ?? `m-${message.id}`,
            type,
            first: !continues,
            time: local && date ? local.slice(date.length).trim() : null,
            lastMine: lastMine !== null && message.id === lastMine,
            seen: lastMine !== null && message.id === lastMine && seenUpTo >= lastMine,
            message,
        });

        previous = type === 'bubble' ? { sender_type: message.sender_type, sender_label: message.sender_label, at } : null;
    });

    return rows;
};
