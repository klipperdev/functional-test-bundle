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

use Doctrine\Common\Cache\ClearableCache;
use Doctrine\Common\DataFixtures\Executor\AbstractExecutor;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Common\DataFixtures\Loader;
use Doctrine\Common\DataFixtures\ProxyReferenceRepository;
use Doctrine\Common\DataFixtures\Purger\MongoDBPurger;
use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use Doctrine\Common\DataFixtures\Purger\PHPCRPurger;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\Mapping\AbstractClassMetadataFactory;
use Doctrine\Persistence\Mapping\ClassMetadata;
use Doctrine\Persistence\ObjectManager;
use Klipper\Bundle\FunctionalTestBundle\Test\Backup\BackupInterface;
use Klipper\Bundle\FunctionalTestBundle\Test\Backup\MysqlBackup;
use Klipper\Bundle\FunctionalTestBundle\Test\Backup\PgsqlBackup;
use Klipper\Bundle\FunctionalTestBundle\Test\DataFixtures\FixtureApplicationableInterface;
use Klipper\Bundle\FunctionalTestBundle\Test\DataFixtures\FixtureDefaultAuthenticationInterface;
use PDO\SQLite\Driver as SqliteDriver;
use Symfony\Bridge\Doctrine\DataFixtures\ContainerAwareLoader;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * @author François Pluchino <francois.pluchino@klipper.dev>
 */
trait DatabaseKitTestsTrait
{
    protected static bool $dbReady = false;

