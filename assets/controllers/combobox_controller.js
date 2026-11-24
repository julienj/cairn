import { Controller } from '@hotwired/stimulus';

/*
 * A text field with suggestions: the list opens on focus and narrows as
 * the user types; arrows move, Enter or a click fills the field with the
 * suggestion, Escape or a click outside closes. What is typed and
 * matches nothing stays: a suggestion is a shortcut, not a constraint.
 * The document is listened to only while the list is open.
 *
 * <div data-controller="combobox">
 *   <input data-combobox-target="input" data-action="input->combobox#filter focus->combobox#open keydown->combobox#navigate">
 *   <ul data-combobox-target="list" hidden>
 *     <li><button data-combobox-target="option" data-action="combobox#choose">Maison Dupont</button></li>
 *   </ul>
 * </div>
 */
export default class extends Controller {
    static targets = ['input', 'list', 'option'];

    connect() {
        this.active = -1;
        this.onDocumentClick = (event) => {
            if (!this.element.contains(event.target)) {
                this.close();
            }
        };
    }

    disconnect() {
        document.removeEventListener('click', this.onDocumentClick);
    }

    open() {
        this.filter();
    }

    filter() {
        const text = this.inputTarget.value.trim().toLowerCase();
        let visible = 0;
        for (const option of this.optionTargets) {
            const matches = option.textContent.trim().toLowerCase().includes(text);
            option.parentElement.hidden = !matches;
            option.removeAttribute('aria-selected');
            visible += matches ? 1 : 0;
        }
        this.active = -1;
        visible > 0 ? this.show() : this.close();
    }

    navigate(event) {
        if (event.key === 'Escape') {
            this.close();

            return;
        }
        if (!['ArrowDown', 'ArrowUp', 'Enter'].includes(event.key)) {
            return;
        }
        const options = this.visibleOptions;
        if (event.key === 'Enter') {
            if (this.isOpen && this.active >= 0 && options[this.active]) {
                event.preventDefault();
                this.pick(options[this.active]);
            }

            return;
        }
        if (options.length === 0) {
            return;
        }
        event.preventDefault();
        if (!this.isOpen) {
            this.show();
        }
        this.active = event.key === 'ArrowDown' ? Math.min(this.active + 1, options.length - 1) : Math.max(this.active - 1, 0);
        options.forEach((option, index) => {
            if (index === this.active) {
                option.setAttribute('aria-selected', 'true');
                option.scrollIntoView({ block: 'nearest' });
            } else {
                option.removeAttribute('aria-selected');
            }
        });
    }

    choose(event) {
        this.pick(event.currentTarget);
    }

    pick(option) {
        this.inputTarget.value = option.textContent.trim();
        this.inputTarget.dispatchEvent(new Event('input', { bubbles: true }));
        this.inputTarget.dispatchEvent(new Event('change', { bubbles: true }));
        this.close();
        this.inputTarget.focus();
    }

    show() {
        this.listTarget.hidden = false;
        this.inputTarget.setAttribute('aria-expanded', 'true');
        document.addEventListener('click', this.onDocumentClick);
    }

    close() {
        document.removeEventListener('click', this.onDocumentClick);
        this.listTarget.hidden = true;
        this.inputTarget.setAttribute('aria-expanded', 'false');
        this.active = -1;
    }

    get isOpen() {
        return !this.listTarget.hidden;
    }

    get visibleOptions() {
        return this.optionTargets.filter((option) => !option.parentElement.hidden);
    }
}
