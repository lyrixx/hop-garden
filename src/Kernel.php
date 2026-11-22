<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel implements CompilerPassInterface
{
    use MicroKernelTrait;

    /**
     * debug:translation checks the templates of every Twig path, EasyAdmin's too, which translate in a domain chosen at
     * runtime: it would report their messages as missing. It checks ours only (tests/Integration/Translation/).
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('console.command.translation_debug')) {
            return;
        }

        $definition = $container->getDefinition('console.command.translation_debug');
        $vendorDir = $this->getProjectDir() . '/vendor/';
        /** @var list<string> $paths */
        $paths = $definition->getArgument(6);
        $definition->replaceArgument(6, array_values(array_filter($paths, static fn (string $path): bool => !str_starts_with($path, $vendorDir))));
    }
}
