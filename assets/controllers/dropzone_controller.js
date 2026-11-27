import { Controller } from '@hotwired/stimulus';

/*
 * Files dropped on the element go to the file input it holds, as if they
 * had been chosen there: nothing else knows they were dragged. Without a
 * file input (the viewer may not attach anything) nothing shows and the
 * browser keeps its own answer to a drop.
 *
 * <section data-controller="dropzone" data-action="dragenter->dropzone#enter dragover->dropzone#over dragleave->dropzone#leave drop->dropzone#drop">
 *     … <input type="file"> …
 *     <div data-dropzone-target="overlay">Drop here</div>
 * </section>
 */
export default class extends Controller {
    static targets = ['overlay'];

    depth = 0;

    get input() {
        return this.element.querySelector('input[type="file"]');
    }

    carriesFiles(event) {
        return this.input !== null && [...(event.dataTransfer?.types ?? [])].includes('Files');
    }

    enter(event) {
        if (!this.carriesFiles(event)) {
            return;
        }
        event.preventDefault();
        this.depth += 1;
        this.overlayTarget.toggleAttribute('data-dropping', true);
    }

    over(event) {
        if (this.carriesFiles(event)) {
            event.preventDefault();
        }
    }

    leave() {
        this.depth = Math.max(0, this.depth - 1);
        if (this.depth === 0) {
            this.overlayTarget.toggleAttribute('data-dropping', false);
        }
    }

    drop(event) {
        if (!this.carriesFiles(event)) {
            return;
        }
        event.preventDefault();
        this.depth = 0;
        this.overlayTarget.toggleAttribute('data-dropping', false);
        const input = this.input;
        input.files = event.dataTransfer.files;
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }
}
