import { Controller } from '@hotwired/stimulus';

/*
 * Keeps what is being written from being lost by a click (Ui:DraftGuard,
 * once on a page where something is written). A draft is what was typed
 * in a named field of an element marked `data-draft`, as long as it
 * differs from what the field held, or a file waiting there
 * (`data-draft-pending`): a text posted, given up or put back as it was
 * is no draft any more, with nothing to tell this controller.
 *
 * A link followed while there is one waits for an answer in a dialog,
 * cloned from the template. Only links are held: a visit the page makes
 * itself (the redirect that follows a save) is no mistake, and a link a
 * Turbo frame takes for itself replaces no page. Closing the tab, or a
 * link Turbo leaves to the browser, gets the browser's own warning, the
 * only one it allows there; going back in the history gets none.
 */
const WRITTEN = 'textarea, input:not([type=checkbox], [type=radio], [type=file], [type=hidden])';

export default class extends Controller {
    static targets = ['template', 'dialog', 'stay'];

    initialize() {
        this.warn = this.warn.bind(this);
    }

    connect() {
        this.fields = new Map();
        this.leaving = false;
        window.addEventListener('beforeunload', this.warn);
    }

    disconnect() {
        window.removeEventListener('beforeunload', this.warn);
    }

    /* The first time a field is typed in, what it held is remembered: the server wrote it as its default. */
    note({ target }) {
        if (!(target instanceof Element) || !target.matches(WRITTEN) || this.fields.has(target)) {
            return;
        }
        // a field without a name is a search, not a text
        if ((!target.name && !target.dataset.model) || !target.closest('[data-draft]')) {
            return;
        }
        this.fields.set(target, target.defaultValue.trim());
    }

    get unsaved() {
        if (this.leaving) {
            return false;
        }
        for (const [field, initial] of this.fields) {
            if (!field.isConnected) {
                this.fields.delete(field);
            } else if (field.value.trim() !== initial) {
                return true;
            }
        }

        return null !== document.querySelector('[data-draft] [data-draft-pending]');
    }

    ask(event) {
        if (event.defaultPrevented || this.hasDialogTarget || !this.hasTemplateTarget || !this.unsaved) {
            return;
        }
        const url = new URL(event.detail.url, window.location.href);
        if ('' !== url.hash && url.pathname === window.location.pathname && url.search === window.location.search) {
            return;
        }
        // cancelling `turbo:click` alone hands the link back to the browser
        event.preventDefault();
        event.detail.originalEvent?.preventDefault();

        this.destination = url.href;
        this.focused = document.activeElement;
        this.element.append(this.templateTarget.content.cloneNode(true));
        this.stayTarget.focus();
    }

    stay(event) {
        // a browser picking an autofill suggestion sends a keydown without a key, which the `esc` filter lets through
        if (!this.hasDialogTarget || ('keydown' === event?.type && 'Escape' !== event.key)) {
            return;
        }
        this.dialogTarget.remove();
        this.focused?.focus?.();
    }

    leave() {
        this.dialogTarget.remove();
        this.leaving = true;
        window.Turbo.visit(this.destination);
    }

    warn(event) {
        if (this.unsaved) {
            event.preventDefault();
        }
    }
}
