<?php

namespace App\Tests\Unit\Garden;

use App\Garden\PlantingPhotoStorage;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class PlantingPhotoStorageTest extends TestCase
{
    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    private string $publicDir;
    private TestHandler $logs;

    protected function setUp(): void
    {
        $this->publicDir = sys_get_temp_dir() . '/hop-garden-public-' . bin2hex(random_bytes(4));
        mkdir($this->publicDir . '/uploads/plantings', recursive: true);
        $this->logs = new TestHandler();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->publicDir . '/{,uploads/,uploads/plantings/}*', GLOB_BRACE) ?: [] as $path) {
            is_dir($path) ? @rmdir($path) : @unlink($path);
        }
        @rmdir($this->publicDir . '/uploads/plantings');
        @rmdir($this->publicDir . '/uploads');
        @rmdir($this->publicDir);
    }

    public function testThePhotoIsNamedByTheServer(): void
    {
        $filename = $this->storage()->store($this->upload('../../../index.php.png'));

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.png$/', $filename);
        self::assertFileExists($this->publicDir . '/uploads/plantings/' . $filename);
        self::assertSame('uploads/plantings/' . $filename, $this->storage()->publicPath($filename));
    }

    public function testAReplacedPhotoIsRemoved(): void
    {
        $filename = $this->storage()->store($this->upload('rose.png'));

        $this->storage()->remove($filename);

        self::assertFileDoesNotExist($this->publicDir . '/uploads/plantings/' . $filename);
        self::assertSame([], $this->logs->getRecords());
    }

    public function testOnlyAPhotoOfTheGardenIsRemoved(): void
    {
        file_put_contents($this->publicDir . '/index.php', '<?php');

        $this->storage()->remove('../../index.php');
        $this->storage()->remove('gone.png');

        self::assertFileExists($this->publicDir . '/index.php');
        self::assertSame([], $this->logs->getRecords(), 'Nothing to remove.');
    }

    private function storage(): PlantingPhotoStorage
    {
        return new PlantingPhotoStorage($this->publicDir, new Logger('test', [$this->logs]));
    }

    private function upload(string $clientName): UploadedFile
    {
        $path = $this->publicDir . '/' . bin2hex(random_bytes(4));
        file_put_contents($path, base64_decode(self::PNG, true));

        return new UploadedFile($path, $clientName, 'image/png', test: true);
    }
}
