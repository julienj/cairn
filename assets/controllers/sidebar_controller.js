import { Controller } from '@hotwired/stimulus';

const COOKIE = 'cairn_sidebar';
const YEAR = 60 * 60 * 24 * 365;

/*
 * Mobile navigation: the sidebar slides in, a backdrop covers the page.
 * Both are targets of a controller placed on the page shell.
 *
 * On a wide screen, `toggle` folds the project navigation away or brings it
 * back: the state goes on <html> (`data-sidebar`, which the
 * `sidebar-collapsed` variant reads) and into a cookie, from which the
 * server renders the next pages the same way (SidebarExtension).
 */
export default class extends Controller {
    static targets = ['panel', 'backdrop', 'toggle'];

    open() {
        this.panelTarget.classList.remove('-translate-x-full');
        this.backdropTarget.classList.remove('hidden');
    }

    close() {
        this.panelTarget.classList.add('-translate-x-full');
        this.backdropTarget.classList.add('hidden');
    }

    toggleTargetConnected(button) {
        // A page restored from Turbo's cache may carry the state of before.
        this.#label(button, 'collapsed' === document.documentElement.dataset.sidebar);
    }

    toggle() {
        const root = document.documentElement;
        const collapsed = 'collapsed' !== root.dataset.sidebar;

        if (collapsed) {
            root.dataset.sidebar = 'collapsed';
            const secure = 'https:' === window.location.protocol ? '; Secure' : '';
            document.cookie = `${COOKIE}=collapsed; path=/; max-age=${YEAR}; SameSite=Lax${secure}`;
        } else {
            delete root.dataset.sidebar;
            document.cookie = `${COOKIE}=; path=/; max-age=0; SameSite=Lax`;
        }
        this.toggleTargets.forEach((button) => this.#label(button, collapsed));
    }

    #label(button, collapsed) {
        const label = collapsed ? button.dataset.expandLabel : button.dataset.collapseLabel;
        button.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        button.setAttribute('aria-label', label);
        button.setAttribute('title', label);
    }
}
