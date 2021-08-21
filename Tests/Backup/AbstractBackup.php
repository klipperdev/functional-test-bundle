<?php

/*
 * This file is part of the Klipper package.
 *
 * (c) François Pluchino <francois.pluchino@klipper.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Klipper\Bundle\FunctionalTestBundle\Tests\Backup;

/**
 * Abstract class of backup.
 *
 * @author François Pluchino <francois.pluchino@klipper.dev>
 */
abstract class AbstractBackup implements BackupInterface
{
    protected string $cacheDir;

    protected array $params;

    protected string $hash;

    public function __construct(string $cacheDir, array $params, string $hash)
    {
        $this->cacheDir = $cacheDir;
        $this->params = $params;
        $this->hash = $hash;
    }

    public function exists(): bool
    {
        $backupFile = $this->getFile();

        return file_exists($backupFile) && file_exists($backupFile.'.ser');
    }
}
