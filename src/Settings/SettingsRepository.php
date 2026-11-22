<?php

namespace App\Settings;

use App\Settings\Entity\Settings;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Settings>
 */
class SettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Settings::class);
    }

    /**
     * Null until a first setting is chosen.
     */
    public function findTheSettings(): ?Settings
    {
        return $this->find(Settings::ID);
    }

    public function add(Settings $settings): void
    {
        $this->getEntityManager()->persist($settings);
    }
}
