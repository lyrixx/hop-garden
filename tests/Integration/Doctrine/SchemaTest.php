<?php

namespace App\Tests\Integration\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaValidator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The test database is built by the migrations: a mapping change without its migration fails here.
 */
final class SchemaTest extends KernelTestCase
{
    public function testTheMigrationsMatchTheMapping(): void
    {
        self::bootKernel();
        $validator = new SchemaValidator(self::getContainer()->get(EntityManagerInterface::class));

        self::assertSame([], $validator->validateMapping());
        // The table of the migrations themselves is filtered by the console commands only
        self::assertSame([], array_values(array_filter($validator->getUpdateSchemaList(), static fn (string $sql): bool => !str_contains($sql, 'doctrine_migration_versions'))));
    }
}
