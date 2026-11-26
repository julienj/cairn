/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

const MAX_WIDTH = 1920;
const MAX_BYTES = 1500000;
/* Below this, in pixels of the screenshot, a drag is a click: no shape, no crop. */
const SMALL = 12;

/*
 * The screenshot of the widget, taken by the browser itself
 * (getDisplayMedia): what the reporter sees, as they see it. The button
 * only shows where the browser allows it: a page served over HTTPS, in a
 * frame the site let capture. One click asks the browser, which asks the
 * reporter; the panel moves out of the way (`widget:collapse`), one image
 * is taken, and the sharing stops at once.
 *
 * The image then opens in the editor, in the panel made a dialog over the
 * page (`widget:widen`): the reporter draws on it (a line, an arrow, a
 * box, in a colour of a few), crops it, and attaches it. What is drawn is
 * kept apart from the image until then, so that it can be undone. What
 * goes to the form (`widget-capture:taken`) is the one image, flattened,
 * and what it was made of (`edit`: the image as it was taken, what is
 * drawn, the crop, what can be undone), which the form keeps and hands
 * back to annotate the screenshot again (`widget:annotate`). Chromium offers the tab itself; elsewhere the reporter
 * shares a window or a screen, which shows more than the site: the
 * editor then opens on cropping.
 */
export default class extends Controller {
    static targets = ['take', 'editor', 'canvas', 'tool', 'colour', 'undo', 'cropHelp'];

    static values = { failed: String };

    connect() {
        this.takeTarget.hidden = !(window.isSecureContext && navigator.mediaDevices?.getDisplayMedia);
        this.colourTargets.forEach((swatch) => { swatch.style.backgroundColor = swatch.dataset.colour; });
        this.editing = null;
    }

    disconnect() {
        if (null !== this.editing) {
            window.dispatchEvent(new CustomEvent('widget:narrow'));
        }
    }

    async take() {
        let stream = null;
        try {
            stream = await navigator.mediaDevices.getDisplayMedia({ video: { displaySurface: 'browser' }, audio: false, preferCurrentTab: true, selfBrowserSurface: 'include' });
        } catch (error) {
            // closing the browser's own dialog is a choice, not a failure
            if ('NotAllowedError' !== error.name) {
                this.fail();
            }

            return;
        }
        let image = null;
        let whole = false;
        try {
            const track = stream.getVideoTracks()[0];
            whole = 'browser' !== track.getSettings().displaySurface;
            await new Promise((done) => { window.dispatchEvent(new CustomEvent('widget:collapse', { detail: { done } })); });
            // the browser needs a moment to draw the page without the panel, and its own sharing notice to go
            await new Promise((done) => { setTimeout(done, 350); });
            image = await this.frame(stream);
        } catch {
            this.fail();
        } finally {
            stream.getTracks().forEach((track) => track.stop());
            window.dispatchEvent(new CustomEvent('widget:expand'));
        }
        if (null !== image) {
            this.open({ image, shapes: [], crop: null, history: [] }, whole ? 'crop' : 'pen');
        }
    }

    /* One image of the stream, as a canvas. */
    async frame(stream) {
        const video = document.createElement('video');
        video.muted = true;
        video.playsInline = true;
        video.srcObject = stream;
        await video.play();
        await new Promise((done) => { requestAnimationFrame(() => requestAnimationFrame(done)); });
        const canvas = document.createElement('canvas');
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        canvas.getContext('2d').drawImage(video, 0, 0);
        video.pause();
        video.srcObject = null;

        return canvas;
    }

    // --- the editor

    annotate({ detail: { edit } }) {
        if (null === this.editing) {
            this.open({ ...edit, shapes: [...edit.shapes], history: [...edit.history] }, 'pen');
        }
    }

    open(editing, tool) {
        this.editing = editing;
        this.shape = null;
        this.origin = null;
        this.selection = null;
        this.colour ??= this.colourTargets[0]?.dataset.colour ?? '#e11d48';
        this.width = Math.max(3, Math.round(Math.max(editing.image.width, editing.image.height) / 320));
        this.editorTarget.hidden = false;
        window.dispatchEvent(new CustomEvent('widget:widen'));
        this.use(tool);
        this.paint(this.colour);
        this.toolTargets.find((button) => button.dataset.tool === tool)?.focus();
    }

