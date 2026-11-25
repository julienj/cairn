/*
 * What the reporter asked of their system, as the page sees it through its
 * media queries: many problems of display only show with one of them. Read
 * when the report is handed over, so it says what holds then.
 */
export function preferences() {
    if (!window.matchMedia) {
        return null;
    }
    const holds = (query) => window.matchMedia(query).matches;

    return {
        colorScheme: holds('(prefers-color-scheme: dark)') ? 'dark' : 'light',
        reducedMotion: holds('(prefers-reduced-motion: reduce)'),
        contrast: holds('(prefers-contrast: more)') ? 'more' : (holds('(prefers-contrast: less)') ? 'less' : null),
        forcedColors: holds('(forced-colors: active)'),
        pointer: holds('(pointer: coarse)') ? 'coarse' : (holds('(pointer: fine)') ? 'fine' : (holds('(pointer: none)') ? 'none' : null)),
    };
}

/*
 * How much the page is zoomed by pinching (a phone, a trackpad), 1 when
 * it is not. The zoom of the browser itself (Ctrl +) is not told to a
 * page: it shows in its pixel ratio.
 */
export function zoom() {
    const scale = window.visualViewport?.scale;

    return 'number' === typeof scale && scale > 0 ? Math.round(scale * 100) / 100 : null;
}
