import { bare, cut, keep, now } from './text.js';

const MAX_PAGES = 20;
const KEY = 'cairn-widget-pages';

/*
 * The pages seen in this tab, kept by the site itself in its
 * sessionStorage: the list lasts as long as the tab, and is nobody's
 * history. A page that changes its address without loading (an
 * application in one page) is another page seen. A page that does not
 * load the widget is missing from the list.
 * Returns the function that gives the list.
 */
export function watchPages() {
    const pages = () => {
        try {
            const list = JSON.parse(window.sessionStorage.getItem(KEY) || '[]');

            // the storage is the site's: anything else may have written there
            return Array.isArray(list) ? list.filter((page) => page && 'string' === typeof page.url) : [];
        } catch {
            return [];
        }
    };

    const seen = () => {
        const list = pages();
        const url = bare(window.location.href);
        if (list.length && list[list.length - 1].url === url) {
            return;
        }
        keep(list, MAX_PAGES, { url, title: cut(document.title), at: now() });
        try {
            window.sessionStorage.setItem(KEY, JSON.stringify(list));
        } catch { /* no storage: the list is this page alone */ }
    };

    for (const name of ['pushState', 'replaceState']) {
        const native = window.history[name];
        window.history[name] = function () {
            const result = native.apply(this, arguments);
            // once the page gave itself its new title
            window.setTimeout(seen, 0);

            return result;
        };
    }
    window.addEventListener('popstate', () => window.setTimeout(seen, 0));
    seen();

    return pages;
}
