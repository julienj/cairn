import { Controller } from '@hotwired/stimulus';

/*
 * Opens a picture over the page instead of leaving it, in a copy of the
 * figure the page holds once (Ui:Lightbox): a click on what calls `open`,
 * closed by a click anywhere or Escape. Without that figure the link is
 * followed as any other. A click meant for a
 * new tab (a modifier key, the middle button) is left to the link.
 *
 * <span data-controller="lightbox" data-lightbox-src-value="/file.png" data-lightbox-caption-value="file.png">
 *     <a href="/file.png" data-action="click->lightbox#open">file.png</a>
 * </span>
 */
export default class extends Controller {
    static values = { src: String, caption: String };

    disconnect() {
        this.close();
    }

    open(event) {
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) {
            return;
        }
        const template = document.querySelector('template[data-lightbox-template]');
        if (!template) {
            return;
        }
        event.preventDefault();
        const figure = template.content.firstElementChild.cloneNode(true);
        const image = figure.querySelector('img');
        image.src = this.srcValue;
        image.alt = this.captionValue;
        figure.querySelector('[data-lightbox-caption]').textContent = this.captionValue;
        document.body.append(figure);
        this.figure = figure;
        this.onKey = (keyEvent) => {
            if (keyEvent.key === 'Escape') {
                keyEvent.preventDefault();
                this.close();
            }
        };
        figure.addEventListener('click', () => this.close());
        document.addEventListener('keydown', this.onKey);
        requestAnimationFrame(() => figure.setAttribute('data-open', ''));
    }

    close() {
        if (!this.figure) {
            return;
        }
        const figure = this.figure;
        this.figure = null;
        document.removeEventListener('keydown', this.onKey);
        figure.removeAttribute('data-open');
        setTimeout(() => figure.remove(), 200);
    }
}
