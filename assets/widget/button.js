const KEY = 'cairn-widget-place';
const EDGES = ['top', 'right', 'bottom', 'left'];
/* The button when nobody points at it: a disc of this many pixels. */
export const SIZE = 40;
/* What the button, and the panel beside it, keep between them and the edge of the window, in pixels. */
export const MARGIN = 16;
/* Where the centre of the button runs: this far from the edge. */
const INSET = MARGIN + SIZE / 2;
/* How far the pointer goes before a press is a move and no longer a click, in pixels. */
const SLACK = 6;
/* How close to an anchor, along its edge, a drop is taken by it, in pixels. */
const MAGNET = 56;
/* A button that is thrown lands where it was going: this many milliseconds further at the speed it was let go. */
const THROW = 140;
/* How the label opens: at once but slowly at first, so that a pointer that only passes, or comes to click, hardly sets it off. */
const UNFOLD = '.35s cubic-bezier(.6, 0, .2, 1)';
/* The anchors, shown while the button is moved: the corners and the middle of each edge. */
const ANCHORS = [
    { edge: 'top', at: 0 }, { edge: 'top', at: 0.5 }, { edge: 'top', at: 1 },
    { edge: 'left', at: 0.5 }, { edge: 'right', at: 0.5 },
    { edge: 'bottom', at: 0 }, { edge: 'bottom', at: 0.5 }, { edge: 'bottom', at: 1 },
];
/* What the disc is filled with, and what rings its badge: one colour, so the badge reads as cut in the disc. */
const FILL = '#1c1c21';
const FILL_OVER = '#26262c';
/* The mark of Cairn, its three stones in a square of 64: each place that draws it says how. */
export const STONES = '<rect x="6" y="43" width="44" height="9" rx="4.5"/><rect x="16" y="29" width="40" height="8" rx="4" transform="rotate(-3 36 33)"/><rect x="12" y="15" width="27" height="8" rx="4" transform="rotate(4 25.5 19)"/>';
const LOGO = `<svg class="logo" width="22" height="22" viewBox="0 0 64 64" fill="currentColor" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true">${STONES}</svg>`;
const CLOSE = '<svg class="close" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>';

/*
 * The button of the widget: the mark of Cairn in a small dark disc, which
 * says what it is for when pointed at, and which whoever reviews the site
 * moves out of the way. Dragged, it follows the pointer; dropped (or
 * thrown), it goes to the nearest edge of the window, anywhere along it,
 * and an anchor takes it when it lands close: a corner, or the middle of
 * an edge. The site remembers the place (localStorage: for this site, in
 * this browser), as an edge and how far along it, so the button is there
 * again on the next page and after the window changed size. A press that
 * does not move is a click.
 *
 * The button sits in a dock, a point of the window which is the centre of
 * the disc: the label opens towards the inside of the window from there,
 * so the disc itself never moves under the pointer.
 */
