import { Controller } from '@hotwired/stimulus';

/*
 * A form field chosen in a Ui:Picker: picking an item writes its value
 * into the hidden field and its label on the button that opens the list,
 * or the line itself when it is marked `data-picker-line` (a person).
 * A field that may stay empty offers an item without a value.
 *
 * <div data-controller="picker">
 *   <input type="hidden" name="project" data-picker-target="input">
 *   … <span data-picker-target="label">Choose…</span> …
 *   <button data-action="picker#choose dropdown#close" data-picker-value-param="12" data-picker-label-param="MD-SPT · Shop">…</button>
 * </div>
 */
export default class extends Controller {
    static targets = ['input', 'label', 'chosen'];

    /*
     * A live component that redraws keeps what a script added beside what
     * the server now draws: the line of the server replaces our copy.
     */
    chosenTargetConnected() {
        this.labelTarget.querySelectorAll('[data-picker-copy]').forEach((copy) => copy.remove());
    }

    choose(event) {
        const { value, label } = event.params;
        this.inputTarget.value = value;
        this.inputTarget.dispatchEvent(new Event('change', { bubbles: true }));
        // a line that is more than a label (a person with their avatar) is copied as it is drawn
        const line = event.currentTarget.querySelector('[data-picker-line]');
        const text = document.createElement('span');
        text.className = 'truncate';
        text.textContent = label;
        const copy = line ? line.cloneNode(true) : text;
        copy.dataset.pickerCopy = '';
        this.labelTarget.replaceChildren(copy);
        // an empty value is the choice of nothing: the button reads as its placeholder again
        const button = this.labelTarget.closest('button');
        button?.classList.toggle('text-faint', '' === value);
        button?.classList.toggle('text-body', '' !== value);
    }
}
