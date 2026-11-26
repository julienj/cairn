import { Controller } from '@hotwired/stimulus';

/*
 * A list of what happened, read with or without each of its kinds: a
 * button per kind (`data-kind`, pressed while its lines show) hides and
 * shows the lines of that kind (`line` targets, `data-kind`).
 */
export default class extends Controller {
    static targets = ['line'];

    toggle({ currentTarget }) {
        const shown = 'true' !== currentTarget.getAttribute('aria-pressed');
        currentTarget.setAttribute('aria-pressed', String(shown));
        for (const line of this.lineTargets) {
            if (line.dataset.kind === currentTarget.dataset.kind) {
                line.hidden = !shown;
            }
        }
    }
}
