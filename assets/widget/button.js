const CORNERS = ['bottom-right', 'bottom-left', 'top-right', 'top-left'];
const KEY = 'cairn-widget-corner';
/* How far the pointer goes before a press is a move and no longer a click, in pixels. */
const SLACK = 6;

/*
 * The button of the widget, which whoever reviews the site moves out of
 * the way: dragged, it follows the pointer, and dropped it goes to the
 * nearest corner of the window. The site remembers the corner
 * (localStorage: for this site, in this browser), so the button is there
 * again on the next page. A press that does not move is a click.
 */
export function createButton(label, onClick) {
    const button = document.createElement('button');
    button.type = 'button';
    button.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><path d="M12 7v4"/><path d="M12 14h.01"/></svg>';
    button.appendChild(document.createTextNode(label));
    button.dataset.corner = remembered();

    let press = null;
    let moved = false;

    button.addEventListener('pointerdown', (event) => {
        if (0 !== event.button) {
            return;
        }
        const box = button.getBoundingClientRect();
        press = { x: event.clientX, y: event.clientY, dx: event.clientX - box.left, dy: event.clientY - box.top };
        moved = false;
        button.setPointerCapture(event.pointerId);
    });
    button.addEventListener('pointermove', (event) => {
        if (null === press) {
            return;
        }
        if (!moved && Math.hypot(event.clientX - press.x, event.clientY - press.y) < SLACK) {
            return;
        }
        moved = true;
        button.dataset.dragged = '';
        const box = button.getBoundingClientRect();
        button.style.left = `${Math.max(0, Math.min(window.innerWidth - box.width, event.clientX - press.dx))}px`;
        button.style.top = `${Math.max(0, Math.min(window.innerHeight - box.height, event.clientY - press.dy))}px`;
    });
    const drop = () => {
        if (null === press) {
            return;
        }
        press = null;
        if (!moved) {
            return;
        }
        const box = button.getBoundingClientRect();
        const corner = `${box.top + box.height / 2 < window.innerHeight / 2 ? 'top' : 'bottom'}-${box.left + box.width / 2 < window.innerWidth / 2 ? 'left' : 'right'}`;
        delete button.dataset.dragged;
        button.style.left = '';
        button.style.top = '';
        button.dataset.corner = corner;
        try {
            window.localStorage.setItem(KEY, corner);
        } catch { /* no storage: the corner lasts as long as the page */ }
    };
    button.addEventListener('pointerup', drop);
    button.addEventListener('pointercancel', drop);
    button.addEventListener('click', (event) => {
        if (moved) {
            // the click that ends a move opens nothing
            moved = false;
            event.preventDefault();

            return;
        }
        onClick();
    });

    return button;
}

function remembered() {
    try {
        const corner = window.localStorage.getItem(KEY);

        return CORNERS.includes(corner) ? corner : CORNERS[0];
    } catch {
        return CORNERS[0];
    }
}

export const buttonStyles = `
button { position: fixed; z-index: 2147483646; height: 48px; padding: 0 20px 0 16px; display: inline-flex; align-items: center; gap: 10px; border: 0; border-radius: 24px; background: #4f46e5; color: #fff; font: 600 14px/1 system-ui, sans-serif; box-shadow: 0 8px 24px rgba(17, 24, 39, .25); cursor: pointer; touch-action: none; user-select: none; -webkit-user-select: none; transition: background-color .15s; }
button:hover { background: #4338ca; }
button:focus-visible { outline: 3px solid #a5b4fc; outline-offset: 2px; }
button[data-corner="bottom-right"] { right: 24px; bottom: 24px; }
button[data-corner="bottom-left"] { left: 24px; bottom: 24px; }
button[data-corner="top-right"] { right: 24px; top: 24px; }
button[data-corner="top-left"] { left: 24px; top: 24px; }
button[data-dragged] { right: auto; bottom: auto; cursor: grabbing; box-shadow: 0 12px 32px rgba(17, 24, 39, .35); }
`;
