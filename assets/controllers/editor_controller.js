/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { Editor, Extension } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import { Markdown } from '@tiptap/markdown';
import Mention from '@tiptap/extension-mention';
import Image from '@tiptap/extension-image';
import { Placeholder } from '@tiptap/extensions';

/*
 * Rich editor over a markdown textarea (Ui:Form:Editor). The textarea,
 * hidden, stays the value: forms submit it and live components bind it
 * with data-model; every change writes the markdown back and fires
 * `input`. `#` suggests issues (`#MD-SPT-12`), `@` people
 * (`@[Claire Martin](user:12)`), pasted or dropped images are uploaded.
 * Mod+Enter and Escape are dispatched as `editor:submit` and
 * `editor:cancel` for the page to act on; `reset` empties it when the
 * page asks (a comment was posted). Lazy: TipTap and ProseMirror
 * are only fetched on a page that has an editor.
 */

/*
 * Only the attachments of this instance are drawn as images, as on the
 * page that shows the text (MarkdownRenderer): a remote image would tell
 * its host who opens the editor. Another address is drawn as its text;
 * the markdown written back is left as it was.
 */
const ATTACHMENT_URL = /^\/attachments\/[0-9a-f-]{36}\/[A-Za-z0-9._-]+$/i;

const AttachmentImage = Image.extend({
    renderHTML({ HTMLAttributes }) {
        if (ATTACHMENT_URL.test(HTMLAttributes.src ?? '')) {
            return this.parent({ HTMLAttributes });
        }

        return ['span', { class: 'text-muted' }, String(HTMLAttributes.src ?? '')];
    },
});

const COMMANDS = {
    bold: (chain) => chain.toggleBold(),
    italic: (chain) => chain.toggleItalic(),
    strike: (chain) => chain.toggleStrike(),
    code: (chain) => chain.toggleCode(),
    bulletList: (chain) => chain.toggleBulletList(),
    orderedList: (chain) => chain.toggleOrderedList(),
    blockquote: (chain) => chain.toggleBlockquote(),
};

/*
 * A reference written in the text: an atomic node with `id` and `label`,
 * shown as `text`, read from and written to markdown by `pattern` and
 * `markdown`. The same patterns are parsed on the server (Markdown/): a
 * reference glued to a word (`abc#MD-SPT-1`) is text there, so here too.
 * The suggestion menu is configured per editor.
 */
const reference = ({ name, cssClass, pattern, attrs, markdown, text }) => {
    const anchored = new RegExp(`^(?:${pattern.source})`);

    return Mention.extend({
        name,
        markdownTokenName: name,
        markdownTokenizer: {
            name,
            level: 'inline',
            start: (src) => src.search(pattern),
            tokenize: (src, tokens) => {
                if (/\w$/.test(tokens.at(-1)?.raw ?? '')) {
                    return undefined;
                }
                const match = anchored.exec(src);
                return match ? { type: name, raw: match[0], attrs: attrs(match) } : undefined;
            },
        },
        parseMarkdown: (token, helpers) => helpers.createNode(name, token.attrs),
        renderMarkdown: (node) => markdown(node.attrs),
    }).configure({
        HTMLAttributes: { class: cssClass },
        renderText: ({ node }) => text(node.attrs),
        renderHTML: ({ options, node }) => ['span', options.HTMLAttributes, text(node.attrs)],
    });
};

const IssueReference = reference({
    name: 'issueReference',
    cssClass: 'issue-reference',
    pattern: /#([A-Z0-9]+-[A-Z0-9]+-\d+)\b/,
    attrs: (match) => ({ id: match[1], label: match[1] }),
    markdown: ({ id }) => `#${id}`,
    text: ({ id }) => `#${id}`,
});

const UserMention = reference({
    name: 'userMention',
    cssClass: 'mention',
    pattern: /@\[([^\]\n]+)\]\(user:(\d+)\)/,
    attrs: (match) => ({ id: match[2], label: match[1] }),
    markdown: ({ id, label }) => `@[${String(label).replace(/[[\]]/g, '')}](user:${id})`,
    text: ({ label }) => `@${label}`,
});

