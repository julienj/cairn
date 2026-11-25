import { bare, cut, describe, keep, now } from './text.js';

const MAX_ERRORS = 30;

/*
 * The errors of the page, from the moment the widget is loaded: what the
 * page writes with console.error, the errors nobody caught and the
 * promises nobody handled. What the browser writes in the console by
 * itself (a resource that failed to load) cannot be read by a script.
 * `noted` is called after each one. Returns the function that gives what
 * was noted so far.
 */
export function watchErrors(noted) {
    const errors = [];
    const note = (error) => {
        keep(errors, MAX_ERRORS, error);
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
            return; // an image or a script that did not load: not an error of the code
        }
        note({ kind: 'error', message: cut(event.message), source: event.filename ? `${bare(event.filename)}:${event.lineno}` : null, at: now() });
    });
    window.addEventListener('unhandledrejection', (event) => {
        note({ kind: 'rejection', message: cut(describe(event.reason)), source: null, at: now() });
    });

    return () => errors.slice();
}
