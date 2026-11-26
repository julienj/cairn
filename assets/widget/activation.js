const KEY = 'cairn-widget-shown';
const PARAMETER = 'cairn';

/*
 * Whether the widget wakes up on this page. A site that says so on its
 * tag (data-display="always", a site being reviewed) has it for
 * everybody. Anywhere else, production above all, it is there for whoever
 * asked for it only: `?cairn=1` in the address of any page asks, the site
 * remembers it (localStorage: for this site, in this browser) so that the
 * button follows from page to page, and `?cairn=0` forgets it, with what
 * the widget had noted in this tab. Either way the parameter then leaves
 * the address.
 *
 * It is asked before anything else, and the page alone answers: for
 * everybody else the script stops there, having listened to nothing,
 * stored nothing and asked Cairn nothing. The parameter is no secret and
 * protects nothing: whoever adds it gets a button, then the sign-in page.
 */
export function wanted(script) {
    if ('always' === script.getAttribute('data-display')) {
        return true;
    }

    let asked = null;
    try {
        asked = new URLSearchParams(window.location.search).get(PARAMETER);
    } catch { /* an address nobody can read asks nothing */ }

    try {
        if ('1' === asked) {
            window.localStorage.setItem(KEY, '1');
        } else if ('0' === asked) {
            window.localStorage.removeItem(KEY);
            forget();
        }
        const shown = '1' === window.localStorage.getItem(KEY);
        if ('1' === asked || '0' === asked) {
            // remembered: the address no longer has to say it
            clean();
        }

        return shown;
    } catch {
        // no storage: the widget is there for the pages that ask
        return '1' === asked;
    }
}

/*
 * Takes the parameter out of the address once the site remembers the
 * answer, so that an address copied from there asks nothing of whoever
 * receives it. The rest of the address is left as it was written, and so
 * is the state the page gave its history. Without storage the parameter
 * stays: it is all that holds the widget on this page.
 */
function clean() {
    try {
        const { pathname, search, hash } = window.location;
        const kept = search.slice(1).split('&').filter((part) => PARAMETER !== part.split('=')[0]);
        window.history.replaceState(window.history.state, '', pathname + (kept.length ? `?${kept.join('&')}` : '') + hash);
    } catch { /* an address that cannot be rewritten keeps its parameter */ }
}

/*
 * What the widget kept for this tab (what it noted, its panel, what Cairn
 * said of the site) goes with it: whoever sends it away leaves nothing
 * behind, and finds it as new when asking again. Where its button was
 * moved to is a preference, and stays.
 */
function forget() {
    try {
        const storage = window.sessionStorage;
        for (let index = storage.length - 1; index >= 0; --index) {
            const key = storage.key(index);
            if (key?.startsWith('cairn-widget-')) {
                storage.removeItem(key);
            }
        }
    } catch { /* no storage: nothing was kept */ }
}
