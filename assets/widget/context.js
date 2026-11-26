import { cut, describe } from './text.js';

const MAX_ENTRIES = 30;
const MAX_NAME = 60;
const MAX_DEPTH = 3;

/*
 * What the site says of itself, as it chooses: its version, its
 * environment, who is signed in… Its pages push objects into
 * `window.Cairn`, before the widget is loaded or after, as many times as
 * they want:
 *
 *   (window.Cairn ||= []).push({ version: '2026.11.3', user: { id: 3, email: 'claire@example.com' } });
 *
 * They are merged in order each time a report is made, into names and
 * texts: an object inside is laid out under dotted names (`user.id`,
 * `user.email`) three levels deep, then told by its first level as
 * console.error is, like a list; a date is written as a moment, a null
 * as `null`, a function is left out. A later name replaces an earlier
 * one with all that was laid out under it, and `undefined` takes it
 * away. `noted` is called
 * after each push. Returns the function that gives the context, name =>
 * text.
 */
export function watchContext(noted) {
    if (undefined === window.Cairn) {
        window.Cairn = [];
    }
    const layer = window.Cairn;
    if (Array.isArray(layer)) {
        const push = layer.push;
        layer.push = function (...items) {
            const length = push.apply(this, items);
            noted();

            return length;
        };
    }

    return () => {
        const context = new Map();
        try {
            for (const item of Array.isArray(window.Cairn) ? window.Cairn : []) {
                if (null === item || 'object' !== typeof item || Array.isArray(item)) {
                    continue;
                }
                for (const [key, value] of Object.entries(item)) {
                    merge(context, key, value, 1);
                }
            }
        } catch { /* reading must never break the page */ }

        return Object.fromEntries([...context].slice(0, MAX_ENTRIES).map(([name, text]) => [name.slice(0, MAX_NAME), text]));
    };
}

function merge(context, name, value, depth) {
    if ('function' === typeof value || 'symbol' === typeof value) {
        return;
    }
    // what was laid out under the name goes, whatever replaces it
    for (const laid of [...context.keys()]) {
        if (laid.startsWith(`${name}.`)) {
            context.delete(laid);
        }
    }
    if (undefined === value) {
        context.delete(name);

        return;
    }
    if (value instanceof Date) {
        context.set(name, Number.isNaN(value.getTime()) ? String(value) : value.toISOString());

        return;
    }
    if (null !== value && 'object' === typeof value && !Array.isArray(value) && depth < MAX_DEPTH) {
        context.delete(name);
        for (const [key, inner] of Object.entries(value)) {
            merge(context, `${name}.${key}`, inner, depth + 1);
        }

        return;
    }
    context.set(name, cut(null !== value && 'object' === typeof value ? describe(value) : value));
}