export function createButton(label, { onClick, onMove, onAim, onEscape }) {
    const dock = document.createElement('div');
    dock.className = 'dock';
    const button = document.createElement('button');
    button.type = 'button';
    button.setAttribute('aria-expanded', 'false');
    button.innerHTML = `<span class="mark">${LOGO}${CLOSE}</span><span class="label"><span></span></span>`;
    button.querySelector('.label span').textContent = label;
    // Around the button, which cuts what goes past it: what the badge holds on to, at the top right corner of the
    // button whether it is a disc or open on its label.
    const pill = dock.appendChild(document.createElement('span'));
    pill.className = 'pill';
    const badge = document.createElement('i');
    badge.className = 'begun';
    pill.append(button, badge);

    const hints = document.createElement('div');
    hints.className = 'anchors';
    hints.setAttribute('aria-hidden', 'true');
    const dots = ANCHORS.map((anchor) => ({ anchor, dot: hints.appendChild(document.createElement('i')) }));

    let place = remembered();
    const moveTo = (point) => {
        dock.style.transform = `translate3d(${Math.round(point.x)}px, ${Math.round(point.y)}px, 0)`;
    };
    const settle = () => {
        dock.dataset.side = sideOf(place);
        moveTo(pointOf(place));
    };
    settle();
    window.addEventListener('resize', () => {
        settle();
        onMove();
    });

    let press = null;
    let moved = false;
    let trail = [];

    const dragged = (event) => {
        const { width, height } = viewport();

        return {
            x: clamp(event.clientX - press.dx, SIZE / 2, width - SIZE / 2),
            y: clamp(event.clientY - press.dy, SIZE / 2, height - SIZE / 2),
        };
    };

    button.addEventListener('pointerdown', (event) => {
        if (0 !== event.button) {
            return;
        }
        const centre = pointOf(place);
        // Held by the disc: a press on the label brings the disc under the pointer.
        press = {
            x: event.clientX,
            y: event.clientY,
            dx: clamp(event.clientX - centre.x, -SIZE / 2, SIZE / 2),
            dy: clamp(event.clientY - centre.y, -SIZE / 2, SIZE / 2),
        };
        moved = false;
        trail = [];
        // Pressed, the button is held or clicked: its label folds, and stays so until the pointer has left.
        dock.dataset.held = '';
        button.setPointerCapture(event.pointerId);
    });
    button.addEventListener('pointerleave', () => {
        if (null === press) {
            delete dock.dataset.held;
        }
    });
    button.addEventListener('pointermove', (event) => {
        if (null === press) {
            return;
        }
        if (!moved && Math.hypot(event.clientX - press.x, event.clientY - press.y) < SLACK) {
            return;
        }
        if (!moved) {
            moved = true;
            dock.dataset.dragging = '';
            for (const { anchor, dot } of dots) {
                const point = pointOf(anchor);
                // not `transform`: it would be scaled with the dot
                dot.style.translate = `${point.x}px ${point.y}px`;
            }
            hints.dataset.shown = '';
        }
        const point = dragged(event);
        moveTo(point);
        trail.push({ x: point.x, y: point.y, at: event.timeStamp });
        while (trail.length > 2 && event.timeStamp - trail[0].at > 80) {
            trail.shift();
        }
        const target = placeOf(point);
        for (const { anchor, dot } of dots) {
            dot.toggleAttribute('data-near', anchor.edge === target.edge && anchor.at === target.at);
        }
    });
    const drop = (event) => {
        if (null === press) {
            return;
        }
        const point = moved ? dragged(event) : null;
        press = null;
        if (null === point) {
            return;
        }
        const first = trail[0];
        const last = trail[trail.length - 1];
        const lapse = last && first ? last.at - first.at : 0;
        // let go while still: dropped there; let go while moving: thrown
        const still = !last || event.timeStamp - last.at > 60 || lapse <= 0;
        place = placeOf(still ? point : {
            x: point.x + ((last.x - first.x) / lapse) * THROW,
            y: point.y + ((last.y - first.y) / lapse) * THROW,
        });
        delete dock.dataset.dragging;
        delete hints.dataset.shown;
        settle();
        onMove();
        try {
            window.localStorage.setItem(KEY, `${place.edge}:${place.at}`);
        } catch { /* no storage: the place lasts as long as the page */ }
    };
    button.addEventListener('pointerup', drop);
    button.addEventListener('pointercancel', drop);
    // aimed at, by the pointer or the keyboard, before it is pressed
    button.addEventListener('pointerenter', onAim);
    button.addEventListener('focus', onAim);
    button.addEventListener('keydown', (event) => {
        if ('Escape' === event.key) {
            onEscape();
        }
    });
    button.addEventListener('click', (event) => {
        if (moved) {
            // the click that ends a move opens nothing
            moved = false;
            event.preventDefault();

            return;
        }
        onClick();
    });

    return {
        nodes: [hints, dock],
        /* Where the button stands: its edge, and the centre of its disc in the window. */
        position: () => ({ edge: place.edge, ...pointOf(place) }),
        open: (open) => button.setAttribute('aria-expanded', open ? 'true' : 'false'),
        focus: () => button.focus({ preventScroll: true }),
        /* Out of sight while something of the panel stands over the whole page. */
        hide: (hidden) => { dock.style.visibility = hidden ? 'hidden' : ''; },
        /* An issue is begun in the panel: a dot on the disc says so. */
        begun: (begun) => button.toggleAttribute('data-begun', begun),
    };
}

