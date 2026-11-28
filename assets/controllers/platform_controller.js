import { Controller } from '@hotwired/stimulus';

/*
 * Tells the page which keyboard it is read on, once, on <html>: a hint
 * names the key that is under the fingers (the `mac` variant of app.css,
 * used by Ui:ModKey: ⌘ on a Mac, Ctrl everywhere else).
 *
 * <body data-controller="platform">
 */
export default class extends Controller {
    connect() {
        const platform = navigator.userAgentData?.platform ?? navigator.platform ?? '';
        document.documentElement.toggleAttribute('data-mac', /mac|iphone|ipad/i.test(platform));
    }
}
