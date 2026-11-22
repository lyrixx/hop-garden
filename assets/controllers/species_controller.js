import { Controller } from '@hotwired/stimulus';
import * as Turbo from '@hotwired/turbo';

/*
 * The page of a species. Its card is validated in its frame; once validated, the plant is in the garden, and the page
 * around the card follows: refreshed in place, a morph that keeps the scroll (see templates/plant/show.html.twig).
 */
export default class extends Controller {
    submitted({ target, detail }) {
        if (detail.success && target.action.endsWith('/validate')) {
            Turbo.visit(window.location.href, { action: 'replace' });
        }
    }
}
