import { buttonStyles, createButton } from './button.js';

const KEY = 'cairn-widget-open';
/* How long what is noted waits before going to the frame, so that a burst goes as one message, in milliseconds. */
const NOTED_WAIT = 300;

/*
 * What the widget shows on the site: its button, and the panel that
 * holds a page of Cairn in a frame the site cannot read. Both live in a
 * closed shadow tree, out of reach of the styles of the site and of its
 * own. The frame is only created the first time the panel opens. Whether
 * the panel is open is kept in the sessionStorage of the site, so that it
 * stays open from page to page in this tab.
 *
 * The frame and the script talk by messages, each checking where the
 * other's come from: the script hands over what it noted (`report()`),
 * again whenever it notes more (the function returned, which waits for
 * a burst to end), closes the panel when asked to, and takes the whole widget out of the
 * picture while the frame takes a screenshot.
 */
export function createPanel({ cairn, address, label, report }) {
    const host = document.createElement('div');
    host.setAttribute('data-cairn-widget', '');
    const root = host.attachShadow({ mode: 'closed' });

    const style = document.createElement('style');
    style.textContent = `
:host { all: initial; }
${buttonStyles}
div { position: fixed; top: 0; right: 0; bottom: 0; z-index: 2147483647; width: min(400px, 100vw); background: #fff; box-shadow: -12px 0 32px rgba(17, 24, 39, .18); }
iframe { display: block; width: 100%; height: 100%; border: 0; }
[hidden] { display: none !important; }
`;
    const panel = document.createElement('div');
    panel.hidden = true;
    let frame = null;

    const send = (message) => frame?.contentWindow?.postMessage(message, cairn);
    let pending = null;
    const noted = () => {
        if (null === frame || null !== pending) {
            return;
        }
        pending = window.setTimeout(() => {
            pending = null;
            send({ type: 'cairn:context', report: report() });
        }, NOTED_WAIT);
    };

    const show = (open) => {
        if (open && null === frame) {
            frame = document.createElement('iframe');
            // asked in the name of this site: Cairn answers no other, and lets no other frame the answer
            frame.src = `${address}?site=${encodeURIComponent(window.location.origin)}`;
            frame.title = label;
            frame.allow = 'display-capture';
            panel.appendChild(frame);
        }
        panel.hidden = !open;
        button.hidden = open;
        try {
            if (open) {
                window.sessionStorage.setItem(KEY, '1');
            } else {
                window.sessionStorage.removeItem(KEY);
            }
        } catch { /* no storage: the panel closes with the page */ }
        if (open) {
            send({ type: 'cairn:context', report: report() });
        }
    };
    const button = createButton(label, () => show(true));

    window.addEventListener('message', (event) => {
        if (event.origin !== cairn || null === frame || event.source !== frame.contentWindow) {
            return;
        }
        switch (event.data?.type) {
            case 'cairn:ready':
                send({ type: 'cairn:context', report: report() });
                break;
            case 'cairn:close':
                show(false);
                break;
            case 'cairn:collapse':
                // Out of the picture but still drawn: a frame that is not displayed is slowed down by the
                // browser. Then two frames, for the page to be drawn without the widget.
                host.style.opacity = '0';
                host.style.pointerEvents = 'none';
                window.requestAnimationFrame(() => window.requestAnimationFrame(() => send({ type: 'cairn:collapsed' })));
                break;
            case 'cairn:expand':
                host.style.opacity = '';
                host.style.pointerEvents = '';
                break;
        }
    });

    root.append(style, button, panel);
    // Beside the body, not in it: a site that replaces its body to change page (Turbo, htmx) would take the widget away.
    document.documentElement.appendChild(host);
    try {
        if ('1' === window.sessionStorage.getItem(KEY)) {
            show(true);
        }
    } catch { /* no storage: the panel starts closed */ }

    return noted;
}
