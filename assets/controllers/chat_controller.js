import { Controller } from '@hotwired/stimulus';

/*
 * The chat: Turbo posts the message, the worker runs the agent, and its answer comes back as Turbo Streams, through
 * Mercure (App\Chat\AgentRunner). What stays here belongs to the page: the message shown as soon as it is sent, the
 * waiting dots, the end of the conversation kept in sight.
 */
export default class extends Controller {
    static targets = ['log', 'form', 'input', 'submit', 'empty', 'run', 'sentMessage', 'sendError', 'silence'];

    connect() {
        // An empty conversation stays at the top, its title in view
        if (!this.hasEmptyTarget) {
            this.scroll();
            // The text grows or shrinks when the fonts arrive
            document.fonts.ready.then(() => this.scroll());
        }
    }

    disconnect() {
        clearTimeout(this.timeout);
    }

    suggest({ params: { text } }) {
        this.inputTarget.value = text;
        this.formTarget.requestSubmit();
    }

    // Before Turbo submits the form
    send(event) {
        const text = this.inputTarget.value.trim();
        if ('' === text || this.busy) {
            event.preventDefault();

            return;
        }

        this.emptyTargets.forEach((element) => element.remove());
        const message = this.sentMessageTarget.content.cloneNode(true);
        message.firstElementChild.textContent = text;
        this.logTarget.append(message);
        this.setBusy(true);
    }

    // The form data is read already
    sending() {
        this.inputTarget.value = '';
    }

    // Turbo would show the error page in place of the chat
    received(event) {
        if (!event.detail.fetchResponse.succeeded) {
            event.preventDefault();
        }
    }

    sent(event) {
        // Turbo enables the button again
        this.submitTarget.disabled = this.busy;
        if (!event.detail.success) {
            this.showError(this.sendErrorTarget);
        }
    }

    // The worker starts or ends a run: see templates/components/Chat/Run.html.twig
    runTargetConnected(element) {
        this.setBusy('true' === element.dataset.busy);
    }

    // After each stream that changes the chat, its end in sight again
    rendering(event) {
        const stream = event.target;
        if (!stream.hasAttribute('target') && !stream.hasAttribute('targets')) {
            return;
        }
        if (!stream.targetElements.some((element) => this.element.contains(element))) {
            return;
        }

        const render = event.detail.render;
        event.detail.render = async (streamElement) => {
            await render(streamElement);
            this.scroll();
        };
    }

    showError(template) {
        this.logTarget.append(template.content.cloneNode(true));
        this.setBusy(false);
        this.scroll();
    }

    setBusy(busy) {
        this.busy = busy;
        this.submitTarget.disabled = busy;
        this.element.classList.toggle('is-busy', busy);
        clearTimeout(this.timeout);
        if (busy) {
            // Down to the thinking dots, under the message just sent
            this.scroll();
            // The worker always ends with "done" or "error": silence means it is not running
            this.timeout = setTimeout(() => this.showError(this.silenceTarget), 120000);
        }
    }

    // The end of the chat at the bottom of the screen, where its form sticks: the end of the page would show the
    // footer, and push the conversation above the screen
    scroll() {
        this.element.scrollIntoView({ block: 'end' });
    }
}
