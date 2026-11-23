<?php

namespace App\Tests\Unit\Garden;

use App\Garden\Slugs;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SlugsTest extends TestCase
{
    /**
     * @param list<string> $taken
     */
    #[DataProvider('provideTheFirstSlugFreeCases')]
    public function testTheFirstSlugFree(string $expected, string $base, array $taken): void
    {
        self::assertSame($expected, Slugs::firstFree($base, $taken));
    }

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function provideTheFirstSlugFreeCases(): iterable
    {
        yield 'free' => ['rose', 'rose', []];
        yield 'taken' => ['rose-2', 'rose', ['rose']];
        yield 'its suffixes taken too' => ['rose-4', 'rose', ['rose', 'rose-2', 'rose-3']];
        yield 'another name of the same start' => ['rose', 'rose', ['rose-bush', 'rose-2']];
        yield 'reserved by a page' => ['new-2', 'new', []];
    }
}