    close() {
        this.editing = null;
        this.shape = null;
        this.editorTarget.hidden = true;
        window.dispatchEvent(new CustomEvent('widget:narrow'));
        if (!this.takeTarget.hidden) {
            this.takeTarget.focus();
        }
    }

    cancel() {
        this.close();
    }

    async keep() {
        const editing = this.editing;
        this.close();
        await this.emit(this.flatten(editing), editing);
    }

    choose({ params: { tool } }) {
        this.use(tool);
    }

    use(tool) {
        this.tool = tool;
        this.toolTargets.forEach((button) => button.setAttribute('aria-pressed', String(button.dataset.tool === tool)));
        this.cropHelpTarget.hidden = 'crop' !== tool;
        this.draw();
    }

    pick({ params: { colour } }) {
        this.paint(colour);
    }

    paint(colour) {
        this.colour = colour;
        this.colourTargets.forEach((swatch) => swatch.setAttribute('aria-pressed', String(swatch.dataset.colour === colour)));
    }

    undo() {
        const last = this.editing.history.pop();
        if ('shape' === last?.type) {
            this.editing.shapes.pop();
        } else if ('crop' === last?.type) {
            this.editing.crop = last.before;
        }
        this.draw();
    }

    key(event) {
        if (null === this.editing) {
            return;
        }
        if ('Escape' === event.key) {
            event.preventDefault();
            this.cancel();
        } else if ('z' === event.key.toLowerCase() && (event.metaKey || event.ctrlKey) && !event.shiftKey) {
            event.preventDefault();
            this.undo();
        }
    }

    /* The part of the image shown: all of it while cropping, so that the crop can be drawn again. */
    region() {
        const { image, crop } = this.editing;

        return 'crop' === this.tool || null === crop ? { x: 0, y: 0, width: image.width, height: image.height } : crop;
    }

    point(event) {
        const region = this.region();
        const box = this.canvasTarget.getBoundingClientRect();
        const scale = region.width / box.width;

        return {
            x: region.x + Math.max(0, Math.min(region.width, (event.clientX - box.left) * scale)),
            y: region.y + Math.max(0, Math.min(region.height, (event.clientY - box.top) * scale)),
        };
    }

    press(event) {
        if (null === this.editing || 0 !== event.button) {
            return;
        }
        this.canvasTarget.setPointerCapture(event.pointerId);
        this.origin = this.point(event);
        if ('crop' === this.tool) {
            this.selection = null;
        } else {
            this.shape = { tool: this.tool, colour: this.colour, width: this.width, points: [this.origin, this.origin] };
        }
        this.draw();
    }

    drag(event) {
        if (null === this.origin) {
            return;
        }
        const to = this.point(event);
        if ('crop' === this.tool) {
            this.selection = this.box(this.origin, to);
        } else if ('pen' === this.shape.tool) {
            this.shape.points.push(to);
        } else {
            this.shape.points[1] = to;
        }
        this.draw();
    }

    release() {
        if (null === this.origin) {
            return;
        }
        if ('crop' === this.tool) {
            if (this.selection && this.selection.width > SMALL && this.selection.height > SMALL) {
                this.editing.history.push({ type: 'crop', before: this.editing.crop });
                this.editing.crop = this.selection;
                this.selection = null;
                this.use('pen');
            }
            this.selection = null;
        } else if (this.reaches(this.shape)) {
            this.editing.shapes.push(this.shape);
            this.editing.history.push({ type: 'shape' });
        }
        this.origin = null;
        this.shape = null;
        this.draw();
    }

    reaches(shape) {
        const [from, to] = [shape.points[0], shape.points[shape.points.length - 1]];

        return 'pen' === shape.tool ? shape.points.length > 2 : Math.hypot(to.x - from.x, to.y - from.y) > SMALL;
    }

    box(from, to) {
        return { x: Math.min(from.x, to.x), y: Math.min(from.y, to.y), width: Math.abs(to.x - from.x), height: Math.abs(to.y - from.y) };
    }