    /**
     * Set the database to the provided fixtures.
     *
     * Drops the current database and then loads fixtures using the specified
     * classes. The parameter is a list of fully qualified class names of
     * classes that implement Doctrine\Common\DataFixtures\FixtureInterface
     * so that they can be loaded by the DataFixtures Loader::addFixture
     *
     * When using SQLite this method will automatically make a copy of the
     * loaded schema and fixtures which will be restored automatically in
     * case the same fixture classes are to be loaded again. Caveat: changes
     * to references and/or identities may go undetected.
     *
     * Depends on the doctrine data-fixtures library being available in the
     * class path.
     *
     * @param FixtureInterface[] $fixtures     List of fixtures to load
     * @param string             $omName       The name of object manager to use
     * @param string             $registryName The service id of manager registry to use
     * @param int                $purgeMode    Sets the ORM purge mode
     *
     * @throws
     */
    protected static function loadFixtures(array $fixtures, ?string $omName = null, string $registryName = 'doctrine', ?int $purgeMode = null): ?AbstractExecutor
    {
        $container = static::getContainer();
        $registry = $container->get($registryName);

        if ($registry instanceof ManagerRegistry) {
            $om = $registry->getManager($omName);
            $type = $registry->getName();
        } else {
            $om = $registry->getEntityManager($omName);
            $type = 'ORM';
        }

        $container->get('cache.app_clearer')->clear('');

        $executorClass = 'PHPCR' === $type && class_exists('Doctrine\Bundle\PHPCRBundle\DataFixtures\PHPCRExecutor')
            ? 'Doctrine\Bundle\PHPCRBundle\DataFixtures\PHPCRExecutor'
            : 'Doctrine\\Common\\DataFixtures\\Executor\\'.$type.'Executor';
        $referenceRepository = new ProxyReferenceRepository($om);
        $metadataFactory = $om->getMetadataFactory();
        $cacheDriver = $metadataFactory instanceof AbstractClassMetadataFactory ? $metadataFactory->getCacheDriver() : null;
        $backup = null;

        if ($cacheDriver instanceof ClearableCache) {
            $cacheDriver->deleteAll();
        }

        if ('ORM' === $type) {
            /** @var EntityManagerInterface $om */
            $connection = $om->getConnection();
            if ($connection->getDriver() instanceof SqliteDriver) {
                static::markTestSkipped('The Sqlite database driver is not supported for the ');
            }

            static::initDatabase();
            $params = static::getConnectionParams();
            $metadatas = $om->getMetadataFactory()->getAllMetadata();
            usort($metadatas, static function ($a, $b) {
                return strcmp($a->name, $b->name);
            });

            $schemaTool = new SchemaTool($om);
            $schemaTool->dropSchema($metadatas);

            $backup = static::getBackup($container, $params, $metadatas, $fixtures);

            if ($backup) {
                $backupFile = $backup->getFile();
                $fs = new Filesystem();
                $fs->mkdir(\dirname($backupFile));

                if ($backup->exists() && static::isBackupUpToDate($fixtures, $backupFile)) {
                    $om->flush();
                    $om->clear();

                    static::preFixtureRestore($om, $referenceRepository);

                    $cmd = $backup->getRestoreCommand();
                    static::runDatabaseCommand($cmd, $params);

                    /** @var ORMExecutor $executor */
                    $executor = new $executorClass($om);
                    $executor->setReferenceRepository($referenceRepository);
                    $referenceRepository->load($backupFile);

                    static::postFixtureRestore();

                    return $executor;
                }
            }

            if (!empty($metadatas)) {
                $schemaTool->createSchema($metadatas);
            }

            static::postFixtureSetup();

            /** @var ORMExecutor $executor */
            $executor = new $executorClass($om);
            $executor->setReferenceRepository($referenceRepository);
        } else {
            $executor = null;
        }

        if (null === $executor) {
            $purgerClass = 'Doctrine\\Common\\DataFixtures\\Purger\\'.$type.'Purger';
            if ('PHPCR' === $type) {
                /** @var PHPCRPurger $purger */
                $purger = new $purgerClass($om);
                $initManager = $container->has('doctrine_phpcr.initializer_manager')
                    ? $container->get('doctrine_phpcr.initializer_manager')
                    : null;

                /** @var AbstractExecutor $executor */
                $executor = new $executorClass($om, $purger, $initManager);
            } else {
                /** @var MongoDBPurger|ORMPurger $purger */
                $purger = new $purgerClass();
                if (null !== $purgeMode) {
                    $purger->setPurgeMode($purgeMode);
                }

                /** @var AbstractExecutor $executor */
                $executor = new $executorClass($om, $purger);
            }

            $executor->setReferenceRepository($referenceRepository);
            $executor->purge();
        }

        $loader = static::getFixtureLoader($fixtures);
        $fixtures = $loader->getFixtures();
        $defaultAuth = $container->getParameter('klipper_functional_test.authentication');
        $application = null;

        foreach ($fixtures as $fixture) {
            if ($fixture instanceof FixtureDefaultAuthenticationInterface) {
                $fixture->setDefaultAuthentication($defaultAuth['username'], $defaultAuth['password']);
            }

            if ($fixture instanceof FixtureApplicationableInterface) {
                if (null === $application) {
                    $application = new Application(static::$kernel);
                }

                $fixture->setApplication($application);
            }
        }

        $executor->execute($fixtures, true);

        if (null !== $backup) {
            $backupFile = $backup->getFile();
            $om = $executor->getObjectManager();
            static::preReferenceSave($om, $executor, $backupFile);

            $referenceRepository->save($backupFile);

            $params = static::getConnectionParams();
            $cmd = $backup->getBackupCommand();
            static::runDatabaseCommand($cmd, $params);

            static::postReferenceSave($om, $executor, $backupFile);
        }

        return $executor;
    }

    /**
     * Callback function to be executed after Schema creation.
     * Use this to execute acl:init or other things necessary.
     */
    protected static function postFixtureSetup(): void
    {
    }

    /**
     * Callback function to be executed before Schema restore.
     *
     * @param ObjectManager            $manager             The object manager
     * @param ProxyReferenceRepository $referenceRepository The reference repository
     */
    protected static function preFixtureRestore(ObjectManager $manager, ProxyReferenceRepository $referenceRepository): void
    {
    }

    /**
     * Callback function to be executed after Schema restore.
     */
    protected static function postFixtureRestore(): void
    {
    }

