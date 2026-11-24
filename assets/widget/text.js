/*
 * What every note of the widget goes through before it is kept: texts are
 * cut, addresses lose what they may carry of private, lists are bounded.
 */

const MAX_TEXT = 500;

export function now() {
    return new Date().toISOString();
}

export function cut(text) {
    text = String(text);

    return text.length > MAX_TEXT ? `${text.slice(0, MAX_TEXT)}…` : text;
}

/* An address without its parameters nor its fragment: a token or an e-mail may sit there. */
export function bare(address) {
    try {
        const url = new URL(address, window.location.href);

        return url.origin + url.pathname;
    } catch {
        return cut(address);
    }
}

/* Adds to a list that keeps its last `max` entries. */
export function keep(list, max, entry) {
    list.push(entry);
    if (list.length > max) {
        list.shift();
    }
}

const MAX_KEYS = 10;

/*
 * What the page handed to console.error, as a text. An object is told by
 * its first level only (`{status: 500, data: {…}}`): the page may log its
 * whole state, and writing all of it out to keep a line would slow the
 * site down.
 */
export function describe(value) {
    if (value instanceof Error) {
        return `${value.name}: ${value.message}`;
    }
    if ('object' !== typeof value || null === value) {
        return String(value);
    }
    try {
        if (Array.isArray(value)) {
            return `[${value.slice(0, MAX_KEYS).map(brief).join(', ')}${value.length > MAX_KEYS ? ', …' : ''}]`;
        }
        const keys = Object.keys(value);

        return `{${keys.slice(0, MAX_KEYS).map((key) => `${key}: ${brief(value[key])}`).join(', ')}${keys.length > MAX_KEYS ? ', …' : ''}}`;
    } catch {
        return Object.prototype.toString.call(value);
    }
}

function brief(value) {
    if ('string' === typeof value) {
        return JSON.stringify(cut(value));
    }
    if ('object' === typeof value && null !== value) {
        return Array.isArray(value) ? '[…]' : '{…}';
    }

    return 'function' === typeof value ? 'ƒ' : String(value);
}
