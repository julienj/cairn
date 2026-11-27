import { Controller } from '@hotwired/stimulus';

/*
 * One tooltip for the whole page, in place of the browser's: quick, the
 * same everywhere, and shown to the keyboard as to the pointer. Its bubble
 * is the `template` target (Ui:Tooltip), copied once. It reads
 * what the templates already say: the `title` of an element is taken when
 * the pointer or the focus reaches it (and put aside as `data-tip`, so
 * that the browser does not show its own as well), so no template changes
 * and what a live component redraws is read again the same way.
 * `data-kbd="⌘ B"` adds the keys of a shortcut after the text.
 *
 * The first tooltip waits a moment; the next ones, while the pointer goes
 * from one to another, do not.
 *
 * <div data-controller="tooltip"><template data-tooltip-target="template">…</template></div>
 */
const DELAY = 450;
const WARM = 400;

export default class extends Controller {
    static targets = ['template'];

    connect() {
        this.over = (event) => this.enter(event.target, true);
        this.focus = (event) => this.enter(event.target, false);
        this.leave = () => this.hide();
        document.addEventListener('pointerover', this.over);
        document.addEventListener('focusin', this.focus);
        ['focusout', 'pointerdown', 'keydown'].forEach((type) => document.addEventListener(type, this.leave));
        window.addEventListener('scroll', this.leave, true);
    }

    disconnect() {
        this.hide();
        this.bubble?.remove();
        this.bubble = null;
        document.removeEventListener('pointerover', this.over);
        document.removeEventListener('focusin', this.focus);
        ['focusout', 'pointerdown', 'keydown'].forEach((type) => document.removeEventListener(type, this.leave));
        window.removeEventListener('scroll', this.leave, true);
    }

    enter(target, pointer) {
        const owner = target instanceof Element ? target.closest('[title], [data-tip]') : null;
        if (owner === this.owner) {
            return;
        }
        this.hide();
        if (!owner || !this.adopt(owner) || owner.matches('[aria-expanded="true"]')) {
            return;
        }
        if (!pointer && !owner.matches(':focus-visible')) {
            return;
        }
        this.owner = owner;
        this.timer = setTimeout(() => this.show(owner), pointer && Date.now() - (this.shownAt ?? 0) > WARM ? DELAY : 0);
    }

    /*
     * Takes the `title` of an element. It is not shown when it only repeats
     * what is written there or beside it (the name next to an avatar),
     * unless that text is cut: a title is then how it is read whole. An
     * element that had no other name keeps this one for a screen reader.
     */
    adopt(owner) {
        const title = owner.getAttribute('title');
        if (title !== null) {
            owner.removeAttribute('title');
            if (owner.textContent.trim() === '' && !owner.hasAttribute('aria-label') && title.trim() !== '') {
                owner.setAttribute('aria-label', title);
            }
            const cut = [owner, ...owner.querySelectorAll('*')].some((element) => element.scrollWidth > element.clientWidth);
            const beside = (owner.parentElement?.textContent ?? '').replace(owner.textContent, '');
            const repeats = owner.textContent.trim() === title.trim() || beside.includes(title);
            if (title.trim() === '' || (repeats && !cut)) {
                delete owner.dataset.tip;
            } else {
                owner.dataset.tip = title;
            }
        }

        return Boolean(owner.dataset.tip);
    }

    show(owner) {
        if (!owner.isConnected) {
            return;
        }
        this.bubble ??= this.build();
        this.bubble.replaceChildren(document.createTextNode(owner.dataset.tip));
        (owner.dataset.kbd ?? '').split(' ').filter(Boolean).forEach((key) => {
            const kbd = document.createElement('kbd');
            kbd.textContent = key;
            this.bubble.append(kbd);
        });
        this.bubble.hidden = false;
        const box = owner.getBoundingClientRect();
        const size = this.bubble.getBoundingClientRect();
        const above = box.top - size.height - 7;
        this.bubble.style.top = `${above < 6 ? box.bottom + 7 : above}px`;
        this.bubble.style.left = `${Math.min(Math.max(6, box.left + box.width / 2 - size.width / 2), window.innerWidth - size.width - 6)}px`;
        requestAnimationFrame(() => this.bubble?.setAttribute('data-open', ''));
    }

    hide() {
        clearTimeout(this.timer);
        if (this.bubble?.hasAttribute('data-open')) {
            this.shownAt = Date.now();
            this.bubble.removeAttribute('data-open');
            this.bubble.hidden = true;
        }
        this.owner = null;
    }

    build() {
        const bubble = this.templateTarget.content.firstElementChild.cloneNode(true);
        document.body.append(bubble);

        return bubble;
    }
}
