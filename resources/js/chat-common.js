// What the customer chat (chat.js) and the owner inbox (owner-chat.js) share (Phase 7D): talking to the server, client
// ids for idempotent sends, and turning messages into the rows <x-chat.rows> draws.

// refresh loops ask again while the server says more messages are waiting, but never more than this many pages in one go
export const MAX_REFRESH_PAGES = 10;

export const NEAR_BOTTOM_PX = 120;

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

// Bubbles of one sender closer together than this form one group: the avatar, name and time show once, at its start
const GROUP_WITHIN_MS = 5 * 60 * 1000;

// The list as it is drawn: a date line whenever the day changes (in the shop's time, from the server), then each message.
// `first` marks the start of a group of bubbles from the same sender, so the avatar and name are drawn once per group
export const buildRows = (messages) => {
    const rows = [];
    let day = null;
    let previous = null;

    messages.forEach((message) => {
        const local = message.created_at?.local ?? null;
        const date = local?.match(/^[A-Za-z]+ \d+, \d+/)?.[0] ?? null;

        if (date && date !== day) {
            day = date;
            previous = null;
            rows.push({ key: `date-${date}`, type: 'date', text: date });
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
            message,
        });

        previous = type === 'bubble' ? { sender_type: message.sender_type, sender_label: message.sender_label, at } : null;
    });

    return rows;
};
