import { Controller } from '@hotwired/stimulus';

/*
 * A menu opened by a trigger, closed by a click outside, Escape or a second
 * click. Optional `search` target gets the focus when the menu opens.
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
        this.onKeydown = (event) => {
            if (event.key === 'Escape' && this.isOpen) {
                this.close();
                // so that what holds the menu (a dialog) does not close with it
                event.preventDefault();
            }
        };
    }

    disconnect() {
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
        this.menuTarget.classList.add('hidden');
        this.trigger?.setAttribute('aria-expanded', 'false');
    }

    listen() {
        if (this.listening) {
            return;
        }
        this.listening = true;
        document.addEventListener('click', this.onDocumentClick);
        document.addEventListener('keydown', this.onKeydown);
    }

    unlisten() {
        if (!this.listening) {
            return;
        }
        this.listening = false;
        document.removeEventListener('click', this.onDocumentClick);
        document.removeEventListener('keydown', this.onKeydown);
    }

    get isOpen() {
        return !this.menuTarget.classList.contains('hidden');
    }

    get trigger() {
        return this.element.querySelector('[aria-expanded]');
    }
}
