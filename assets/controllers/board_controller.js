/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';
import Sortable from 'sortablejs';

/*
 * The cards of a kanban column, dragged to another column or to another
 * rank of their own with SortableJS:
 *
 * <section data-board-column> <span data-board-count>3</span>
 *   <ul data-controller="board" data-board-place-value="in_progress" data-board-rank-value="true">
 *     <li data-board-key="MD-SPT-12" data-board-places="to_test done new">
 *
 * A card goes only to the places its `data-board-places` lists, computed
 * by the server for the viewer. While it is dragged, the columns say which
 * they are (`data-board-state`: allowed or denied), and the one under
 * the pointer says it would take it (`data-board-over`). Dropped, it stays where it was put
 * and Board:Move applies the move, at once or after a dialog. The
 * `board:moved` browser event it sends back settles it here, without
 * reading any column again: applied, the card takes the places it may now
 * go to and the two columns count again; refused or cancelled, it goes
 * back where it came from. Until then the card cannot be dragged again
 * (`board-pending`): one move is settled at a time. A column that loaded
 * again in the meantime was drawn by the server as it had the issue then,
 * so both ends are looked up by their place and by what they hold now.
 *
 * The order of a column is kept by the server. Where the viewer may rank
 * (`data-board-rank-value`), a card is also dragged within its column and
 * Board:Move is told the two cards it was dropped between, as it is for a
 * card dropped on another column. A card moved but not ranked (`top` in
 * `board:moved`: the viewer may not) goes on top of its column, where
 * every issue arrives.
 */

/** Where the card being dragged came from: its place and the card that followed it. */
const origins = new WeakMap();

/** The moves waiting for their `board:moved`, by issue key: the card, where it came from and the place it was dropped on. */
const pending = new Map();

const listOf = (place) => document.querySelector(`[data-controller~="board"][data-board-place-value="${CSS.escape(place)}"]`);

export default class extends Controller {
    static values = { place: String, rank: Boolean };

    connect() {
        this.settle = this.settle.bind(this);
        window.addEventListener('board:moved', this.settle);
        this.sortable = Sortable.create(this.element, {
            group: 'board',
            sort: this.rankValue,
            draggable: 'li[data-board-key]',
            filter: '.board-locked, .board-pending',
            preventOnFilter: false,
            animation: 150,
            // the place the card would land at, drawn by the classes of its list item (Board:Column)
            ghostClass: 'board-ghost',
            onStart: (event) => {
                origins.set(event.item, { place: this.placeValue, next: event.item.nextElementSibling });
                this.mark(event.item);
            },
            onEnd: () => {
                this.mark(null);
                this.over(null);
            },
            onMove: (event) => {
                const allowed = event.to === event.from || this.places(event.dragged).includes(event.to.dataset.boardPlaceValue);
                this.over(allowed && event.to !== event.from ? event.to : null);

                return allowed;
            },
            onAdd: (event) => this.dropped(event.item, 'move', { place: this.placeValue }),
            onUpdate: (event) => this.dropped(event.item, 'rank'),
        });
    }

    disconnect() {
        window.removeEventListener('board:moved', this.settle);
        this.sortable?.destroy();
    }

    places(card) {
        return (card.dataset.boardPlaces || '').split(' ').filter(Boolean);
    }

    /** Tells each column whether the card being dragged may go there; null clears it. */
    mark(card) {
        const places = card ? this.places(card) : [];
        document.querySelectorAll('[data-controller~="board"]').forEach((list) => {
            const place = list.dataset.boardPlaceValue;
            if (null === card || list === this.element) {
                delete list.dataset.boardState;
            } else {
                list.dataset.boardState = places.includes(place) ? 'allowed' : 'denied';
            }
        });
    }

    /** Marks the list the card would be dropped on; null clears it. */
    over(list) {
        document.querySelectorAll('[data-board-over]').forEach((other) => {
            if (other !== list) {
                delete other.dataset.boardOver;
            }
        });
        if (list) {
            list.dataset.boardOver = '';
        }
    }

    /**
     * A card was dropped in this column, from another one (`move`) or from
     * another rank (`rank`): it waits there while Board:Move settles it,
     * told the cards it now stands between.
     */
    async dropped(card, action, args = {}) {
        const key = card.dataset.boardKey;
        card.classList.add('board-pending');
        pending.set(key, { card, origin: origins.get(card), to: this.placeValue });
        const above = card.previousElementSibling?.dataset.boardKey;
        const below = card.nextElementSibling?.dataset.boardKey;
        const mover = document.querySelector('[data-board-move]');
        if (!mover) {
            return;
        }
        const component = await getComponent(mover);
        component.action(action, { key, ...args, ...(above ? { above } : {}), ...(below ? { below } : {}) });
    }

    /**
     * The move of a card ended. Every column hears it; the first one settles
     * it for the board.
     */
    settle(event) {
        const { key, applied, top, places } = event.detail || {};
        const move = pending.get(key);
        if (!move?.origin) {
            return;
        }
        pending.delete(key);
        const { card, origin, to } = move;
        card.classList.remove('board-pending');
        // A column that loaded again since the drop has its cards drawn anew: the origin then shows
        // the issue again, as another element, and the place it was dropped on has lost the card.
        const from = listOf(origin.place);
        const target = listOf(to);
        const redrawn = [...(from?.children || [])].find((item) => item !== card && item.dataset.boardKey === key);
        if (!applied) {
            if (redrawn || !from) {
                card.remove();
            } else {
                from.insertBefore(card, origin.next?.parentElement === from ? origin.next : null);
            }

            return;
        }
        redrawn?.remove();
        if (!card.isConnected || top) {
            target?.prepend(card);
        }
        card.dataset.boardPlaces = (places || []).join(' ');
        const locked = 0 === (places || []).length && !this.rankValue;
        card.classList.toggle('board-locked', locked);
        card.classList.toggle('cursor-grab', !locked);
        if (from === target) {
            return;
        }
        if (from) {
            this.count(from, -1);
        }
        if (target) {
            this.count(target, 1);
        }
    }

    count(list, change) {
        const count = list.closest('[data-board-column]')?.querySelector('[data-board-count]');
        if (count) {
            count.textContent = Math.max(0, (parseInt(count.textContent, 10) || 0) + change);
        }
    }
}
