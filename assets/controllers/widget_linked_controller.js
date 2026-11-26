import { Controller } from '@hotwired/stimulus';

/*
 * In the window the widget opened to link an account: once it is linked,
 * tells the frame that opened the window (a page of Cairn, hence the
 * same origin: nobody else hears it) and closes the window. The frame
 * asks for its token by itself anyway, so nothing is lost when the two
 * windows are no longer tied. `silent` only offers `leave`, for the
 * button that gives up.
 */
export default class extends Controller {
    static values = { silent: Boolean };

    connect() {
        if (this.silentValue) {
            return;
        }
        try {
            window.opener?.postMessage({ type: 'cairn:linked' }, window.location.origin);
        } catch {
            // the opener is gone or of another origin: the frame asks by itself
        }
        this.closing = setTimeout(() => window.close(), 1200);
    }

    disconnect() {
        clearTimeout(this.closing);
    }

    leave() {
        window.close();
    }
}
