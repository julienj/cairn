import { Controller } from '@hotwired/stimulus';

/*
 * Keeps where a frame was scrolled sideways when it loads again: the
 * frame stays, its content is replaced, and the area that scrolls would
 * otherwise start again at its left edge.
 *
 * <turbo-frame data-controller="keep-scroll" data-action="turbo:before-frame-render->keep-scroll#keep">
 *   <div data-keep-scroll-target="area" class="overflow-x-auto">
 *
 * The position is given back by the render itself, right after the new
 * content is in place: Turbo paints twice before `turbo:frame-render`,
 * and restoring there would show the area at its left edge first.
 * Only an area there before and after is restored: another view of the
 * frame starts at its left edge.
 */
export default class extends Controller {
    static targets = ['area'];

    keep(event) {
        if (!this.hasAreaTarget) {
            return;
        }
        const left = this.areaTarget.scrollLeft;
        const render = event.detail.render;
        event.detail.render = (currentElement, newElement) => {
            render(currentElement, newElement);
            if (this.hasAreaTarget) {
                this.areaTarget.scrollLeft = left;
            }
        };
    }
}
