import { bare, keep, now } from './text.js';

const MAX_FAILURES = 60;
/* What made the browser ask for a file, as it says it, by the kind the report gives. */
const INITIATORS = { img: 'image', image: 'image', script: 'script', link: 'stylesheet', css: 'stylesheet', video: 'media', audio: 'media', track: 'media' };
const TAGS = { IMG: 'image', IMAGE: 'image', SCRIPT: 'script', LINK: 'stylesheet', VIDEO: 'media', AUDIO: 'media', SOURCE: 'media', TRACK: 'media' };
/* A style sheet also loads fonts and images: their address says which. */
const FONT = /\.(woff2?|ttf|otf|eot)$/i;
const IMAGE = /\.(png|jpe?g|gif|webp|avif|svg|ico|bmp)$/i;
/* What the code of the page asks for: noted with its method by the watch of fetch and XMLHttpRequest. */
const AJAX = ['fetch', 'xmlhttprequest'];

/*
 * Every request of the page that failed, Ajax or file, in the order it
 * failed: its kind, the method of an Ajax request, its address without
 * its parameters, the status of the answer and its moment. Nothing of
 * what a request carried or received is read.
 *
 * - fetch and XMLHttpRequest are watched, for their method and for the
 *   requests that got no answer at all (status 0);
 * - everything else the browser timed with a status in error is noted as
 *   it tells it, from the start of the page, before the widget was there
 *   too (not Safari, which gives no status);
 * - so that Safari has them too, the error of an element that asked for a
 *   file, and the images already broken, without a status.
 *
 * A file is noted once, an Ajax request each time it fails. The requests
 * made to Cairn itself (`ignored`, its origin) are not the page's.
 * `noted` is called after each one. Returns the function that gives what
 * was noted so far, and the fetch of the browser as it was, for the
 * widget's own request.
 */
export function watchNetwork(ignored, noted) {
    const failures = [];
    const files = new Map();

    const note = (failure) => {
        if ('' === failure.url || failure.url.startsWith(`${ignored}/`)) {
            return;
        }
        if ('ajax' !== failure.kind) {
            const known = files.get(failure.url);
            if (known) {
                known.status ??= failure.status;

                return;
            }
            files.set(failure.url, failure);
        }
        keep(failures, MAX_FAILURES, failure);
        noted();
    };
    const ajax = (method, address, status) => note({ kind: 'ajax', method: String(method || 'GET').toUpperCase(), url: bare(address), status, at: now() });

    const nativeFetch = window.fetch;
    window.fetch = function (input, init) {
        const method = init?.method ?? input?.method ?? 'GET';
        const address = 'string' === typeof input ? input : input?.url ?? String(input);

        return nativeFetch.apply(this, arguments).then((response) => {
            if (response.status >= 400) {
                ajax(method, address, response.status);
            }

            return response;
        }, (error) => {
            ajax(method, address, 0);
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
                        ajax(last.method, last.address, this.status);
                    }
                });
            }
            opened.set(this, { method, address });

            return open.apply(this, arguments);
        };
    }

    try {
        new PerformanceObserver((list) => {
            for (const entry of list.getEntries()) {
                if (entry.responseStatus >= 400 && !AJAX.includes(entry.initiatorType)) {
                    note({ kind: kindOf(entry.name, INITIATORS[entry.initiatorType]), method: null, url: bare(entry.name), status: entry.responseStatus, at: momentOf(entry) });
                }
            }
        }).observe({ type: 'resource', buffered: true });
    } catch { /* a browser that times nothing: the elements tell what failed */ }

    window.addEventListener('error', (event) => {
        const element = event.target;
        const address = element instanceof Element ? element.currentSrc || element.src || element.href : null;
        if ('string' === typeof address && '' !== address) {
            note({ kind: kindOf(address, TAGS[element.tagName.toUpperCase()]), method: null, url: bare(address), status: null, at: now() });
        }
    }, true);

    const broken = () => {
        for (const image of document.images) {
            const address = image.currentSrc || image.src;
            if (address && image.complete && 0 === image.naturalWidth && 0 === image.naturalHeight) {
                note({ kind: 'image', method: null, url: bare(address), status: null, at: now() });
            }
        }
    };

    return {
        network: () => {
            try {
                broken();
            } catch { /* noting must never break the page */ }

            return failures.slice().sort((one, other) => one.at.localeCompare(other.at));
        },
        nativeFetch: nativeFetch.bind(window),
    };
}

function kindOf(address, initiated) {
    let path = address;
    try {
        path = new URL(address, window.location.href).pathname;
    } catch { /* the address as it is */ }
    if (FONT.test(path)) {
        return 'font';
    }

    return IMAGE.test(path) ? 'image' : initiated ?? 'other';
}

/* The moment a timed request got its answer, so that one told afterwards keeps its place among the others. */
function momentOf(entry) {
    return new Date(window.performance.timeOrigin + (entry.responseEnd || entry.startTime)).toISOString();
}
