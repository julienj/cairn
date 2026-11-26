import { bare, keep, now, RECENT, remembered } from './text.js';

const MAX_ACTIONS = 30;
const MAX_NAME = 60;
const MAX_SELECTOR = 120;
const KEY = 'cairn-widget-actions';

const ACTED = 'a[href], button, summary, input[type="submit"], input[type="button"], input[type="reset"], input[type="image"], [role="button"], [role="link"], [role="tab"], [role="menuitem"], [role="option"], [role="switch"], [role="checkbox"], [role="radio"]';
const FIELD = 'input, select, textarea';
const ROLES = { a: 'link', button: 'button', summary: 'button', select: 'combobox', textarea: 'textbox', form: 'form' };

/*
 * What the reporter did on the site before reporting, the last actions
 * only: what was clicked, which field was changed, which form was sent.
 * An action says what was acted on, never what was typed nor chosen: the
 * name a button, a link or a field shows (cut short), its role, and where
 * it stands in the page. No key is listened to. Where a site marks a zone
 * `data-cairn-private`, the names of what is in it are left out too.
 *
 * A change of page is not an action: pages.js notes it. What an action
 * caused, a request that failed or an error, is noted by network.js and
 * errors.js: each list keeps its own facts, and their moments put them in
 * order.
 *
 * Kept by the site itself in its sessionStorage, as the pages seen are:
 * what was done on the page before is what led to this one, for ten
 * minutes. `noted` is
 * called after each action. Returns the function that gives the list.
 */
export function watchActions(noted) {
    const { list: actions, save } = remembered(KEY, MAX_ACTIONS, RECENT);

    const note = (kind, element) => {
        const selector = path(element);
        const page = bare(window.location.href);
        const last = actions[actions.length - 1];
        if (last && last.kind === kind && last.selector === selector && last.page === page) {
            // the same thing again: one line that counts
            last.count = (last.count || 1) + 1;
        } else {
            keep(actions, MAX_ACTIONS, { kind, role: role(element), name: name(element), selector, page, count: 1, at: now() });
        }
        save();
        noted();
    };

    // The element an event is about, null for what happens in the widget itself.
    const about = (event) => {
        const element = event.composedPath?.()[0] ?? event.target;

        return element instanceof Element && !element.closest('[data-cairn-widget]') && !(event.target instanceof Element && event.target.closest('[data-cairn-widget]')) ? element : null;
    };
    const listen = (type, heard) => document.addEventListener(type, (event) => {
        try {
            const element = about(event);
            if (element) {
                heard(element);
            }
        } catch { /* noting must never break the page */ }
    }, true);

    listen('click', (element) => {
        const acted = element.closest(ACTED);
        if (acted) {
            note('click', acted);
        } else if (!element.closest(`${FIELD}, label, [contenteditable]`)) {
            // a click on what answers to nothing may be the problem itself; a field is noted when it changes
            note('click', element);
        }
    });
    listen('change', (element) => {
        if (element.matches(FIELD)) {
            note('change', element);
        }
    });
    listen('submit', (element) => note('submit', element));

    return () => {
        // on a page that stays, what was done long ago is no longer what led to the problem
        const since = Date.now() - RECENT;

        return actions.filter((action) => !(Date.parse(action.at) < since));
    };
}

function role(element) {
    const given = element.getAttribute('role');
    if (given) {
        return given.toLowerCase().slice(0, 30);
    }
    const tag = element.tagName.toLowerCase();
    if ('input' === tag) {
        const type = (element.getAttribute('type') || 'text').toLowerCase();

        return ['checkbox', 'radio'].includes(type) ? type : ['submit', 'button', 'reset', 'image'].includes(type) ? 'button' : 'textbox';
    }

    return ROLES[tag] ?? tag;
}

/*
 * The name the page gives to what was acted on: what a button or a link
 * shows, the label of a field. Never the content of a field, and nothing
 * in a zone the site keeps private.
 */
function name(element) {
    if (element.closest('[data-cairn-private]')) {
        return null;
    }
    let text = element.getAttribute('aria-label');
    if (!text && element.matches(FIELD)) {
        const type = (element.getAttribute('type') || '').toLowerCase();
        // the value of a button is what it shows; the value of any other field is what was typed
        text = ['submit', 'button', 'reset'].includes(type) ? element.value : (element.labels?.[0]?.textContent || element.getAttribute('placeholder') || element.getAttribute('alt') || element.getAttribute('name'));
    } else if (!text && element.matches(ACTED)) {
        text = element.textContent || element.getAttribute('title') || element.querySelector('img[alt]')?.getAttribute('alt');
    }
    text = String(text || '').replace(/\s+/g, ' ').trim();

    return '' === text ? null : (text.length > MAX_NAME ? `${text.slice(0, MAX_NAME)}…` : text);
}

/* Where the element stands, as a short selector: up to three levels, an id ends it, and what has siblings like it says which it is. */
function path(element) {
    const parts = [];
    for (let node = element; node instanceof Element && parts.length < 3 && node !== document.documentElement && node !== document.body; node = node.parentElement) {
        const id = node.getAttribute('id');
        if (id && /^[A-Za-z][\w-]*$/.test(id)) {
            parts.unshift(`#${id}`);
            break;
        }
        const test = node.getAttribute('data-testid');
        const classes = Array.from(node.classList).filter((one) => /^[A-Za-z_-][\w-]*$/.test(one)).slice(0, 2);
        if (test && /^[\w-]+$/.test(test)) {
            parts.unshift(`${node.tagName.toLowerCase()}[data-testid="${test}"]`);
            continue;
        }
        // among its like, which one: two buttons side by side are two things
        const like = Array.from(node.parentElement?.children ?? []).filter((one) => one.tagName === node.tagName);
        parts.unshift(node.tagName.toLowerCase() + classes.map((one) => `.${one}`).join('') + (like.length > 1 ? `:nth-of-type(${like.indexOf(node) + 1})` : ''));
    }

    return parts.join(' > ').slice(0, MAX_SELECTOR) || null;
}
