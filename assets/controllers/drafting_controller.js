import { Controller } from '@hotwired/stimulus';
import * as Turbo from '@hotwired/turbo';

/*
 * The page of a planting while the agent drafts its care sheet. Its tool calls come as Turbo Streams, then the worker
 * streams the summary of the draft, or refreshes the page with the failure (App\Garden\DraftCareSheetHandler). What
 * stays here belongs to the page: a run that may have ended before the page listened, and a worker that does not
 * answer.
 */
export default class extends Controller {
    static targets = ['pending', 'drafted', 'failure', 'failureMessage'];
    // silence: what to say when the worker does not answer, in the language of the page
    static values = { drafting: Boolean, statusUrl: String, silence: String };

    connect() {
        this.source = this.element.querySelector('turbo-mercure-stream-source');
        if (this.source) {
            this.observer = new MutationObserver(() => this.source.hasAttribute('connected') && this.catchUp());
            this.observer.observe(this.source, { attributes: true, attributeFilter: ['connected'] });
        }
    }

    disconnect() {
        this.observer?.disconnect();
        clearTimeout(this.timeout);
    }

    // The draft came, streamed by the worker: nothing left to wait for
    draftedTargetConnected() {
        this.draftingValue = false;
    }

    draftingValueChanged(drafting) {
        drafting ? this.watch() : clearTimeout(this.timeout);
    }

    // The run may have ended before the page started listening: a refresh, in place (a morph)
    async catchUp() {
        const response = await fetch(this.statusUrlValue, { headers: { Accept: 'application/json' } });
        if (response.ok && !(await response.json()).drafting && this.draftingValue) {
            Turbo.visit(window.location.href, { action: 'replace' });
        }
    }

    // The worker always ends with a card or a failure: a long silence means it is not running
    watch() {
        if (!this.draftingValue) {
            return;
        }
        clearTimeout(this.timeout);
        this.timeout = setTimeout(() => this.fail(this.silenceValue), 120000);
    }

    fail(message) {
        if (this.hasPendingTarget) {
            this.pendingTarget.hidden = true;
        }
        this.failureMessageTarget.textContent = message;
        this.failureTarget.hidden = false;
    }
}
