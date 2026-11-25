/*
 * How the page performed, as the browser measured it since it began to
 * load, before the widget was there too: the answer of the server
 * (TTFB), the page ready and loaded, and the Core Web Vitals, the largest
 * content drawn (LCP), how much the page moved under the reader (CLS)
 * and the slowest answer to an interaction (INP). Each is null where the
 * browser does not measure it (Safari measures neither CLS nor INP).
 * Times are in milliseconds from the start of the navigation; in a page
 * that changes without loading, they are those of its last load.
 * Returns the function that gives the measures as they are then.
 */
export function watchPerformance() {
    let lcp = null;
    let cls = 0;
    let session = null;
    let inp = 0;

    const observe = (type, take, options = {}) => {
        try {
            new PerformanceObserver((list) => list.getEntries().forEach(take)).observe({ type, buffered: true, ...options });

            return true;
        } catch {
            return false;
        }
    };

    observe('largest-contentful-paint', (entry) => {
        lcp = entry.startTime;
    });
    // the largest burst of shifts, one second apart at most and five seconds long at most, as the CLS counts them
    const shifts = observe('layout-shift', (entry) => {
        if (entry.hadRecentInput) {
            return;
        }
        if (session && entry.startTime - session.last < 1000 && entry.startTime - session.first < 5000) {
            session.value += entry.value;
            session.last = entry.startTime;
        } else {
            session = { value: entry.value, first: entry.startTime, last: entry.startTime };
        }
        cls = Math.max(cls, session.value);
    });
    // the slowest interaction: the INP of a visit of a few interactions, which is what a report holds
    const interactions = observe('event', (entry) => {
        if (entry.interactionId) {
            inp = Math.max(inp, entry.duration);
        }
    }, { durationThreshold: 16 });

    const time = (value) => ('number' === typeof value && value > 0 ? Math.round(value) : null);

    return () => {
        const navigation = window.performance?.getEntriesByType?.('navigation')?.[0];

        return {
            ttfb: time(navigation?.responseStart),
            domContentLoaded: time(navigation?.domContentLoadedEventEnd),
            load: time(navigation?.loadEventEnd),
            lcp: time(lcp),
            cls: shifts ? Math.round(cls * 1000) / 1000 : null,
            inp: interactions ? time(inp) : null,
        };
    };
}
