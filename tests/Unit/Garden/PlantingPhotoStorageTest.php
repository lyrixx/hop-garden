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

    private string $shareDir;
    private TestHandler $logs;

    protected function setUp(): void
    {
        $this->shareDir = sys_get_temp_dir() . '/hop-garden-share-' . bin2hex(random_bytes(4));
        mkdir($this->shareDir . '/uploads/plantings', recursive: true);
        $this->logs = new TestHandler();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->shareDir . '/{,uploads/,uploads/plantings/,legacy/}*', GLOB_BRACE) ?: [] as $path) {
            is_dir($path) ? @rmdir($path) : @unlink($path);
        }
        @rmdir($this->shareDir . '/uploads/plantings');
        @rmdir($this->shareDir . '/uploads');
        @rmdir($this->shareDir . '/legacy');
        @rmdir($this->shareDir);
    }

    public function testThePhotoIsNamedByTheServer(): void
    {
        $filename = $this->storage()->store($this->upload('../../../index.php.png'));

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.png$/', $filename);
        self::assertFileExists($this->shareDir . '/uploads/plantings/' . $filename);
        self::assertSame($this->shareDir . '/uploads/plantings/' . $filename, $this->storage()->path($filename), 'Out of public/: served by its controller.');
    }

    public function testAMissingPhotoHasNoPath(): void
    {
        self::assertNull($this->storage()->path('0123456789abcdef0123456789abcdef.png'));
        self::assertNull($this->storage()->path('../../../../etc/passwd'));
        self::assertTrue($this->logs->hasWarningThatContains('The photo of a planting is missing.'));
    }

    public function testThePhotosOfBeforeAreMovedOutOfPublic(): void
    {
        mkdir($this->shareDir . '/legacy');
        file_put_contents($this->shareDir . '/legacy/0123456789abcdef0123456789abcdef.png', base64_decode(self::PNG, true));
        file_put_contents($this->shareDir . '/legacy/index.php', '<?php');

        self::assertSame(['0123456789abcdef0123456789abcdef.png'], $this->storage()->moveFrom($this->shareDir . '/legacy'));
        self::assertFileExists($this->shareDir . '/uploads/plantings/0123456789abcdef0123456789abcdef.png');
        self::assertFileDoesNotExist($this->shareDir . '/legacy/0123456789abcdef0123456789abcdef.png');
        self::assertFileExists($this->shareDir . '/legacy/index.php', 'Only the photos it named.');
        self::assertSame([], $this->storage()->moveFrom($this->shareDir . '/legacy'), 'Once.');
        self::assertSame([], $this->storage()->moveFrom($this->shareDir . '/gone'));
    }

    public function testAReplacedPhotoIsRemoved(): void
    {
        $filename = $this->storage()->store($this->upload('rose.png'));

        $this->storage()->remove($filename);

        self::assertFileDoesNotExist($this->shareDir . '/uploads/plantings/' . $filename);
        self::assertSame([], $this->logs->getRecords());
    }

    public function testOnlyAPhotoOfTheGardenIsRemoved(): void
    {
        file_put_contents($this->shareDir . '/index.php', '<?php');

        $this->storage()->remove('../../index.php');
        $this->storage()->remove('gone.png');

        self::assertFileExists($this->shareDir . '/index.php');
        self::assertSame([], $this->logs->getRecords(), 'Nothing to remove.');
    }

    private function storage(): PlantingPhotoStorage
    {
        return new PlantingPhotoStorage($this->shareDir, new Logger('test', [$this->logs]));
    }

    private function upload(string $clientName): UploadedFile
    {
        $path = $this->shareDir . '/' . bin2hex(random_bytes(4));
        file_put_contents($path, base64_decode(self::PNG, true));

        return new UploadedFile($path, $clientName, 'image/png', test: true);
    }
}
