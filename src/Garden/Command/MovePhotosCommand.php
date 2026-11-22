<?php

namespace App\Garden\Command;

use App\Garden\PlantingPhotoStorage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The photos of the time they were public, in public/uploads/plantings, moved to the private storage of the share
 * directory (PlantingPhotoStorage). Once, after the deployment that makes them private, with the old volume of
 * public/uploads still mounted (see the README); then again, it finds nothing.
 */
#[AsCommand(name: 'app:plantings:move-photos', description: 'Moves the photos of the plantings out of public/, to the private storage')]
final readonly class MovePhotosCommand
{
    public function __construct(
        private PlantingPhotoStorage $photoStorage,
        #[Autowire('%kernel.project_dir%/public/uploads/plantings')]
        private string $legacyDirectory,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $moved = $this->photoStorage->moveFrom($this->legacyDirectory);

        $io->success(\sprintf('%d photo(s) moved out of %s.', \count($moved), $this->legacyDirectory));

        return Command::SUCCESS;
    }
}
