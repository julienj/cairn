import { Controller } from '@hotwired/stimulus';

/*
 * Copies the `text` value, or else the text of the `source` target, when
 * the button is used, and says so for a moment by showing the `done`
 * target in place of the `idle` one. A `text` value that is an address
 * relative to the page (`#comment-12`) is copied as an absolute one.
 */
export default class extends Controller {
    static targets = ['source', 'idle', 'done'];
    static values = { text: String };

    async copy() {
        await navigator.clipboard.writeText(this.hasTextValue ? new URL(this.textValue, window.location.href).href : this.sourceTarget.textContent.trim());
        this.toggle(true);
        clearTimeout(this.timeout);
        this.timeout = setTimeout(() => this.toggle(false), 2000);
    }

    disconnect() {
        clearTimeout(this.timeout);
    }

    toggle(done) {
        this.idleTargets.forEach((element) => { element.hidden = done; });
        this.doneTargets.forEach((element) => { element.hidden = !done; });
    }
}