    /**
     * Callback function to be executed before save of references.
     *
     * @param ObjectManager    $manager        The object manager
     * @param AbstractExecutor $executor       Executor of the data fixtures
     * @param string           $backupFilePath Path of file used to backup the references of the data fixtures
     */
    protected static function preReferenceSave(ObjectManager $manager, AbstractExecutor $executor, string $backupFilePath): void
    {
    }

    /**
     * Callback function to be executed after save of references.
     *
     * @param ObjectManager    $manager        The object manager
     * @param AbstractExecutor $executor       Executor of the data fixtures
     * @param string           $backupFilePath Path of file used to backup the references of the data fixtures
     */
    protected static function postReferenceSave(ObjectManager $manager, AbstractExecutor $executor, string $backupFilePath): void
    {
    }

    /**
     * Run the process database command.
     *
     * @param string $cmd    The command
     * @param array  $params The database connection parameters
     */
    protected static function runDatabaseCommand(string $cmd, array $params): Process
    {
        $process = Process::fromShellCommandline($cmd, null, array_merge($_SERVER, $_ENV, [
            'PGPASSWORD' => $params['password'],
        ]));
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return $process;
    }

    /**
     * Init the database.
     */
    protected static function initDatabase(): void
    {
        $firstChannel = getenv('ENV_TEST_IS_FIRST_ON_CHANNEL');

        if ((!self::$dbReady && false === $firstChannel) || false !== $firstChannel) {
            static::createDatabase();
            static::loadDatabaseExtensions();
            self::$dbReady = false === getenv('ENV_TEST_CHANNEL_READABLE');
        }
    }

    /**
     * Create the database.
     *
     * @throws
     */
    protected static function createDatabase(): void
    {
        $params = static::getConnectionParams();
        $dbName = $params['dbname'];
        unset($params['dbname'], $params['path'], $params['url']);
        $tmpConnection = DriverManager::getConnection($params);

        try {
            $dbName = $tmpConnection->getDatabasePlatform()->quoteSingleIdentifier($dbName);
            $tmpConnection->getSchemaManager()->createDatabase($dbName);
        } catch (\Exception $e) {
            // nothing
        }

        $tmpConnection->close();
    }

    /**
     * Load the database extensions.
     *
     * @throws
     */
    protected static function loadDatabaseExtensions(): void
    {
        $params = static::getConnectionParams();
        $tmpConnection = DriverManager::getConnection($params);
        $driver = str_replace('pdo_', '', $params['driver']);
        $extensions = static::getContainer()->getParameter('klipper_functional_test.db_extensions');

        if ('pgsql' === $driver) {
            static::loadPgsqlDatabaseExtensions($tmpConnection, $extensions[$driver] ?? []);
        }

        $tmpConnection->close();
    }

    /**
     * Load the database extensions for PostgreSQL.
     *
     * @param string[] $extensions
     *
     * @throws
     */
    protected static function loadPgsqlDatabaseExtensions(Connection $connection, array $extensions): void
    {
        foreach ($extensions as $extension) {
            try {
                $connection->prepare('CREATE EXTENSION '.$extension)->executeQuery();
            } catch (\Exception $e) {
                // nothing
            }
        }
    }

    /**
     * Get the parameters of database connection.
     */
    protected static function getConnectionParams(): array
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $connection = $em->getConnection();
        $params = $connection->getParams();
        $params = $params['master'] ?? $params;

        if (!isset($params['dbname'])) {
            throw new \InvalidArgumentException("Connection does not contain a 'dbname' parameter and cannot be created.");
        }