export default class extends Controller {
    static targets = ['input', 'content', 'button'];
    static values = {
        issuesUrl: String,
        usersUrl: String,
        uploadUrl: String,
        placeholder: String,
        linkPrompt: String,
        uploadFailed: String,
        imagesOnly: String,
        autofocus: Boolean,
        editable: { type: Boolean, default: true },
    };

    connect() {
        this.suggesting = false;
        // A restored page may still hold the previous editor's DOM.
        this.contentTarget.replaceChildren();

        this.editor = new Editor({
            element: this.contentTarget,
            content: this.inputTarget.value,
            contentType: 'markdown',
            autofocus: this.autofocusValue ? 'end' : false,
            editable: this.editableValue,
            extensions: [
                StarterKit.configure({ underline: false, heading: { levels: [2, 3] }, link: { openOnClick: false } }),
                Markdown,
                AttachmentImage,
                Placeholder.configure({ placeholder: this.placeholderValue }),
                IssueReference.configure({ suggestion: this.suggestion('#', this.issuesUrlValue, (issue) => ({ id: issue.key, label: issue.key, hint: issue.title })) }),
                UserMention.configure({ suggestion: this.suggestion('@', this.usersUrlValue, (user) => ({ id: String(user.id), label: user.name })) }),
                Extension.create({
                    name: 'pageKeys',
                    addKeyboardShortcuts: () => ({
                        'Mod-Enter': () => {
                            this.dispatch('submit');
                            return true;
                        },
                        Escape: () => {
                            // While a suggestion menu is open, Escape closes it instead.
                            if (this.suggesting) {
                                return false;
                            }
                            this.dispatch('cancel');
                            return true;
                        },
                    }),
                }),
            ],
            editorProps: {
                attributes: { class: 'prose prose-sm max-w-none min-h-[inherit] focus:outline-none' },
                handlePaste: (view, event) => this.uploadAll(event.clipboardData?.files),
                handleDrop: (view, event, slice, moved) => !moved && this.uploadAll(event.dataTransfer?.files),
            },
            onUpdate: () => this.write(),
            onTransaction: () => this.refreshButtons(),
        });
    }

    disconnect() {
        this.editor?.destroy();
        this.popup?.remove();
    }

    run({ params: { command } }) {
        if ('link' === command) {
            this.toggleLink();
            return;
        }
        COMMANDS[command]?.(this.editor.chain().focus()).run();
    }

    toggleLink() {
        const chain = this.editor.chain().focus().extendMarkRange('link');
        if (this.editor.isActive('link')) {
            chain.unsetLink().run();
            return;
        }
        const href = window.prompt(this.linkPromptValue, 'https://');
        if (href && 'https://' !== href) {
            chain.setLink({ href }).run();
        }
    }

    reset() {
        this.editor.commands.clearContent();
        this.write();
    }

    write() {
        this.inputTarget.value = this.editor.getMarkdown();
        this.inputTarget.dispatchEvent(new Event('input', { bubbles: true }));
    }

    refreshButtons() {
        for (const button of this.buttonTargets) {
            button.setAttribute('aria-pressed', String(this.editor.isActive(button.dataset.editorCommandParam)));
        }
    }

    /*
     * Images go to the server one by one, then into the text at the cursor.
     * Any file is taken over, so that the browser never opens a dropped one.
     */
    uploadAll(files) {
        const all = Array.from(files ?? []);
        if (all.length > 0 && '' === this.uploadUrlValue) {
            this.toast(this.uploadFailedValue);

            return true;
        }
        const images = all.filter((file) => file.type.startsWith('image/'));
        if (all.length > 0 && 0 === images.length) {
            this.toast(this.imagesOnlyValue);
        }
        images.forEach((file) => this.upload(file));

        return all.length > 0;
    }

