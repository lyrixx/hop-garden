<?php

namespace App\Garden;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class PlantingPhotoStorage
{
    private const string PUBLIC_DIR = 'uploads/plantings';

    public function __construct(
        #[Autowire('%kernel.project_dir%/public')]
        private string $publicDir,
        private LoggerInterface $logger,
    ) {
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

        $photo->move($this->publicDir . '/' . self::PUBLIC_DIR, $filename);

        return $filename;
    }

    /**
     * A replaced photo: a file left behind only takes room, the warning tells which one.
     */
    public function remove(string $filename): void
    {
        $path = $this->publicDir . '/' . self::PUBLIC_DIR . '/' . basename($filename);
        if (is_file($path) && !@unlink($path)) {
            $this->logger->warning('A replaced photo could not be removed.', ['path' => $path, 'error' => error_get_last()['message'] ?? null]);
        }
    }

    /**
     * @return string relative to the public directory
     */
    public function publicPath(string $filename): string
    {
        return self::PUBLIC_DIR . '/' . $filename;
    }
}
