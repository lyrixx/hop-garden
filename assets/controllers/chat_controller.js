import { Controller } from '@hotwired/stimulus';

/*
 * The chat: Turbo posts the message, the worker runs the agent, and its answer comes back as Turbo Streams, through
 * Mercure (App\Chat\AgentRunner). What stays here belongs to the page: the message shown as soon as it is sent, the
 * waiting dots, the end of the conversation kept in sight, the buttons of the last question, and the message that tells
 * the agent a card was tapped.
 */
export default class extends Controller {
    static targets = ['log', 'form', 'input', 'submit', 'empty', 'run', 'sentMessage', 'sendError', 'silence', 'choices'];

    initialize() {
        // The frames of the cards whose form was sent, until they show the outcome
        this.followedFrames = new Set();
    }

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

    // A suggestion of the empty chat, or an option of a question: sent as if typed
    suggest({ params: { text } }) {
        this.say(text);
    }

    // Once the agent is done, when it runs
    say(text) {
        if (this.busy) {
            this.queued = text;

            return;
        }

        this.inputTarget.value = text;
        this.formTarget.requestSubmit();
    }

    // A form of a card that tells the agent once it succeeds (data-chat-follow-up): validate a draft, create a space,
    // add a plant to the garden
    follow({ target }) {
        const frame = target.closest('turbo-frame');
        if (target.hasAttribute('data-chat-follow-up') && frame) {
            this.followedFrames.add(frame.id);
        }
    }

    // The outcome in its frame: the card says it with data-chat-notice once done, not when the form comes back invalid
    tell({ target }) {
        if (!this.followedFrames.delete(target.id)) {
            return;
        }

        const notice = target.querySelector('[data-chat-notice]');
        if (notice) {
            this.say(notice.dataset.chatNotice);
        }
    }

    // A new question: the ones before are over. Called for each question of the history too, in order, when the page
    // loads: the ones after it stay as they are
    choicesTargetConnected(element) {
        this.choicesTargets
            .filter((choices) => choices.compareDocumentPosition(element) & Node.DOCUMENT_POSITION_FOLLOWING)
            .forEach((choices) => (choices.dataset.answered = 'true'));
        this.refreshChoices();
    }

    // Before Turbo submits the form
    send(event) {
        const text = this.inputTarget.value.trim();
        if ('' === text || this.busy) {
            event.preventDefault();

            return;
        }

        this.emptyTargets.forEach((element) => element.remove());
        // Answered, by a tap or by typing
        this.choicesTargets.forEach((choices) => (choices.dataset.answered = 'true'));
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
        this.refreshChoices();
        clearTimeout(this.timeout);
        if (busy) {
            // Down to the thinking dots, under the message just sent
            this.scroll();
            // The worker always ends with "done" or "error": silence means it is not running
            this.timeout = setTimeout(() => this.showError(this.silenceTarget), 120000);
        } else if (this.queued) {
            const text = this.queued;
            this.queued = null;
            this.say(text);
        }
    }

    // Only the last question has its buttons on, until it is answered, and not while the agent runs
    refreshChoices() {
        const last = this.choicesTargets.at(-1);
        this.choicesTargets.forEach((choices) => {
            const answered = 'true' === choices.dataset.answered;
            choices.classList.toggle('is-answered', answered);
            choices.querySelectorAll('button').forEach((button) => (button.disabled = answered || choices !== last || !!this.busy));
        });
    }

    // The end of the chat at the bottom of the screen, where its form sticks: the end of the page would show the
    // footer, and push the conversation above the screen
    scroll() {
        this.element.scrollIntoView({ block: 'end' });
    }
}
