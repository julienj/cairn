const KEY = 'cairn-widget-shown';
const PARAMETER = 'cairn';

/*
 * Whether the widget wakes up on this page. A site that says so on its
 * tag (data-display="always", a site being reviewed) has it for
 * everybody. Anywhere else, production above all, it is there for whoever
 * asked for it only: `?cairn=1` in the address of any page asks, the site
 * remembers it (localStorage: for this site, in this browser) so that the
 * button follows from page to page, and `?cairn=0` forgets it.
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
        }

        return '1' === window.localStorage.getItem(KEY);
    } catch {
        // no storage: the widget is there for the pages that ask
        return '1' === asked;
    }
}
