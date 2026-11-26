import { buttonStyles, clamp, createButton, MARGIN, SIZE, viewport } from './button.js';

const KEY = 'cairn-widget-open';
const BEGUN = 'cairn-widget-begun';
/* How long what is noted waits before going to the frame, so that a burst goes as one message, in milliseconds. */
const NOTED_WAIT = 300;
/* The panel beside its button: how wide and how tall it gets, and what it leaves between them, in pixels. */
const WIDTH = 480;
const HEIGHT = 760;
const GAP = 12;
/* The mark of Cairn as the frame draws it in its band. */
const MARK = '<svg width="24" height="24" viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="4" stroke-linejoin="round"><rect x="6" y="43" width="44" height="9" rx="4.5"/><rect x="16" y="29" width="40" height="8" rx="4" transform="rotate(-3 36 33)"/><rect x="12" y="15" width="27" height="8" rx="4" transform="rotate(4 25.5 19)"/></svg>';

/*
 * What the widget shows on the site: its button, and the panel that
 * holds a page of Cairn in a frame the site cannot read. Both live in a
 * closed shadow tree, out of reach of the styles of the site and of its
 * own. The panel is a card that opens from the button, wherever the
 * button was moved to: above or below it on the top and bottom edges,
 * beside it on the left and right ones, as tall as the window allows; on
 * a narrow window it takes the whole of it. The button stays and closes
 * it. The panel has one size: it holds a form, which gains nothing from
 * another. The frame is only asked for once the button is aimed at, and
 * the panel shows the shape of the form until the frame has drawn itself:
 * it is never a blank card. Whether the panel is open is kept in the sessionStorage of the site, so that it
 * stays open from page to page in this tab.
 *
 * The frame and the script talk by messages, each checking where the
 * other's come from: the script hands over what it noted (`report()`),
 * again whenever it notes more (the function returned, which waits for
 * a burst to end), closes the panel when asked to, takes the whole widget out of the
 * picture while the frame takes a screenshot, and makes the panel a dialog over the
 * page while the screenshot is edited. It also tells the frame whether
 * the panel covers the button (`cairn:layout`), which otherwise closes it,
 * and to take the keyboard when the panel was opened by hand (`cairn:focus`).
 * Escape closes the panel, from the button as from the frame. The frame
 * says when an issue is begun (`cairn:draft`): the button carries a dot.
 */
