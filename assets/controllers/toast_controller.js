import { Controller } from '@hotwired/stimulus';

const LIFETIME = 15000;
const LEAVING = 600;

// A toast pushed by the worker (templates/components/Notification/Toast.html.twig): it fades out after a while, or on its button.
// Its container survives a Turbo Drive visit, which disconnects and connects the toast again: it keeps its deadline.
export default class extends Controller {
    connect() {
        this.leavesAt ??= Date.now() + LIFETIME;
        this.leaveTimeout = setTimeout(() => this.close(), Math.max(0, this.leavesAt - Date.now()));
    }

    disconnect() {
        clearTimeout(this.leaveTimeout);
    }

    close() {
        clearTimeout(this.leaveTimeout);
        this.element.classList.add('is-leaving');
        setTimeout(() => this.element.remove(), LEAVING);
    }
}