        return $params;
    }

    /**
     * Determine if the Fixtures that define a database backup have been
     * modified since the backup was made.
     *
     * @param FixtureInterface[] $fixtures   The fixtures to check
     * @param string             $backupFile The fixture backup database file path
     *
     * @throws
     *
     * @return bool TRUE if the backup was made since the modifications to the
     *              fixtures; FALSE otherwise
     */
    protected static function isBackupUpToDate(array $fixtures, string $backupFile): bool
    {
        $backupLastModifiedDateTime = new \DateTime();
        $backupLastModifiedDateTime->setTimestamp(filemtime($backupFile));

        $loader = static::getFixtureLoader($fixtures);

        // Use loader in order to fetch all the dependencies fixtures.
        foreach ($loader->getFixtures() as $fixture) {
            $fixtureLastModifiedDateTime = static::getFixtureLastModified($fixture);

            if ($backupLastModifiedDateTime < $fixtureLastModifiedDateTime) {
                return false;
            }
        }

        return true;
    }

    /**
     * Retrieve Doctrine DataFixtures loader.
     *
     * @param FixtureInterface[] $fixtures
     *
     * @return ContainerAwareLoader|Loader
     */
    protected static function getFixtureLoader(array $fixtures): Loader
    {
        $loaderClass = class_exists(ContainerAwareLoader::class)
            ? ContainerAwareLoader::class
            : Loader::class;

        $loader = new $loaderClass(static::getContainer());

        foreach ($fixtures as $fixture) {
            if (!$loader->hasFixture($fixture)) {
                $loader->addFixture($fixture);
            }
        }

        return $loader;
    }

    /**
     * This function finds the time when the data blocks of a class definition
     * file were being written to, that is, the time when the content of the
     * file was changed.
     *
     * @param FixtureInterface $fixture The fixture to check modification date on
     *
     * @throws
     */
    protected static function getFixtureLastModified(FixtureInterface $fixture): ?\DateTime
    {
        $lastModifiedDateTime = null;

        $reflClass = new \ReflectionClass($fixture);
        $classFileName = $reflClass->getFileName();

        if (file_exists($classFileName)) {
            $lastModifiedDateTime = new \DateTime();
            $lastModifiedDateTime->setTimestamp(filemtime($classFileName));
        }

        return $lastModifiedDateTime;
    }

    /**
     * @param ClassMetadata[]    $metadatas
     * @param FixtureInterface[] $fixtures
     */
    protected static function getBackup(ContainerInterface $container, array $params, array $metadatas, array $fixtures): ?BackupInterface
    {
        $selectedBackup = null;

        if (class_exists(Process::class) && $container->getParameter('klipper_functional_test.cache_db')) {
            $hash = md5(serialize($metadatas).serialize(static::getFixtureClassnames($fixtures)));
            $cacheDir = $container->getParameter('kernel.cache_dir');

            foreach (static::getBackupClasses() as $class) {
                if (\call_user_func($class.'::supports', $params)) {
                    $selectedBackup = new $class($cacheDir, $params, $hash);

                    break;
                }
            }
        }

        return $selectedBackup;
    }

    protected static function getBackupClasses(): array
    {
        return [
            PgsqlBackup::class,
            MysqlBackup::class,
        ];
    }

    protected static function bootKernel(array $options = []): KernelInterface
    {
        $kernel = parent::bootKernel($options);
        $container = $kernel->getContainer();
        $container->get('translator')->setLocale(\Locale::getDefault());
        $fs = new Filesystem();

        if ($container->hasParameter('klipper_functional_test.manifest.file')) {
            $assetFile = $container->getParameter('klipper_functional_test.manifest.file');

            if (!file_exists($assetFile)) {
                $fs->dumpFile($assetFile, '{}');
            }
        }

        return $kernel;
    }

    protected static function ensureKernelShutdown(): void
    {
        if (null !== static::$kernel && null !== ($container = static::$kernel->getContainer())) {
            /** @var EntityManagerInterface $em */
            foreach ($container->get('doctrine')->getManagers() as $em) {
                $em->close();
            }
        }

        parent::ensureKernelShutdown();
    }

    /**
     * @param FixtureInterface[] $fixtures
     *
     * @return string[]
     */
    protected static function getFixtureClassnames(array $fixtures): array
    {
        $classnames = array_map(static function (FixtureInterface $fixture) {
            return \get_class($fixture);
        }, $fixtures);

        sort($classnames);

        return $classnames;
    }
}
