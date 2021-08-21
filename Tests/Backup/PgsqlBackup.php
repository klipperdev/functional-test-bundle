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
 * Backup for PostgreSQL.
 *
 * @author François Pluchino <francois.pluchino@klipper.dev>
 */
class PgsqlBackup extends AbstractBackup
{
    public static function supports(array $params): bool
    {
        return isset($params['driver']) && 'pgsql' === str_replace('pdo_', '', $params['driver']);
    }

    public function getFile(): string
    {
        return $this->cacheDir.'/db_dump/test_'.$this->hash.'.pgdmp';
    }

    public function getRestoreCommand(): string
    {
        return sprintf(
            'pg_restore -h %s -U %s -d %s -F c %s',
            escapeshellarg($this->params['host']),
            escapeshellarg($this->params['user']),
            escapeshellarg($this->params['dbname']),
            escapeshellarg($this->getFile())
        );
    }

    public function getBackupCommand(): string
    {
        return sprintf(
            'pg_dump -h %s -U %s -f %s -F c -b -v %s',
            escapeshellarg($this->params['host']),
            escapeshellarg($this->params['user']),
            escapeshellarg($this->getFile()),
            escapeshellarg($this->params['dbname'])
        );
    }
}
