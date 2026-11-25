/*
 * The widget of Cairn, as a site being reviewed loads it:
 *
 *   <script src="https://cairn.example.com/widget.js" data-project="MD-SPT" data-display="always" async></script>
 *
 * Without data-display="always" (production), it does nothing at all
 * until someone asks for it with `?cairn=1` (activation.js).
 *
 * Built by Vite into one file of its own (vite.config.ts, mode "widget"), at an
 * address that never changes: a classic script, not a module, because a
 * module only runs once the page is read, and what happened before the
 * widget loaded is not in its report. It has no dependency and holds
 * neither an account nor a token. It does three things.
 *
 * 1. From the moment it is loaded, it notes what helps reproduce a
 *    problem: the errors of the page (errors.js), its requests that
 *    failed, Ajax or files (network.js), the pages seen in this tab
 *    (pages.js), and what the site says of itself in `window.Cairn`
 *    (context.js); and, when the report is handed over, the zoom and the
 *    preferences of the system (preferences.js).
 * 2. It asks Cairn whether the project knows this site. Only then does it
 *    show its button; on any other site nothing shows.
 * 3. The button opens a panel holding a page of Cairn (panel.js), which
 *    it hands what it noted, and again each time it notes more.
 */
import { wanted } from './activation.js';
import { watchContext } from './context.js';
import { watchErrors } from './errors.js';
import { watchNetwork } from './network.js';
import { watchPages } from './pages.js';
import { createPanel } from './panel.js';
import { preferences, zoom } from './preferences.js';
import { bare, cut, now } from './text.js';

const script = document.currentScript;
const keys = /^([A-Z0-9]{2,5})-([A-Z0-9]{2,5})$/.exec((script?.getAttribute('data-project') ?? '').toUpperCase());

if (script && keys && window.fetch && !window.__cairnWidget && wanted(script)) {
    window.__cairnWidget = true;

    const cairn = new URL(script.src, window.location.href).origin;
    const address = `${cairn}/widget/${keys[1]}/${keys[2]}`;

    // the panel, once there, hears of what is noted
    let noted = () => {};
    const errors = watchErrors(() => noted());
    const { network, nativeFetch } = watchNetwork(cairn, () => noted());
    const pages = watchPages(() => noted());
    const context = watchContext(() => noted());

    const report = () => ({
        page: { url: bare(window.location.href), title: cut(document.title) },
        environment: {
            userAgent: cut(window.navigator.userAgent),
            language: window.navigator.language || null,
            viewport: { width: window.innerWidth, height: window.innerHeight },
            screen: { width: window.screen.width, height: window.screen.height },
            pixelRatio: window.devicePixelRatio || 1,
            zoom: zoom(),
            preferences: preferences(),
        },
        context: context(),
        errors: errors(),
        network: network(),
        pages: pages(),
        collectedAt: now(),
    });

    nativeFetch(`${address}/site`, { credentials: 'omit' })
        .then((response) => (response.ok ? response.json() : null))
        .then((site) => {
            if ('string' === typeof site?.label) {
                noted = createPanel({ cairn, address, label: site.label, report });
            }
        })
        .catch(() => { /* Cairn does not know this site, or cannot be reached: no button */ });
}
