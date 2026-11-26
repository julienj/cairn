import { Controller } from '@hotwired/stimulus';

const CONTEXT_WAIT = 1500;
/* The window that links the account says so itself: asking is what is left when the two windows are no longer tied. */
const POLL_EVERY = 5000;
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
 * `widget:context` (what the site noted, the token, and where the form
 * keeps its draft), `widget:collapse` and
 * `widget:expand` (the panel out of the way while a screenshot is taken),
 * `widget:widen` and `widget:narrow` (the panel a dialog over the page
 * while the screenshot is edited), `widget:draft` (whether an issue is
 * begun).
 */
export default class extends Controller {
    static targets = ['connect', 'alone', 'refused', 'frame', 'link', 'close', 'kept', 'waiting', 'loading'];

    static values = {
        project: String,
        site: String,
        tokenUrl: String,
        unlinkUrl: String,
        reportUrl: String,
        connectUrl: String,
    };

    connect() {
        this.linked = false;
        this.report = null;
        this.pair = null;
        this.memory = null;
        if (window.parent === window) {
            this.loadingTarget.hidden = true;
            this.aloneTarget.hidden = false;

            return;
        }
        // Says it is there; the site answers with what it noted, which names it.
        window.parent.postMessage({ type: 'cairn:ready' }, '*');
        this.waiting = setTimeout(() => { this.aloneTarget.hidden = this.linked; this.loadingTarget.hidden = !this.linked; }, CONTEXT_WAIT);
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
        } else if ('cairn:focus' === event.data?.type) {
            this.focus();
        } else if ('cairn:layout' === event.data?.type) {
            // the button of the site closes the panel, unless the panel covers it
            this.closeTarget.hidden = true !== event.data.covered;
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
            this.forget();
        } else if (403 === status || 404 === status) {
            event.preventDefault();
            this.frameTarget.hidden = true;
            this.refusedTarget.hidden = false;
        }
    }

    /* The account is taken back from this browser: Cairn deletes the token, which the frame forgets whatever Cairn answers. */
    async unlink() {
        const token = this.token;
        this.forget();
        if (null !== token) {
            try {
                await fetch(this.unlinkUrlValue, { method: 'POST', headers: { Authorization: `Bearer ${token}` } });
            } catch { /* Cairn cannot be reached: the token is gone from here, and ends with its time there */ }
        }
    }

    /* A token Cairn no longer takes is only forgotten. */
    forget() {
        this.token = null;
        this.show();
    }

    show() {
        const linked = null !== this.token;
        this.loadingTarget.hidden = true;
        this.refusedTarget.hidden = true;
        this.connectTarget.hidden = linked;
        this.frameTarget.hidden = !linked;
        if (linked) {
            clearTimeout(this.polling);
            if (0 === this.frameTarget.childElementCount) {
                // the shape of the form while it is on its way, which the form replaces
                this.frameTarget.innerHTML = this.loadingTarget.innerHTML;
            }
            this.frameTarget.src = this.reportUrlValue;
        } else {
            this.frameTarget.removeAttribute('src');
            this.frameTarget.innerHTML = '';
            this.wait(false);
            this.prepare();
        }
    }

    // --- linking an account

    /* The secret and its challenge are asked before the click: the window must open in the click itself. */
    async prepare() {
        this.linkTarget.disabled = true;
        this.pair = await this.post({});
        this.linkTarget.disabled = null === this.pair;
        if (this.awaited) {
            this.focus();
        }
    }

    link() {
        if (null === this.pair) {
            return;
        }
        const url = new URL(this.connectUrlValue, window.location.origin);
        url.searchParams.set('site', this.siteValue);
        url.searchParams.set('challenge', this.pair.challenge);
        window.open(url, 'cairn-widget-link', 'popup,width=480,height=680');
        this.wait(true);
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
        } else {
            this.wait(false);
        }
    }

    /* While the window of Cairn is awaited, the frame says where the sign-in goes on. */
    wait(waiting) {
        this.waitingTarget.hidden = !waiting;
        this.keptTarget.hidden = waiting;
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
        event.detail.draft = `cairn-widget-draft:${this.projectValue}:${this.siteValue}`;
    }

    collapse(event) {
        if (!this.linked) {
            event.detail.done();

            return;
        }
        const timeout = setTimeout(() => { this.collapsed = null; event.detail.done(); }, 1000);
        this.collapsed = () => { clearTimeout(timeout); event.detail.done(); };
        this.tell('cairn:collapse');
    }

    expand() {
        this.tell('cairn:expand');
    }

    widen() {
        this.tell('cairn:widen');
    }

    narrow() {
        this.tell('cairn:narrow');
    }

    /* That an issue is begun, and nothing of it: the button of the site shows it. */
    drafted(event) {
        this.tell('cairn:draft', { begun: true === event.detail.begun });
    }

    /* A message to the script of the site, once the site said which it is: to that site and no other. */
    tell(type, said = {}) {
        if (this.linked) {
            window.parent.postMessage({ type, ...said }, this.siteValue);
        }
    }

    /* A file dropped where no form takes it is not opened in place of the frame. */
    refuse(event) {
        if (Array.from(event.dataTransfer?.types ?? []).includes('Files')) {
            event.preventDefault();
        }
    }

    // --- the keyboard

    /*
     * Where the writing starts: the title of the form, else what the frame
     * offers to do (signing in, reporting another problem). Asked while
     * nothing is there to take it, it is given once the form has come.
     */
    focus() {
        const target = this.connectTarget.hidden
            ? this.frameTarget.querySelector('input[name$="[title]"]') ?? this.frameTarget.querySelector('a[href], button:not([hidden], [disabled])')
            : this.linkTarget;
        this.awaited = null === target || target.disabled;
        if (!this.awaited) {
            target.focus({ preventScroll: true });
        }
    }

    /* What the frame just drew takes the keyboard when it was awaited, or when what held it was replaced. */
    loaded() {
        if (this.awaited || (document.hasFocus() && document.activeElement === document.body)) {
            this.focus();
        }
    }

    /*
     * Escape closes the panel, unless something of the form is open over
     * it and takes the key for itself. Asked before the others act on the
     * key (capture): afterwards what was open no longer is. What opens
     * over the form says so itself (`data-widget-over`).
     */
    escape() {
        if (null === this.element.querySelector('[data-widget-over]:not([hidden])')) {
            this.close();
        }
    }

    close() {
        this.tell('cairn:close');
    }
}
