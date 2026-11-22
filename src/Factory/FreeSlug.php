<?php

namespace App\Factory;

use App\Garden\Entity\Garden;
use App\Garden\Slugs;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Zenstruck\Foundry\Configuration;
use Zenstruck\Foundry\Factory;
use Zenstruck\Foundry\LazyValue;

/**
 * The slug of what a factory makes, unless told, as App\Garden\Slugs picks it: its name made a slug, free among the
 * ones in the database and the ones it gave in the garden, not flushed yet (many()), without the lock of a request.
 * Without persistence (a unit test), the name made a slug.
 */
trait FreeSlug
{
    /**
     * @param \Closure(): list<string> $taken the slugs of its kind in the database, in its garden or in the whole site
     *                                        for a garden (none in $garden then)
     */
    private static function freeSlug(mixed $name, \Closure $taken, ?Garden $garden = null): string
    {
        if (!\is_string($name)) {
            throw new \LogicException('A slug is made from a name.');
        }

        $base = (new AsciiSlugger('en'))->slug($name)->lower()->toString();
        if (!Configuration::instance()->isPersistenceEnabled()) {
            return $base;
        }

        $givenInGardens = self::given();
        $given = null !== $garden ? $givenInGardens[$garden] ?? [] : [];
        $slug = Slugs::firstFree($base, [...$taken(), ...$given]);
        if (null !== $garden) {
            $givenInGardens[$garden] = [...$given, $slug];
        }

        return $slug;
    }

    /**
     * @return \WeakMap<Garden, list<string>> the slugs given, by garden, flushed or not
     */
    private static function given(): \WeakMap
    {
        /** @var ?\WeakMap<Garden, list<string>> $given */
        static $given = null;
        if (null === $given) {
            /** @var \WeakMap<Garden, list<string>> $given */
            $given = new \WeakMap();
        }

        return $given;
    }

    /**
     * The garden of the attributes, made first when it is a factory or a lazy value: the slug is free in it.
     */
    private static function gardenOf(mixed $garden): Garden
    {
        $garden = $garden instanceof LazyValue ? $garden() : $garden;
        $garden = $garden instanceof Factory ? $garden->create() : $garden;

        return $garden instanceof Garden ? $garden : throw new \LogicException('A garden is needed for its slug.');
    }
}