/* What the page shows of the window: fixed elements are laid out in it, scrollbars apart. */
export function viewport() {
    const root = document.documentElement;

    return { width: root.clientWidth || window.innerWidth, height: root.clientHeight || window.innerHeight };
}

export function clamp(value, min, max) {
    return Math.max(min, Math.min(Math.max(min, max), value));
}

/* The centre of the disc for a place: on its edge, `at` of the way along it. */
function pointOf(place) {
    const { width, height } = viewport();
    const along = (length) => INSET + place.at * Math.max(0, length - 2 * INSET);
    switch (place.edge) {
        case 'top': return { x: along(width), y: INSET };
        case 'bottom': return { x: along(width), y: height - INSET };
        case 'left': return { x: INSET, y: along(height) };
        default: return { x: width - INSET, y: along(height) };
    }
}

/*
 * The place a point of the window is dropped at: the nearest edge, where
 * the point stands along it, or the anchor it is close to. A corner is
 * told as the end of the top or the bottom edge, whichever side it was
 * reached from, so that a corner is one place.
 */
function placeOf(point) {
    const { width, height } = viewport();
    const gaps = { top: point.y, right: width - point.x, bottom: height - point.y, left: point.x };
    let edge = EDGES.reduce((nearest, candidate) => (gaps[candidate] < gaps[nearest] ? candidate : nearest));
    const horizontal = 'top' === edge || 'bottom' === edge;
    const run = Math.max(1, (horizontal ? width : height) - 2 * INSET);
    let at = clamp(((horizontal ? point.x : point.y) - INSET) / run, 0, 1);
    at = [0, 0.5, 1].find((anchor) => Math.abs(at - anchor) * run <= MAGNET) ?? Math.round(at * 1000) / 1000;
    if (!horizontal && (0 === at || 1 === at)) {
        const end = 'right' === edge ? 1 : 0;
        edge = 0 === at ? 'top' : 'bottom';
        at = end;
    }

    return { edge, at };
}

/* Which way the label opens: away from the edge the button is against. */
function sideOf(place) {
    if ('left' === place.edge) {
        return 'start';
    }
    if ('right' === place.edge) {
        return 'end';
    }

    return place.at < 1 / 3 ? 'start' : (place.at > 2 / 3 ? 'end' : 'center');
}

function remembered() {
    try {
        const [edge, at] = (window.localStorage.getItem(KEY) ?? '').split(':');
        const along = Number.parseFloat(at);
        if (EDGES.includes(edge) && along >= 0 && along <= 1) {
            return { edge, at: along };
        }
    } catch { /* no storage: the place of everybody */ }

    return { edge: 'bottom', at: 1 };
}

/*
 * One look for every site, light or dark: a dark disc with a light
 * hairline, as the tools of a browser are drawn, which no palette of a
 * site is mistaken for. It is opaque: what rings its badge is its own
 * colour, whatever the page behind.
 */