export function createPanel({ cairn, address, label, report }) {
    const host = document.createElement('div');
    host.setAttribute('data-cairn-widget', '');
    const root = host.attachShadow({ mode: 'closed' });

    const style = document.createElement('style');
    style.textContent = `
:host { all: initial; }
${buttonStyles}
.panel { position: fixed; left: var(--left); top: var(--top); z-index: 2147483647; width: var(--width); height: var(--height); overflow: hidden; border-radius: 14px; background: #fff; color-scheme: light dark; box-shadow: 0 0 0 1px rgba(17, 24, 39, .1), 0 2px 6px rgba(17, 24, 39, .08), 0 24px 56px -12px rgba(17, 24, 39, .35); transform-origin: var(--origin); opacity: 0; scale: .96; visibility: hidden; transition: opacity .15s, scale .15s, visibility 0s .15s; }
.panel.open { opacity: 1; scale: 1; visibility: visible; transition: opacity .18s, scale .3s cubic-bezier(.2, 1.15, .3, 1); }
.panel.wide { top: 32px; right: 32px; bottom: 32px; left: 32px; width: auto; height: auto; border-radius: 12px; box-shadow: 0 24px 64px rgba(17, 24, 39, .35); }
.panel { --line: #e5e7eb; --sunken: #f3f4f6; --brand: #4f46e5; }
.waiting { position: absolute; inset: 0; z-index: 1; display: flex; flex-direction: column; background: inherit; border-radius: inherit; pointer-events: none; transition: opacity .2s; }
.waiting.gone { opacity: 0; visibility: hidden; transition: opacity .2s, visibility 0s .2s; }
.waiting header { flex: none; box-sizing: border-box; display: flex; align-items: center; gap: 8px; height: 48px; padding-left: 16px; border-bottom: 1px solid var(--line); color: var(--brand); }
.waiting div { display: grid; gap: 16px; padding: 16px; }
.waiting i { display: block; border-radius: 6px; background: var(--sunken); animation: cairn-wait 2s cubic-bezier(.4, 0, .6, 1) infinite; }
.waiting header i { width: 120px; height: 14px; }
.waiting .name { width: 80px; height: 12px; margin: 4px 0; border-radius: 4px; }
.waiting .field { height: 36px; margin-top: 4px; }
@keyframes cairn-wait { 50% { opacity: .5; } }
.backdrop { position: fixed; inset: 0; z-index: 2147483647; background: rgba(17, 24, 39, .55); }
@media (max-width: 640px) { .panel, .panel.wide { inset: 0; width: auto; height: auto; border-radius: 0; } }
@media (prefers-color-scheme: dark) { .panel { --line: #2d343e; --sunken: #262d37; --brand: #a5b4fc; background: #161b22; box-shadow: 0 0 0 1px rgba(255, 255, 255, .12), 0 24px 56px -12px rgba(0, 0, 0, .6); } }
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
    // What the panel shows until its frame has drawn itself: the shape of what comes, never a blank card.
    const waiting = document.createElement('div');
    waiting.className = 'waiting';
    waiting.setAttribute('aria-hidden', 'true');
    waiting.innerHTML = `<header>${MARK}<i></i></header><div>${['', '', 'height: 152px', 'width: 224px'].map((shape) => `<span><i class="name"></i><i class="field" style="${shape}"></i></span>`).join('')}</div>`;
    panel.appendChild(waiting);

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
    // Whether the panel covers its button (a narrow window): the frame then offers its own way to close.
    const covering = window.matchMedia?.('(max-width: 640px)');
    const told = () => send({ type: 'cairn:layout', covered: true === covering?.matches });

    // The frame, asked for as soon as the button is aimed at: it is on its way when the panel opens.
    const ask = () => {
        if (null !== frame) {
            return;
        }
        lay();
        frame = document.createElement('iframe');
        // asked in the name of this site: Cairn answers no other, and lets no other frame the answer
        frame.src = `${address}?site=${encodeURIComponent(window.location.origin)}`;
        frame.title = label;
        frame.allow = 'display-capture';
        panel.appendChild(frame);
    };

    // Opened by hand, the panel takes the keyboard: its frame is told to put it where the writing starts. A panel
    // found open from the page before takes nothing from the site.
    let handed = false;
    const hand = () => {
        if (handed && null !== frame) {
            frame.focus({ preventScroll: true });
            send({ type: 'cairn:focus' });
        }
    };

    const show = (open, byHand = false) => {
        shown = open;
        handed = open && byHand;
        if (open) {
            ask();
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
            told();
            hand();
        }
    };
    const button = createButton(label, {
        onClick: () => show(!shown, true),
        onMove: () => { lay(); told(); },
        onAim: ask,
        onEscape: () => shown && show(false),
    });

    window.addEventListener('message', (event) => {
        if (event.origin !== cairn || null === frame || event.source !== frame.contentWindow) {
            return;
        }
        switch (event.data?.type) {
            case 'cairn:ready':
                waiting.classList.add('gone');
                hand();
                send({ type: 'cairn:context', report: report() });
                told();
                break;
            case 'cairn:close':
                // closed from the keyboard or from the frame: the keyboard goes back to the button
                show(false);
                button.focus();
                break;
            case 'cairn:draft':
                // The frame says that an issue is begun, and nothing of it. Kept for the next page of this tab, where
                // the button shows it before the frame is there.
                button.begun(true === event.data.begun);
                try {
                    if (true === event.data.begun) {
                        window.sessionStorage.setItem(BEGUN, '1');
                    } else {
                        window.sessionStorage.removeItem(BEGUN);
                    }
                } catch { /* no storage: the dot comes back with the frame */ }
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
                // the dialog has its own ways out: the button would only show through at its corner
                dock.style.visibility = 'hidden';
                break;
            case 'cairn:narrow':
                panel.classList.remove('wide');
                backdrop.hidden = true;
                dock.style.visibility = '';
                break;
        }
    });

    const dock = button.nodes[1];
    root.append(style, ...button.nodes, backdrop, panel);
    // Beside the body, not in it: a site that replaces its body to change page (Turbo, htmx) would take the widget away.
    document.documentElement.appendChild(host);
    try {
        button.begun('1' === window.sessionStorage.getItem(BEGUN));
        if ('1' === window.sessionStorage.getItem(KEY)) {
            show(true);
        }
    } catch { /* no storage: the panel starts closed */ }

    return noted;
}
