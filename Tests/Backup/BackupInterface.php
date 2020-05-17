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
 * Interface of backup.
 *
 * @author François Pluchino <francois.pluchino@klipper.dev>
 */
interface BackupInterface
{
    /**
     * @param array $params The database parameters
     */
    public static function supports(array $params): bool;

    public function getFile(): string;

    public function exists(): bool;

    public function getRestoreCommand(): string;

    public function getBackupCommand(): string;
}