export const buttonStyles = `
.dock { position: fixed; left: 0; top: 0; z-index: 2147483646; width: 0; height: 0; transition: transform .5s cubic-bezier(.2, 1.2, .3, 1); }
.dock[data-dragging] { transition: none; }
.pill { position: absolute; top: ${-SIZE / 2}px; display: block; width: max-content; }
button { position: relative; display: flex; align-items: center; height: ${SIZE}px; min-width: ${SIZE}px; margin: 0; padding: 0; overflow: hidden; border: 0; border-radius: ${SIZE / 2}px; background: ${FILL}; color: #fff; font: 500 13px/1 system-ui, -apple-system, "Segoe UI", sans-serif; letter-spacing: -.005em; box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .13), inset 0 1px 0 rgba(255, 255, 255, .1), 0 1px 2px rgba(0, 0, 0, .3), 0 8px 20px -6px rgba(0, 0, 0, .45); cursor: pointer; touch-action: none; user-select: none; -webkit-user-select: none; -webkit-tap-highlight-color: transparent; transition: background-color .2s, box-shadow .2s, scale .2s cubic-bezier(.2, 1.2, .3, 1); animation: cairn-arrive .35s cubic-bezier(.2, 1.2, .3, 1) backwards; }
.dock[data-side="start"] .pill { left: ${-SIZE / 2}px; }
.dock[data-side="end"] .pill { right: ${-SIZE / 2}px; }
.dock[data-side="end"] button { flex-direction: row-reverse; }
.dock[data-side="center"] .pill { left: 0; translate: -50% 0; }
button:hover, button[aria-expanded="true"] { background: ${FILL_OVER}; box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .18), inset 0 1px 0 rgba(255, 255, 255, .12), 0 1px 2px rgba(0, 0, 0, .3), 0 10px 26px -6px rgba(79, 70, 229, .55); }
button:active { scale: .95; }
button:focus-visible { outline: 2px solid #a5b4fc; outline-offset: 2px; }
.dock[data-dragging] button { scale: 1.08; cursor: grabbing; box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .18), 0 2px 4px rgba(0, 0, 0, .3), 0 18px 36px -8px rgba(0, 0, 0, .6); }
.mark { flex: none; display: grid; place-items: center; width: ${SIZE}px; height: ${SIZE}px; }
.mark svg { grid-area: 1 / 1; transition: opacity .2s, rotate .3s cubic-bezier(.2, 1.2, .3, 1), scale .3s cubic-bezier(.2, 1.2, .3, 1); }
.close, button[aria-expanded="true"] .logo { opacity: 0; scale: .5; }
.close { rotate: -90deg; }
button[aria-expanded="true"] .logo { rotate: 90deg; }
button[aria-expanded="true"] .close { opacity: 1; scale: 1; rotate: 0deg; }
.label { display: grid; grid-template-columns: 0fr; transition: grid-template-columns ${UNFOLD}; }
.label span { min-width: 0; overflow: hidden; white-space: nowrap; opacity: 0; transition: opacity ${UNFOLD}; }
.label span::before, .label span::after { content: ""; display: inline-block; width: 2px; }
.dock[data-side="end"] .label span::before, .dock:not([data-side="end"]) .label span::after { width: 16px; }
button[aria-expanded="false"]:focus-visible .label { grid-template-columns: 1fr; }
button[aria-expanded="false"]:focus-visible .label span { opacity: 1; }
@media (hover: hover) {
    .dock:not([data-held]) button[aria-expanded="false"]:hover .label { grid-template-columns: 1fr; }
    .dock:not([data-held]) button[aria-expanded="false"]:hover .label span { opacity: 1; }
}
.anchors { position: fixed; left: 0; top: 0; z-index: 2147483645; width: 0; height: 0; pointer-events: none; opacity: 0; transition: opacity .2s; }
.anchors[data-shown] { opacity: 1; }
.anchors i { position: absolute; left: ${-SIZE / 2}px; top: ${-SIZE / 2}px; width: ${SIZE}px; height: ${SIZE}px; border-radius: 50%; background: rgba(22, 22, 26, .45); box-shadow: 0 0 0 5px rgba(255, 255, 255, .6); scale: .2; transition: scale .25s cubic-bezier(.2, 1.2, .3, 1), background-color .2s, box-shadow .2s; }
.anchors i[data-near] { scale: 1.15; background: rgba(99, 102, 241, .16); box-shadow: 0 0 0 1.5px rgba(99, 102, 241, .85); }
.begun { position: absolute; right: 1px; top: 1px; width: 10px; height: 10px; border-radius: 50%; background: #a5b4fc; box-shadow: 0 0 0 2px ${FILL}; pointer-events: none; scale: 0; transition: scale .25s cubic-bezier(.2, 1.2, .3, 1), box-shadow .2s; }
button:hover + .begun { box-shadow: 0 0 0 2px ${FILL_OVER}; }
button[data-begun][aria-expanded="false"] + .begun { scale: 1; }
@keyframes cairn-arrive { from { opacity: 0; scale: .6; } }
`;
