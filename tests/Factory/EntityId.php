<?php

namespace App\Tests\Factory;

/**
 * Gives an entity the id Doctrine would give it, for a unit test without a database.
 */
final class EntityId
{
    /**
     * @template T of object
     *
     * @param T $entity
     *
     * @return T
     */
    public static function assign(object $entity, int $id): object
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $id);

        return $entity;
    }
}
