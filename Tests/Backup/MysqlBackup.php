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
 * Backup for Mysql.
 *
 * @author François Pluchino <francois.pluchino@klipper.dev>
 */
class MysqlBackup extends AbstractBackup
{
    public static function supports(array $params): bool
    {
        return isset($params['driver']) && 'mysql' === str_replace('pdo_', '', $params['driver']);
    }

    public function getFile(): string
    {
        return $this->container->getParameter('kernel.cache_dir').'/db_dump/test_'.$this->hash.'.sql';
    }

    public function getRestoreCommand(): string
    {
        return sprintf(
            'mysql -h %s -P %s -u %s -p%s -D %s < %s',
            escapeshellarg($this->params['host']),
            escapeshellarg($this->params['port']),
            escapeshellarg($this->params['user']),
            escapeshellarg($this->params['password']),
            escapeshellarg($this->params['dbname']),
            escapeshellarg($this->getFile())
        );
    }

    public function getBackupCommand(): string
    {
        return sprintf(
            'mysqldump -h %s -P %s -u %s -p%s -B %s > %s',
            escapeshellarg($this->params['host']),
            escapeshellarg($this->params['port']),
            escapeshellarg($this->params['user']),
            escapeshellarg($this->params['password']),
            escapeshellarg($this->params['dbname']),
            escapeshellarg($this->getFile())
        );
    }
}
