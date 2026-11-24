import { Controller } from '@hotwired/stimulus';

const CONTEXT_WAIT = 1500;
const POLL_EVERY = 1500;
const POLL_FOR = 5 * 60 * 1000;

/*
 * The frame of the widget, inside the panel a site shows. It was asked
 * for one site, the only one it listens to, which tells it what it noted
 * of the visit (`cairn:context`), keeps the widget token of this browser for this site
 * (the storage of a framed page is the frame's own, the site cannot read
 * it), signs with it every request made under /widget/, and shows the
 * invitation to link an account while it has none.
 *
 * Linking opens a window of Cairn with the challenge of a secret only
 * this frame holds, then asks for the token until the account is linked:
 * nothing depends on the two windows staying tied, which some identity
 * providers cut.
 *
 * What the form needs of the parent goes through events on the window:
 * `widget:context` (what the site noted), `widget:collapse` and
 * `widget:expand` (the panel out of the way while a screenshot is taken).
 */
export default class extends Controller {
    static targets = ['connect', 'alone', 'refused', 'frame', 'link'];

    static values = {
        project: String,
        site: String,
        tokenUrl: String,
        reportUrl: String,
        connectUrl: String,
    };

    connect() {
        this.linked = false;
        this.report = null;
        this.pair = null;
        this.memory = null;
        if (window.parent === window) {
            this.aloneTarget.hidden = false;

            return;
        }
        // Says it is there; the site answers with what it noted, which names it.
        window.parent.postMessage({ type: 'cairn:ready' }, '*');
        this.waiting = setTimeout(() => { this.aloneTarget.hidden = this.linked; }, CONTEXT_WAIT);
    }

    disconnect() {
        clearTimeout(this.waiting);
        clearTimeout(this.polling);
    }

    received(event) {
        if (event.origin === window.location.origin && 'cairn:linked' === event.data?.type) {
            this.ask();

            return;
        }
        if (event.source !== window.parent || event.origin !== this.siteValue) {
            return;
        }
        if ('cairn:context' === event.data?.type) {
            const first = !this.linked;
            this.linked = true;
            this.report = event.data.report ?? null;
            if (first) {
                clearTimeout(this.waiting);
                this.aloneTarget.hidden = true;
                this.show();
            }
            window.dispatchEvent(new CustomEvent('widget:context-changed', { detail: { report: this.report } }));
        } else if ('cairn:collapsed' === event.data?.type) {
            this.collapsed?.();
            this.collapsed = null;
        }
    }

    // --- the token

    get storageKey() {
        return `cairn-widget:${this.projectValue}:${this.siteValue}`;
    }

    get token() {
        try {
            return window.localStorage.getItem(this.storageKey) ?? this.memory;
        } catch {
            return this.memory;
        }
    }

    set token(value) {
        this.memory = value;
        try {
            if (null === value) {
                window.localStorage.removeItem(this.storageKey);
            } else {
                window.localStorage.setItem(this.storageKey, value);
            }
        } catch {
            // no storage in this frame: the token lasts as long as the page
        }
    }

    sign(event) {
        const token = this.token;
        if (null !== token && event.detail.url.pathname.startsWith('/widget/')) {
            event.detail.fetchOptions.headers.Authorization = `Bearer ${token}`;
        }
    }

    /*
     * A token the widget no longer knows goes back to linking an account;
     * a form refused to the account that is linked (403, or 404 when it
     * has no category there) says so, instead of what Turbo writes in a
     * frame it could not fill.
     */
    answered(event) {
        const status = event.detail.fetchResponse.response.status;
        if (401 === status) {
            event.preventDefault();
            this.unlink();
        } else if (403 === status || 404 === status) {
            event.preventDefault();
            this.frameTarget.hidden = true;
            this.refusedTarget.hidden = false;
        }
    }

    unlink() {
        this.token = null;
        this.show();
    }

    show() {
        const linked = null !== this.token;
        this.refusedTarget.hidden = true;
        this.connectTarget.hidden = linked;
        this.frameTarget.hidden = !linked;
        if (linked) {
            clearTimeout(this.polling);
            this.frameTarget.src = this.reportUrlValue;
        } else {
            this.frameTarget.removeAttribute('src');
            this.frameTarget.innerHTML = '';
            this.prepare();
        }
    }

    // --- linking an account

    /* The secret and its challenge are asked before the click: the window must open in the click itself. */
    async prepare() {
        this.linkTarget.disabled = true;
        this.pair = await this.post({});
        this.linkTarget.disabled = null === this.pair;
    }

    link() {
        if (null === this.pair) {
            return;
        }
        const url = new URL(this.connectUrlValue, window.location.origin);
        url.searchParams.set('site', this.siteValue);
        url.searchParams.set('challenge', this.pair.challenge);
        this.popup = window.open(url, 'cairn-widget-link', 'popup,width=480,height=680');
        this.deadline = Date.now() + POLL_FOR;
        clearTimeout(this.polling);
        this.polling = setTimeout(() => this.ask(), POLL_EVERY);
    }

    async ask() {
        clearTimeout(this.polling);
        if (null === this.pair || null !== this.token) {
            return;
        }
        const answer = await this.post({ verifier: this.pair.verifier });
        if (answer?.token) {
            this.pair = null;
            this.token = answer.token;
            this.show();

            return;
        }
        if (Date.now() < this.deadline) {
            this.polling = setTimeout(() => this.ask(), POLL_EVERY);
        }
    }

    async post(body) {
        try {
            const response = await fetch(this.tokenUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ site: this.siteValue, ...body }),
            });

            return 200 === response.status ? await response.json() : null;
        } catch {
            return null;
        }
    }

    // --- what the form asks of the site

    /* Not `context`: that name is Stimulus's own on a controller. */
    provide(event) {
        event.detail.report = this.report;
        event.detail.token = this.token;
    }

    collapse(event) {
        if (!this.linked) {
            event.detail.done();

            return;
        }
        const timeout = setTimeout(() => { this.collapsed = null; event.detail.done(); }, 1000);
        this.collapsed = () => { clearTimeout(timeout); event.detail.done(); };
        window.parent.postMessage({ type: 'cairn:collapse' }, this.siteValue);
    }

    expand() {
        if (this.linked) {
            window.parent.postMessage({ type: 'cairn:expand' }, this.siteValue);
        }
    }

    close() {
        if (this.linked) {
            window.parent.postMessage({ type: 'cairn:close' }, this.siteValue);
        }
    }
}