    draw() {
        if (null === this.editing) {
            return;
        }
        this.undoTarget.disabled = 0 === this.editing.history.length;
        const region = this.region();
        const canvas = this.canvasTarget;
        if (canvas.width !== Math.round(region.width) || canvas.height !== Math.round(region.height)) {
            canvas.width = Math.round(region.width);
            canvas.height = Math.round(region.height);
        }
        const context = canvas.getContext('2d');
        this.render(context, this.editing, region);
        if (null !== this.shape) {
            context.save();
            context.translate(-region.x, -region.y);
            this.stroke(context, this.shape);
            context.restore();
        }
        if ('crop' === this.tool) {
            const { image } = this.editing;
            const crop = this.selection ?? this.editing.crop;
            if (null === crop) {
                return;
            }
            context.fillStyle = 'rgba(17, 24, 39, 0.55)';
            context.fillRect(0, 0, image.width, image.height);
            context.save();
            context.beginPath();
            context.rect(crop.x, crop.y, crop.width, crop.height);
            context.clip();
            this.render(context, this.editing, region);
            context.restore();
            context.strokeStyle = '#ffffff';
            context.lineWidth = Math.max(2, image.width / 400);
            context.strokeRect(crop.x, crop.y, crop.width, crop.height);
        }
    }

    /* The image and what is drawn on it, the part `region` of it at the origin of `context`. */
    render(context, editing, region) {
        context.drawImage(editing.image, region.x, region.y, region.width, region.height, 0, 0, region.width, region.height);
        context.save();
        context.translate(-region.x, -region.y);
        editing.shapes.forEach((shape) => this.stroke(context, shape));
        context.restore();
    }

    stroke(context, { tool, colour, width, points }) {
        context.strokeStyle = colour;
        context.lineWidth = width;
        context.lineCap = 'round';
        context.lineJoin = 'round';
        context.beginPath();
        const from = points[0];
        const to = points[points.length - 1];
        if ('pen' === tool) {
            context.moveTo(from.x, from.y);
            points.slice(1).forEach(({ x, y }) => context.lineTo(x, y));
        } else if ('box' === tool) {
            const { x, y, width: w, height: h } = this.box(from, to);
            context.rect(x, y, w, h);
        } else if ('arrow' === tool) {
            const angle = Math.atan2(to.y - from.y, to.x - from.x);
            const head = width * 4;
            context.moveTo(from.x, from.y);
            context.lineTo(to.x, to.y);
            context.moveTo(to.x - head * Math.cos(angle - Math.PI / 6), to.y - head * Math.sin(angle - Math.PI / 6));
            context.lineTo(to.x, to.y);
            context.lineTo(to.x - head * Math.cos(angle + Math.PI / 6), to.y - head * Math.sin(angle + Math.PI / 6));
        }
        context.stroke();
    }

    /* What is attached: the part kept, with what is drawn on it, as one image. */
    flatten(editing) {
        const region = editing.crop ?? { x: 0, y: 0, width: editing.image.width, height: editing.image.height };
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(region.width);
        canvas.height = Math.round(region.height);
        this.render(canvas.getContext('2d'), editing, region);

        return canvas;
    }

    // --- handing the image to the form

    async emit(image, edit) {
        let canvas = image;
        if (image.width > MAX_WIDTH) {
            canvas = document.createElement('canvas');
            canvas.width = MAX_WIDTH;
            canvas.height = Math.round(image.height * MAX_WIDTH / image.width);
            canvas.getContext('2d').drawImage(image, 0, 0, canvas.width, canvas.height);
        }
        let blob = await new Promise((done) => { canvas.toBlob(done, 'image/png'); });
        let name = 'capture.png';
        if (blob && blob.size > MAX_BYTES) {
            // a photograph of a page weighs too much as PNG: JPEG, barely lossy
            blob = await new Promise((done) => { canvas.toBlob(done, 'image/jpeg', 0.88); });
            name = 'capture.jpg';
        }
        if (!blob) {
            this.fail();

            return;
        }
        this.dispatch('taken', { detail: { blob, name, edit } });
    }

    fail() {
        window.dispatchEvent(new CustomEvent('toast:show', { detail: { message: this.failedValue, tone: 'danger' } }));
    }
}
