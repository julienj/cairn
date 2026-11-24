import { bare, keep, now } from './text.js';

const MAX_REQUESTS = 30;

/*
 * The Ajax requests of the page that failed (fetch and XMLHttpRequest):
 * an answer in error, or no answer at all (status 0). Only the method,
 * the address without its parameters, the status and the time are kept:
 * nothing of what a request carried or received is read. The requests
 * made to Cairn itself (`ignored`, its origin) are not the page's.
 * Returns the function that gives what was noted so far, and the fetch
 * of the browser as it was, for the widget's own request.
 */
export function watchRequests(ignored) {
    const requests = [];
    const nativeFetch = window.fetch;

    const failed = (method, address, status) => {
        const url = bare(address);
        if (!url.startsWith(`${ignored}/`)) {
            keep(requests, MAX_REQUESTS, { method: String(method || 'GET').toUpperCase(), url, status, at: now() });
        }
    };

    window.fetch = function (input, init) {
        const method = init?.method ?? input?.method ?? 'GET';
        const address = 'string' === typeof input ? input : input?.url ?? String(input);

        return nativeFetch.apply(this, arguments).then((response) => {
            if (response.status >= 400) {
                failed(method, address, response.status);
            }

            return response;
        }, (error) => {
            failed(method, address, 0);
            throw error;
        });
    };

    if (window.XMLHttpRequest) {
        const open = XMLHttpRequest.prototype.open;
        // what each object was last opened for: one that is opened again is another request, listened to once
        const opened = new WeakMap();
        XMLHttpRequest.prototype.open = function (method, address) {
            if (!opened.has(this)) {
                this.addEventListener('loadend', () => {
                    if (0 === this.status || this.status >= 400) {
                        const last = opened.get(this);
                        failed(last.method, last.address, this.status);
                    }
                });
            }
            opened.set(this, { method, address });

            return open.apply(this, arguments);
        };
    }

    return { requests: () => requests.slice(), nativeFetch: nativeFetch.bind(window) };
}
