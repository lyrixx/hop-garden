import { Controller } from '@hotwired/stimulus';

/*
 * The subscriber cookie of the Mercure hub lasts an hour, and the hub closes a stream when it expires: the stream
 * reconnects with the cookie of the moment. A page left open, the garden waiting for its frost alert at 7:00, renews it
 * before then (App\Controller\MercureController::renewCookie()). Back from a computer asleep, a stream refused with the
 * expired cookie is closed for good: the page renews it, and opens the stream again.
 */
const RENEW_EVERY = 20 * 60 * 1000;
// A tab shown again, after a while hidden
const RENEW_AFTER = 5 * 60 * 1000;

export default class extends Controller {
    static values = { url: String };

    connect() {
        this.renewedAt = Date.now();
        this.interval = setInterval(() => this.renew(), RENEW_EVERY);
        this.wake = () => {
            if ('visible' === document.visibilityState && (Date.now() - this.renewedAt > RENEW_AFTER || this.closedSources().length > 0)) {
                this.renew();
            }
        };
        document.addEventListener('visibilitychange', this.wake);
        window.addEventListener('online', this.wake);
    }

    disconnect() {
        clearInterval(this.interval);
        document.removeEventListener('visibilitychange', this.wake);
        window.removeEventListener('online', this.wake);
    }

    async renew() {
        this.renewedAt = Date.now();
        let response;
        try {
            // Logged out: a redirect to the login page, not followed
            response = await fetch(this.urlValue, { method: 'POST', redirect: 'manual' });
        } catch {
            // Offline: back online renews it
            return;
        }
        if (204 !== response.status) {
            return;
        }
        for (const source of this.closedSources()) {
            source.setAttribute('src', source.getAttribute('src'));
        }
    }

    closedSources() {
        return [...document.querySelectorAll('turbo-mercure-stream-source[private]:not([connected])')];
    }
}
