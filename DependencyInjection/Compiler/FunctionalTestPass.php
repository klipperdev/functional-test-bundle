<?php

/*
 * This file is part of the Klipper package.
 *
 * (c) François Pluchino <francois.pluchino@klipper.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Klipper\Bundle\FunctionalTestBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @author François Pluchino <francois.pluchino@klipper.dev>
 */
class FunctionalTestPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if ('test' !== $container->getParameter('kernel.environment')) {
            return;
        }

        if ($container->hasDefinition('stof_doctrine_extensions.listener.blameable')) {
            $def = $container->getDefinition('stof_doctrine_extensions.listener.blameable');
            $def->setPublic(true);
        }

        if ($container->hasDefinition('assets._version__default')) {
            $assetDef = $container->getDefinition('assets._version__default');
            $container->setParameter('klipper_functional_test.manifest.file', array_values($assetDef->getArguments())[0]);
        }
    }
}