    toast(message) {
        window.dispatchEvent(new CustomEvent('toast:show', { detail: { message } }));
    }

    async upload(file) {
        const body = new FormData();
        body.append('file', file);
        try {
            const response = await fetch(this.uploadUrlValue, { method: 'POST', body, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            // A failure the controller did not decide itself (a proxy, a crash) comes as HTML.
            const result = response.headers.get('Content-Type')?.includes('application/json') ? await response.json() : {};
            if (!response.ok) {
                throw new Error(result.error ?? this.uploadFailedValue);
            }
            this.editor.chain().focus().setImage({ src: result.url, alt: result.name }).run();
        } catch (error) {
            this.toast(error.message || this.uploadFailedValue);
        }
    }

    /* A suggestion fed by a JSON endpoint, drawn as a small menu under the caret. */
    suggestion(char, url, toItem) {
        let request = null;
        const items = async ({ query }) => {
            if ('' === url) {
                return [];
            }
            request?.abort();
            request = new AbortController();
            try {
                const response = await fetch(`${url}?q=${encodeURIComponent(query)}`, { signal: request.signal, headers: { Accept: 'application/json' } });
                return (await response.json()).map(toItem);
            } catch {
                return [];
            }
        };

        const render = () => {
            let state = null;
            let selected = 0;
            const choose = (index) => {
                const item = state.items[index];
                if (item) {
                    state.command({ id: item.id, label: item.label });
                }
            };
            const draw = (props) => {
                state = props;
                selected = Math.min(selected, Math.max(0, state.items.length - 1));
                this.popup.replaceChildren(...state.items.map((item, index) => this.option(item, index === selected, () => choose(index))));
                this.popup.hidden = 0 === state.items.length;
                this.editor.view.dom.setAttribute('aria-activedescendant', this.popup.hidden ? '' : this.popup.children[selected].id);
                const rect = state.clientRect?.();
                if (rect) {
                    Object.assign(this.popup.style, { top: `${rect.bottom + 6}px`, left: `${rect.left}px` });
                }
            };

            return {
                onStart: (props) => {
                    this.suggesting = true;
                    selected = 0;
                    this.popup ??= Object.assign(document.createElement('div'), {
                        role: 'listbox',
                        className: 'fixed z-50 w-80 max-w-[calc(100vw-2rem)] rounded-md border border-line bg-overlay p-1 shadow-lg',
                    });
                    document.body.append(this.popup);
                    draw(props);
                },
                onUpdate: draw,
                onKeyDown: ({ event }) => {
                    if (0 === state.items.length) {
                        return false;
                    }
                    if ('ArrowDown' === event.key || 'ArrowUp' === event.key) {
                        selected = (selected + ('ArrowDown' === event.key ? 1 : -1) + state.items.length) % state.items.length;
                        draw(state);
                        return true;
                    }
                    if ('Enter' === event.key || 'Tab' === event.key) {
                        choose(selected);
                        return true;
                    }
                    return 'Escape' === event.key;
                },
                onExit: () => {
                    this.suggesting = false;
                    this.popup?.remove();
                    this.editor.view.dom.removeAttribute('aria-activedescendant');
                },
            };
        };

        return { char, items, render };
    }

    option({ id, label, hint }, selected, choose) {
        const option = document.createElement('button');
        option.type = 'button';
        option.role = 'option';
        option.id = `${this.element.id || 'editor'}-option-${id}`;
        option.setAttribute('aria-selected', String(selected));
        option.className = `flex w-full items-baseline gap-2 rounded px-2 py-1.5 text-left text-sm ${selected ? 'bg-brand-soft text-brand' : 'text-body hover:bg-subtle'}`;
        option.addEventListener('mousedown', (event) => { event.preventDefault(); choose(); });
        option.append(Object.assign(document.createElement('span'), { className: 'shrink-0 font-medium', textContent: label }));
        if (hint) {
            option.append(Object.assign(document.createElement('span'), { className: 'truncate text-muted', textContent: hint }));
        }

        return option;
    }
}
