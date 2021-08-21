<?php

/*
 * This file is part of the Klipper package.
 *
 * (c) François Pluchino <francois.pluchino@klipper.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Klipper\Bundle\FunctionalTestBundle\Test;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * File util class for tests.
 *
 * @author François Pluchino <francois.pluchino@klipper.dev>
 */
final class FileUtil
{
    /**
     * Load the files in content directory.
     *
     * @param ContainerInterface $container The container service
     * @param string[]           $files     The names of uploaded files
     *
     * @return string[]
     */
    public static function loadFiles(ContainerInterface $container, array $files): array
    {
        $copyFiles = [];
        $fs = new Filesystem();
        $localBase = static::getLocalBase($container).'/';
        $originalFilename = __DIR__.'/Fixtures/Resources/assets/file.jpg';

        foreach ($files as $filename) {
            $fs->copy($originalFilename, $localBase.$filename);
            $copyFiles[] = $localBase.$filename;
        }

        return $copyFiles;
    }

    public static function getLocalBase(ContainerInterface $container): string
    {
        return $container->hasParameter('content_local_base')
            ? $container->getParameter('content_local_base')
            : $container->getParameter('kernel.cache_dir').'/content_local';
    }
}
