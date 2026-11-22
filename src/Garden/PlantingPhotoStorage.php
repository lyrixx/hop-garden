<?php

namespace App\Garden;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The photos of the plantings, out of public/: in the share directory (APP_SHARE_DIR, a volume in production, shared by
 * the web and the worker), served to the members of their garden only by GardenController::photo().
 */
final readonly class PlantingPhotoStorage
{
    /**
     * Of a name it gives: 32 hexadecimal characters and the extension of the image, never a path.
     */
    public const string FILENAME_REQUIREMENT = '[0-9a-f]{32}\.(jpg|jpeg|png|webp)';

    private const string DIRECTORY = 'uploads/plantings';

    private string $directory;

    public function __construct(
        #[Autowire('%kernel.share_dir%')]
        string $shareDir,
        private LoggerInterface $logger,
    ) {
        $this->directory = $shareDir . '/' . self::DIRECTORY;
    }

    /**
     * The file name and its extension come from the server, never from the client.
     *
     * @return string the file name of the stored photo
     */
    public function store(UploadedFile $photo): string
    {
        // Guessed from the content, already checked by the Image constraint
        $extension = $photo->guessExtension() ?? throw new \LogicException('A validated photo has a known image type.');
        $filename = bin2hex(random_bytes(16)) . '.' . $extension;

        $photo->move($this->directory, $filename);

        return $filename;
    }

    /**
     * A replaced photo, or the photos of a deleted garden: a file left behind only takes room, the warning tells which one.
     */
    public function remove(string $filename): void
    {
        $path = $this->directory . '/' . basename($filename);
        if (is_file($path) && !@unlink($path)) {
            $this->logger->warning('A photo could not be removed.', ['path' => $path, 'error' => error_get_last()['message'] ?? null]);
        }
    }

    /**
     * Null when the file is missing: a database restored without its photos, a volume lost.
     */
    public function path(string $filename): ?string
    {
        $path = $this->directory . '/' . basename($filename);
        if (!is_file($path)) {
            $this->logger->warning('The photo of a planting is missing.', ['path' => $path]);

            return null;
        }

        return $path;
    }

    /**
     * The photos of the time they were public (public/uploads/plantings), moved here: app:plantings:move-photos.
     *
     * @return list<string> the file names moved
     */
    public function moveFrom(string $legacyDirectory): array
    {
        if (!is_dir($legacyDirectory)) {
            return [];
        }

        if (!is_dir($this->directory) && !mkdir($this->directory, 0o775, true) && !is_dir($this->directory)) {
            throw new \RuntimeException(\sprintf('The directory "%s" could not be created.', $this->directory));
        }

        $moved = [];
        foreach (new \FilesystemIterator($legacyDirectory) as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || 1 !== preg_match('/^' . self::FILENAME_REQUIREMENT . '$/', $file->getFilename())) {
                continue;
            }

            if (!rename($file->getPathname(), $this->directory . '/' . $file->getFilename())) {
                throw new \RuntimeException(\sprintf('The photo "%s" could not be moved to "%s".', $file->getPathname(), $this->directory));
            }
            $moved[] = $file->getFilename();
        }
        sort($moved);

        return $moved;
    }
}
