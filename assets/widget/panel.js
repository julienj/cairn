import { buttonStyles, clamp, createButton, MARGIN, SIZE, viewport } from './button.js';

const KEY = 'cairn-widget-open';
/* How long what is noted waits before going to the frame, so that a burst goes as one message, in milliseconds. */
const NOTED_WAIT = 300;
/* The panel beside its button: how wide and how tall it gets, and what it leaves between them, in pixels. */
const WIDTH = 400;
const HEIGHT = 760;
const GAP = 12;

/*
 * What the widget shows on the site: its button, and the panel that
 * holds a page of Cairn in a frame the site cannot read. Both live in a
 * closed shadow tree, out of reach of the styles of the site and of its
 * own. The panel is a card that opens from the button, wherever the
 * button was moved to: above or below it on the top and bottom edges,
 * beside it on the left and right ones, as tall as the window allows; on
 * a narrow window it takes the whole of it. The button stays and closes
 * it. The panel has one size: it holds a form, which gains nothing from
 * another. The frame is only created the first time the panel opens. Whether
 * the panel is open is kept in the sessionStorage of the site, so that it
 * stays open from page to page in this tab.
 *
 * The frame and the script talk by messages, each checking where the
 * other's come from: the script hands over what it noted (`report()`),
 * again whenever it notes more (the function returned, which waits for
 * a burst to end), closes the panel when asked to, takes the whole widget out of the
 * picture while the frame takes a screenshot, and makes the panel a dialog over the
 * page while the screenshot is edited.
 */
export function createPanel({ cairn, address, label, report }) {
    const host = document.createElement('div');
    host.setAttribute('data-cairn-widget', '');
    const root = host.attachShadow({ mode: 'closed' });

    const style = document.createElement('style');
    style.textContent = `
:host { all: initial; }
${buttonStyles}
.panel { position: fixed; left: var(--left); top: var(--top); z-index: 2147483647; width: var(--width); height: var(--height); overflow: hidden; border-radius: 14px; background: #fff; box-shadow: 0 0 0 1px rgba(17, 24, 39, .1), 0 2px 6px rgba(17, 24, 39, .08), 0 24px 56px -12px rgba(17, 24, 39, .35); transform-origin: var(--origin); opacity: 0; scale: .96; visibility: hidden; transition: opacity .15s, scale .15s, visibility 0s .15s; }
.panel.open { opacity: 1; scale: 1; visibility: visible; transition: opacity .18s, scale .3s cubic-bezier(.2, 1.15, .3, 1); }
.panel.wide { top: 32px; right: 32px; bottom: 32px; left: 32px; width: auto; height: auto; border-radius: 12px; box-shadow: 0 24px 64px rgba(17, 24, 39, .35); }
.backdrop { position: fixed; inset: 0; z-index: 2147483647; background: rgba(17, 24, 39, .55); }
@media (max-width: 640px) { .panel, .panel.wide { inset: 0; width: auto; height: auto; border-radius: 0; } }
iframe { display: block; width: 100%; height: 100%; border: 0; }
[hidden] { display: none !important; }
@media (prefers-reduced-motion: reduce) { *, *::before, *::after { transition-duration: .01ms !important; animation: none !important; } }
`;
    const panel = document.createElement('div');
    panel.className = 'panel';
    // Behind the panel while it is a dialog over the page: the site stays out of reach meanwhile.
    const backdrop = document.createElement('div');
    backdrop.className = 'backdrop';
    backdrop.hidden = true;
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

    // The card against its button, kept in the window: the styles read where it goes.
    const lay = () => {
        const { edge, x, y } = button.position();
        const { width, height } = viewport();
        const reach = MARGIN + SIZE + GAP;
        const wide = Math.min(WIDTH, width - 2 * MARGIN);
        const stacked = 'top' === edge || 'bottom' === edge;
        const tall = Math.min(HEIGHT, height - MARGIN - (stacked ? reach : MARGIN));
        let left;
        let top;
        if (stacked) {
            left = clamp(x - wide / 2, MARGIN, width - MARGIN - wide);
            top = 'top' === edge ? reach : height - reach - tall;
        } else {
            left = 'left' === edge ? reach : width - reach - wide;
            top = clamp(y - tall / 2, MARGIN, height - MARGIN - tall);
        }
        panel.style.setProperty('--left', `${left}px`);
        panel.style.setProperty('--top', `${top}px`);
        panel.style.setProperty('--width', `${wide}px`);
        panel.style.setProperty('--height', `${tall}px`);
        // it opens from the button
        panel.style.setProperty('--origin', `${clamp(x - left, 0, wide)}px ${clamp(y - top, 0, tall)}px`);
    };
    let shown = false;

    const show = (open) => {
        shown = open;
        if (open && null === frame) {
            frame = document.createElement('iframe');
            // asked in the name of this site: Cairn answers no other, and lets no other frame the answer
            frame.src = `${address}?site=${encodeURIComponent(window.location.origin)}`;
            frame.title = label;
            frame.allow = 'display-capture';
            panel.appendChild(frame);
        }
        if (open) {
            lay();
            // drawn closed once, so that the first opening is seen too
            panel.getBoundingClientRect();
        }
        panel.classList.toggle('open', open);
        button.open(open);
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
    const button = createButton(label, { onClick: () => show(!shown), onMove: lay });

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
            case 'cairn:widen':
                panel.classList.add('wide');
                backdrop.hidden = false;
                break;
            case 'cairn:narrow':
                panel.classList.remove('wide');
                backdrop.hidden = true;
                break;
        }
    });

    root.append(style, ...button.nodes, backdrop, panel);
    // Beside the body, not in it: a site that replaces its body to change page (Turbo, htmx) would take the widget away.
    document.documentElement.appendChild(host);
    try {
        if ('1' === window.sessionStorage.getItem(KEY)) {
            show(true);
        }
    } catch { /* no storage: the panel starts closed */ }

    return noted;
}
