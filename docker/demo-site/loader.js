/*
 * Loads the widget of Cairn the way a site being reviewed would, with one
 * difference: a site writes the address of its Cairn in a <script> tag of
 * its <head>, this one works it out, so that the same pages serve
 * http://staging.localhost:8001 (Cairn on localhost) and the address of
 * the machine on the network (Cairn on the same address), where nothing
 * is HTTPS and the screenshot is therefore not offered. The port of Cairn
 * is the stack's to say (`/cairn.js`, written by compose.yaml).
 *
 * What the page says on the tag of this loader (data-display) goes on the
 * tag of the widget: the pages of the site have it for everybody, as a
 * site being reviewed does, and live.html only on demand, as production.
 *
 * The tag is written into the page as it is read, so that the widget is
 * there before the scripts of the page run, as it is on a real site: what
 * happens before it loads is not in its report.
 */
(function () {
    var display = document.currentScript.getAttribute('data-display');
    var local = 'localhost' === window.location.hostname || /\.localhost$/.test(window.location.hostname);
    var cairn = 'http://' + (local ? 'localhost' : window.location.hostname) + ':' + (window.cairnPort || 8000);
    document.write('<script src="' + cairn + '/widget.js" data-project="MD-SPT" data-version="2026.11.3-rc2"' + (display ? ' data-display="' + display + '"' : '') + '><\/script>');
})();
