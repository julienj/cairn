import { bare, cut, describe, keep, now, RECENT, remembered } from './text.js';

const MAX_ERRORS = 30;
const KEY = 'cairn-widget-errors';

/*
 * The errors of the page, from the moment the widget is loaded: what the
 * page writes with console.error, the errors nobody caught and the
 * promises nobody handled. A file that failed to load is not an error of
 * the code: network.js notes it.
 * The site keeps them in its sessionStorage, as it keeps what was done
 * (actions.js): the error an action caused is still beside it on the
 * next page.
 * `noted` is called after each one. Returns the function that gives what
 * was noted so far.
 */
export function watchErrors(noted) {
    const { list: errors, save } = remembered(KEY, MAX_ERRORS, RECENT);
    const note = (error) => {
        keep(errors, MAX_ERRORS, error);
        save();
        noted();
    };

    const consoleError = window.console?.error;
    if (consoleError) {
        window.console.error = function (...args) {
            try {
                note({ kind: 'console', message: cut(args.map(describe).join(' ')), source: null, at: now() });
            } catch { /* noting must never break the page */ }

            return consoleError.apply(this, args);
        };
    }
    window.addEventListener('error', (event) => {
        if (!event.message) {
            return; // an image or a script that did not load: network.js
        }
        note({ kind: 'error', message: cut(event.message), source: event.filename ? `${bare(event.filename)}:${event.lineno}` : null, at: now() });
    });
    window.addEventListener('unhandledrejection', (event) => {
        note({ kind: 'rejection', message: cut(describe(event.reason)), source: null, at: now() });
    });

    return () => errors.slice();
}
