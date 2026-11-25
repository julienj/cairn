import { Controller } from '@hotwired/stimulus';

/*
 * A menu opened by a trigger, closed by a click outside, the focus leaving
 * it, Escape or a second click. Optional `search` target gets the focus
 * when the menu opens.
 *
 * The keyboard: Down or Up on the trigger opens the menu on its first or
 * last line; inside, they go from line to line (links, buttons, fields),
 * Home and End to either end, Enter in the search field takes the first
 * line it left, Escape closes. A menu closed from inside gives the focus
 * back to its trigger. A
 * radio keeps its arrows, which choose within its group, and a text field
 * its Home and End.
 *
 * The document is listened to only while the menu is open.
 *
 * <div data-controller="dropdown">
 *   <button data-action="dropdown#toggle" aria-expanded="false">…</button>
 *   <div data-dropdown-target="menu" class="hidden">…</div>
 * </div>
 */
export default class extends Controller {
    static targets = ['menu', 'search'];

    connect() {
        this.onDocumentClick = (event) => {
            if (!this.element.contains(event.target)) {
                this.close();
            }
        };
        this.onDocumentFocus = (event) => {
            if (!this.element.contains(event.target)) {
                this.close();
            }
        };
        this.onDocumentKeydown = (event) => {
            if (event.key !== 'Escape') {
                return;
            }
            this.close();
            // so that what holds the menu (a dialog) does not close with it
            event.preventDefault();
        };
        this.onKeydown = (event) => this.navigate(event);
        this.element.addEventListener('keydown', this.onKeydown);
        // a page restored from the cache of Turbo may come back with its menu open
        if (this.isOpen) {
            this.listen();
        }
    }

    disconnect() {
        this.element.removeEventListener('keydown', this.onKeydown);
        this.unlisten();
    }

    toggle() {
        this.isOpen ? this.close() : this.open();
    }

    open() {
        this.menuTarget.classList.remove('hidden');
        this.trigger?.setAttribute('aria-expanded', 'true');
        this.listen();
        if (this.hasSearchTarget) {
            this.searchTarget.value = '';
            this.searchTarget.dispatchEvent(new Event('input', { bubbles: true }));
            this.searchTarget.focus();
        }
    }

    close() {
        // a live redraw may have hidden the menu while it was listened to
        this.unlisten();
        if (!this.isOpen) {
            return;
        }
        // a line taken, or Escape: the focus goes back to the trigger rather than to a hidden line
        const focused = this.menuTarget.contains(document.activeElement);
        this.menuTarget.classList.add('hidden');
        this.trigger?.setAttribute('aria-expanded', 'false');
        if (focused) {
            this.trigger?.focus();
        }
    }

    navigate(event) {
        if (event.altKey || event.ctrlKey || event.metaKey || !['ArrowDown', 'ArrowUp', 'Home', 'End', 'Enter'].includes(event.key)) {
            return;
        }
        const target = event.target;
        if (target === this.trigger) {
            if ('ArrowDown' === event.key || 'ArrowUp' === event.key) {
                event.preventDefault();
                if (!this.isOpen) {
                    this.open();
                }
                const lines = this.lines;
                ('ArrowDown' === event.key ? lines[0] : lines[lines.length - 1])?.focus();
            }

            return;
        }
        if (!this.isOpen || !this.menuTarget.contains(target)) {
            return;
        }
        if ('Enter' === event.key) {
            if (this.hasSearchTarget && target === this.searchTarget) {
                // in a form, Enter in the search would submit it: it takes the first line instead
                event.preventDefault();
                this.lines.find((line) => line !== target && !isField(line))?.click();
            }

            return;
        }
        if ('radio' === target.type || target.matches('textarea, select') || (isField(target) && ('Home' === event.key || 'End' === event.key))) {
            return;
        }
        const lines = this.lines;
        if (0 === lines.length) {
            return;
        }
        event.preventDefault();
        const index = lines.indexOf(target);
        const next = {
            ArrowDown: index + 1 < lines.length ? index + 1 : 0,
            ArrowUp: index > 0 ? index - 1 : lines.length - 1,
            Home: 0,
            End: lines.length - 1,
        }[event.key];
        lines[next].focus();
    }

    listen() {
        if (this.listening) {
            return;
        }
        this.listening = true;
        document.addEventListener('click', this.onDocumentClick);
        document.addEventListener('focusin', this.onDocumentFocus);
        document.addEventListener('keydown', this.onDocumentKeydown);
    }

    unlisten() {
        if (!this.listening) {
            return;
        }
        this.listening = false;
        document.removeEventListener('click', this.onDocumentClick);
        document.removeEventListener('focusin', this.onDocumentFocus);
        document.removeEventListener('keydown', this.onDocumentKeydown);
    }

    get isOpen() {
        return !this.menuTarget.classList.contains('hidden');
    }

    get trigger() {
        return [...this.element.querySelectorAll('[aria-expanded]')].find((element) => !this.menuTarget.contains(element));
    }

    /** What the arrows reach, in the order shown: what Tab would reach, a group of radios once (its checked one). */
    get lines() {
        const candidates = [...this.menuTarget.querySelectorAll('a[href], button, input, select, textarea, [tabindex]')].filter(
            (element) => !element.disabled && element.tabIndex >= 0 && 'hidden' !== element.type && element.getClientRects().length > 0,
        );

        return candidates.filter((element) => {
            if ('radio' !== element.type || element.checked) {
                return true;
            }
            const group = candidates.filter((other) => 'radio' === other.type && other.name === element.name);

            return !group.some((other) => other.checked) && group[0] === element;
        });
    }
}

function isField(element) {
    return element.matches('input:not([type="checkbox"]):not([type="radio"]), textarea, select');
}
