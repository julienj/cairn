import { Controller } from '@hotwired/stimulus';

/*
 * A bar that stays at the top of a long page takes the title of the page
 * once the heading has left the screen under it, and gives it back when
 * the heading returns. What is watched is the block that holds the `h1`
 * (the live component around it, when there is one), which stays while
 * the `h1` itself comes and goes: a title being edited in place is a
 * field, and the one shown is read from the `h1` each time.
 *
 * <nav data-controller="sticky-title">… <span data-sticky-title-target="title"></span></nav>
 */
export default class extends Controller {
    static targets = ['title'];

    connect() {
        const heading = document.querySelector('main h1');
        if (!heading) {
            return;
        }
        this.observer = new IntersectionObserver(([entry]) => this.toggle(!entry.isIntersecting), {
            rootMargin: `-${Math.round(this.element.getBoundingClientRect().bottom)}px 0px 0px 0px`,
        });
        this.observer.observe(heading.closest('[data-controller~="live"]') ?? heading);
    }

    disconnect() {
        this.observer?.disconnect();
    }

    toggle(stuck) {
        const heading = document.querySelector('main h1');
        if (stuck && heading) {
            this.titleTarget.textContent = heading.textContent.trim();
        }
        this.element.toggleAttribute('data-stuck', stuck && heading !== null);
    }
}
