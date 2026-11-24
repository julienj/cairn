/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

const MAX_WIDTH = 1920;
const MAX_BYTES = 1500000;

/*
 * The screenshot of the widget, taken by the browser itself
 * (getDisplayMedia): what the reporter sees, as they see it. The button
 * only shows where the browser allows it: a page served over HTTPS, in a
 * frame the site let capture. One click asks the browser, which asks the
 * reporter; the panel moves out of the way (`widget:collapse`), one image
 * is taken, and the sharing stops at once.
 *
 * Chromium offers the tab itself: the image is the page, and goes
 * straight to the form (`widget-capture:taken`). Elsewhere the reporter
 * shares a window or a screen, which may show more than the site: the
 * image is then cropped first, by dragging over the part to keep.
 */
export default class extends Controller {
    static targets = ['take', 'cropper', 'canvas'];

    static values = { failed: String };

    connect() {
        this.takeTarget.hidden = !(window.isSecureContext && navigator.mediaDevices?.getDisplayMedia);
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
        try {
            const track = stream.getVideoTracks()[0];
            const whole = 'browser' !== track.getSettings().displaySurface;
            await new Promise((done) => { window.dispatchEvent(new CustomEvent('widget:collapse', { detail: { done } })); });
            // the browser needs a moment to draw the page without the panel, and its own sharing notice to go
            await new Promise((done) => { setTimeout(done, 350); });
            const image = await this.frame(stream);
            if (whole) {
                this.crop(image);
            } else {
                await this.emit(image);
            }
        } catch {
            this.fail();
        } finally {
            stream.getTracks().forEach((track) => track.stop());
            window.dispatchEvent(new CustomEvent('widget:expand'));
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

    // --- cropping what is more than the page

    crop(image) {
        this.image = image;
        this.selection = null;
        this.canvasTarget.width = image.width;
        this.canvasTarget.height = image.height;
        this.takeTarget.hidden = true;
        this.cropperTarget.hidden = false;
        this.draw();
    }

    point(event) {
        const box = this.canvasTarget.getBoundingClientRect();
        const scale = this.image.width / box.width;

        return {
            x: Math.max(0, Math.min(this.image.width, (event.clientX - box.left) * scale)),
            y: Math.max(0, Math.min(this.image.height, (event.clientY - box.top) * scale)),
        };
    }

    press(event) {
        this.canvasTarget.setPointerCapture(event.pointerId);
        this.origin = this.point(event);
        this.selection = null;
        this.draw();
    }

    drag(event) {
        if (!this.origin) {
            return;
        }
        const to = this.point(event);
        this.selection = { x: Math.min(this.origin.x, to.x), y: Math.min(this.origin.y, to.y), width: Math.abs(to.x - this.origin.x), height: Math.abs(to.y - this.origin.y) };
        this.draw();
    }

    release() {
        this.origin = null;
    }

    draw() {
        const context = this.canvasTarget.getContext('2d');
        context.drawImage(this.image, 0, 0);
        if (!this.selection) {
            return;
        }
        const { x, y, width, height } = this.selection;
        context.fillStyle = 'rgba(17, 24, 39, 0.55)';
        context.fillRect(0, 0, this.image.width, this.image.height);
        context.drawImage(this.image, x, y, width, height, x, y, width, height);
        context.strokeStyle = '#ffffff';
        context.lineWidth = Math.max(2, this.image.width / 400);
        context.strokeRect(x, y, width, height);
    }

    async keep() {
        let image = this.image;
        if (this.selection && this.selection.width > 20 && this.selection.height > 20) {
            const { x, y, width, height } = this.selection;
            image = document.createElement('canvas');
            image.width = Math.round(width);
            image.height = Math.round(height);
            image.getContext('2d').drawImage(this.image, x, y, width, height, 0, 0, image.width, image.height);
        }
        this.cancel();
        await this.emit(image);
    }

    cancel() {
        this.image = null;
        this.selection = null;
        this.cropperTarget.hidden = true;
        this.takeTarget.hidden = false;
    }

    // --- handing the image to the form

    async emit(image) {
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
        this.dispatch('taken', { detail: { blob, name } });
    }

    fail() {
        window.dispatchEvent(new CustomEvent('toast:show', { detail: { message: this.failedValue, tone: 'danger' } }));
    }
}
