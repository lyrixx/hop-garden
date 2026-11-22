import { Controller } from '@hotwired/stimulus';

// The link of an invitation (templates/gardens/members.html.twig): its button copies it, and says so. Asked from the list
// ("Copy a new link"), it is copied as soon as the page shows it, where the browser lets a page write to the clipboard
// without a click; elsewhere, the button waits, focused. Without the clipboard (no HTTPS), the link is selected instead.
export default class extends Controller {
    static targets = ['source', 'button'];
    static values = { copied: String, auto: Boolean };

    connect() {
        if (this.autoValue) {
            this.write().catch(() => this.buttonTarget.focus());
        }
    }

    copy() {
        this.write().catch(() => this.select());
    }

    select() {
        this.sourceTarget.select();
    }

    async write() {
        await navigator.clipboard.writeText(this.sourceTarget.value);
        this.buttonTarget.textContent = this.copiedValue;
    }
}
