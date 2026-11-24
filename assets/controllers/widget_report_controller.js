import { Controller } from '@hotwired/stimulus';

/* The pictures of the files sent, by uuid: they outlive the form, which is drawn again when the category changes. */
const previews = new Map();
/* The screenshot among them: taking another one replaces it. */
let capture = null;
/* The files on their way: the form is not sent before they are in. */
let sending = 0;
/* The form on the page: another one each time the category changes. */
let current = null;
/* The files that are in and that no form holds yet: the one on the page takes them, or the next one while it is drawn again. */
const arrived = [];

/*
 * The form of the widget. It asks the frame for what the site noted of
 * the visit and carries it in a hidden field (`widget:context`), sends
 * the files and the screenshot as soon as they are there, each signed
 * with the widget token and kept by the form as a hidden field, and has
 * the report drawn by the server as it would be kept, in a second view
 * of the form. Changing the category sends the form back to be drawn
 * again, creating nothing, through a button of its own (`refresher`):
 * nothing of it stays in the form when the request fails. A file takes
 * its time to be sent: the form cannot be sent meanwhile, and a file
 * that arrives while the form is drawn again goes to the next one.
 */
export default class extends Controller {
    static targets = ['main', 'detail', 'body', 'box', 'files', 'template', 'submit', 'refresher'];

    static values = { attachmentUrl: String, previewUrl: String, failed: String };

    connect() {
        current = this;
        this.refreshing = false;
        const context = { report: null, token: null };
        window.dispatchEvent(new CustomEvent('widget:context', { detail: context }));
        this.token = context.token;
        this.note(context.report);
        this.filesTarget.querySelectorAll('li').forEach((item) => this.picture(item));
        this.hold();
        this.take();
    }

    disconnect() {
        if (current === this) {
            current = null;
        }
    }

    noted(event) {
        this.note(event.detail.report);
    }

    note(report) {
        const field = this.element.querySelector('input[type="hidden"][name$="[technical]"]');
        if (null === field) {
            return;
        }
        if (null !== report) {
            field.value = JSON.stringify(report);
        }
        this.boxTarget.hidden = '' === field.value;
    }

    refresh() {
        this.refreshing = true;
        this.element.requestSubmit(this.refresherTarget);
    }

    /* A request of the form ended: one that failed leaves this form on the page, which takes what waited for the next. */
    settled(event) {
        if (!event.detail.success) {
            this.refreshing = false;
            this.take();
        }
    }

    // --- the files

    chosen(event) {
        Array.from(event.target.files).forEach((file) => this.send(file, false));
        event.target.value = '';
    }

    captured(event) {
        this.send(new File([event.detail.blob], event.detail.name, { type: event.detail.blob.type }), true);
    }

    async send(file, isCapture) {
        const body = new FormData();
        body.append('file', file);
        let answer = null;
        sending++;
        current?.hold();
        try {
            const response = await fetch(this.attachmentUrlValue, { method: 'POST', body, headers: { Authorization: `Bearer ${this.token}`, Accept: 'application/json' } });
            answer = await response.json();
        } catch {
            answer = null;
        }
        sending--;
        // the form on the page by now, which may be another one than this
        current?.hold();
        if (!answer?.uuid) {
            window.dispatchEvent(new CustomEvent('toast:show', { detail: { message: answer?.error ?? this.failedValue, tone: 'danger' } }));

            return;
        }
        if (file.type.startsWith('image/')) {
            previews.set(answer.uuid, URL.createObjectURL(file));
        }
        if (isCapture) {
            (current ?? this).forget(capture);
            capture = answer.uuid;
        }
        arrived.push(answer);
        current?.take();
    }

    /* The form is not sent while a file is on its way: it would leave without it. */
    hold() {
        this.submitTarget.disabled = sending > 0;
    }

    /* Puts in the form the files that arrived, unless it is being drawn again: the next one will. */
    take() {
        if (this.refreshing) {
            return;
        }
        for (const file of arrived.splice(0)) {
            const holder = document.createElement('template');
            // a function, not a text: a `$` of the name would be read as a pattern of the replacement
            holder.innerHTML = this.templateTarget.innerHTML.replaceAll('__uuid__', file.uuid).replaceAll('__name__', () => this.escape(file.name));
            const item = holder.content.firstElementChild;
            this.filesTarget.appendChild(item);
            this.picture(item);
        }
    }

    discard(event) {
        this.forget(event.target.closest('li').dataset.uuid);
    }

    forget(uuid) {
        if (null === uuid) {
            return;
        }
        const waiting = arrived.findIndex((file) => file.uuid === uuid);
        if (-1 !== waiting) {
            arrived.splice(waiting, 1);
        }
        this.filesTarget.querySelector(`li[data-uuid="${uuid}"]`)?.remove();
        if (previews.has(uuid)) {
            URL.revokeObjectURL(previews.get(uuid));
            previews.delete(uuid);
        }
        if (capture === uuid) {
            capture = null;
        }
    }

    picture(item) {
        const url = previews.get(item.dataset.uuid);
        const image = item.querySelector('img');
        if (url && image) {
            image.src = url;
            image.hidden = false;
            // the screenshot is shown whole, to be checked before the issue is sent
            item.toggleAttribute('data-capture', item.dataset.uuid === capture);
        }
    }

    escape(text) {
        const holder = document.createElement('span');
        holder.textContent = text;

        return holder.innerHTML.replaceAll('"', '&quot;');
    }

    // --- the report, read before it is sent

    async view() {
        const field = this.element.querySelector('input[type="hidden"][name$="[technical]"]');
        const response = await fetch(this.previewUrlValue, { method: 'POST', body: field.value, headers: { Authorization: `Bearer ${this.token}`, 'Content-Type': 'application/json' } });
        if (!response.ok) {
            return;
        }
        this.bodyTarget.innerHTML = await response.text();
        this.mainTarget.hidden = true;
        this.detailTarget.hidden = false;
    }

    back() {
        this.detailTarget.hidden = true;
        this.mainTarget.hidden = false;
    }
}
