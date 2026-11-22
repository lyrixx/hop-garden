<?php

namespace App\Twig\Components;

use App\Garden\Form\Model\SpeciesPickerData;
use App\Garden\Model\SpeciesSearchResult;
use App\Garden\SpeciesSearch;
use Symfony\Component\Form\FormView;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * A name and its species, picked among the ones Trefle finds for the name as it is typed: for a new planting, where
 * picking one is optional, or for a new species, where it is required. The search re-renders the component with a GET:
 * it changes nothing.
 */
#[AsLiveComponent(method: 'get')]
final class SpeciesPicker
{
    use DefaultActionTrait;

    /**
     * The form, at mount only: its name and species fields are rendered once, then the live controller keeps their
     * values in sync with the props, so that the photo and the rest of the form never re-render.
     */
    public ?FormView $form = null;

    /**
     * A new species needs one from Trefle; a planting keeps its name as is without.
     */
    #[LiveProp]
    public bool $required = false;

    /**
     * The name, as typed: the search query.
     */
    #[LiveProp(writable: true)]
    public string $name = '';

    /**
     * The Trefle id of the picked species, or null. Set by the browser: the hook checks it among the candidates.
     */
    #[LiveProp(writable: true, onUpdated: 'onTrefleIdUpdated')]
    public ?int $trefleId = null;

    /**
     * The scientific name of the picked species, from Trefle, never from the browser.
     */
    #[LiveProp]
    public ?string $scientificName = null;

    public function __construct(
        private readonly SpeciesSearch $speciesSearch,
    ) {
    }

    public function mount(FormView $form): void
    {
        $data = $form->vars['data'] ?? null;
        if (!$data instanceof SpeciesPickerData) {
            throw new \LogicException(\sprintf('The species picker needs a form of %s.', SpeciesPickerData::class));
        }

        $this->form = $form;
        $this->name = $data->name;
        $this->trefleId = $data->trefleId;
        $this->scientificName = $data->scientificName;
    }

    /**
     * Picked among the candidates of the current name, or cleared: an id that is not one of them clears it too.
     */
    public function onTrefleIdUpdated(): void
    {
        $candidate = null !== $this->trefleId ? $this->speciesSearch->search($this->name)?->find($this->trefleId) : null;

        $this->trefleId = $candidate?->trefleId;
        $this->scientificName = $candidate?->scientificName;
    }

    /**
     * Null while a species is picked, or while the name is too short to search.
     */
    public function getSearchResult(): ?SpeciesSearchResult
    {
        return null === $this->scientificName ? $this->speciesSearch->search($this->name) : null;
    }
}
