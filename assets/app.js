import './stimulus_bootstrap.js';
import '@fontsource-variable/literata/index.css';
import '@fontsource-variable/literata/wght-italic.css';
import '@fontsource-variable/dm-sans/index.css';
import './styles/app.css';
import './styles/plate.css';
import './styles/garden.css';
import './styles/account.css';

// A Turbo Frame changed by one of its own forms fades from its old content to the new one: the switch of a reminder,
// a draft card validated in place, whose status grows into "Validated"
const STATUS_TRANSITION = 'plant-status';
document.addEventListener('turbo:submit-start', ({ target }) => target.closest('turbo-frame')?.setAttribute('data-transition', ''));
document.addEventListener('turbo:before-frame-render', (event) => {
    const frame = event.target;
    if (!frame.hasAttribute('data-transition')) {
        return;
    }
    frame.removeAttribute('data-transition');
    if (!document.startViewTransition || matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return;
    }

    const render = event.detail.render;
    const nameStatus = () => frame.querySelector('.plant-card .status')?.style.setProperty('view-transition-name', STATUS_TRANSITION);
    event.detail.render = (currentElement, newElement) => {
        nameStatus();
        const transition = document.startViewTransition(() => {
            render(currentElement, newElement);
            nameStatus();
        });
        transition.finished.finally(() => frame.querySelector('.plant-card .status')?.style.removeProperty('view-transition-name'));
    };
});
