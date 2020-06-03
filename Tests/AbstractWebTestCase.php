<?php

/*
 * This file is part of the Klipper package.
 *
 * (c) François Pluchino <francois.pluchino@klipper.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Klipper\Bundle\FunctionalTestBundle\Tests;

use Doctrine\Common\Cache\ClearableCache;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Common\DataFixtures\Executor\AbstractExecutor;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\Common\DataFixtures\Loader;
use Doctrine\Common\DataFixtures\ProxyReferenceRepository;
use Doctrine\Common\DataFixtures\Purger\MongoDBPurger;
use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use Doctrine\Common\DataFixtures\Purger\PHPCRPurger;
use Doctrine\Persistence\Mapping\AbstractClassMetadataFactory;
use Doctrine\Persistence\Mapping\ClassMetadata;
use Doctrine\Persistence\ObjectManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\PDOSqlite\Driver as SqliteDriver;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Klipper\Bundle\FunctionalTestBundle\Tests\Backup\BackupInterface;
use Klipper\Bundle\FunctionalTestBundle\Tests\Backup\MysqlBackup;
use Klipper\Bundle\FunctionalTestBundle\Tests\Backup\PgsqlBackup;
use Symfony\Bridge\Doctrine\DataFixtures\ContainerAwareLoader;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase as BaseWebTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Abstract Web test case for klipper platform.
 *
 * @author François Pluchino <francois.pluchino@klipper.dev>
 */
abstract class AbstractWebTestCase extends BaseWebTestCase
{
    protected static ?KernelInterface $systemKernel = null;

    protected static bool $dbReady = false;

    protected bool $systemBooted = false;

    protected function tearDown(): void
    {
        static::ensureSystemKernelShutdown();
        parent::tearDown();
        $this->systemBooted = false;
    }

    /**
     * Get the container service.
     */
    protected function getContainer(): ContainerInterface
    {
        if (null === static::$systemKernel || !$this->systemBooted) {
            static::bootSystemKernel();
            $this->systemBooted = true;
        }

        return static::$systemKernel->getContainer();
    }

    /**
     * Creates an instance of a lightweight Http client.
     *
     * If $authentication is set to 'true' it will use the content of
     * 'klipper_functional_test.authentication' to log in.
     *
     * $params can be used to pass headers to the client, note that they have
     * to follow the naming format used in $_SERVER.
     * Example: 'HTTP_X_REQUESTED_WITH' instead of 'X-Requested-With'
     *
     * @param array|bool|string $authentication The authentication
     * @param array             $params         The client parameters
     */
    protected function createAuthClient(bool $authentication = false, array $params = []): KernelBrowser
    {
        if ($authentication) {
            if (true === $authentication) {
                $authentication = [
                    'username' => $this->getContainer()
                        ->getParameter('klipper_functional_test.authentication.username'),
                ];
            } elseif (\is_string($authentication)) {
                $authentication = [
                    'username' => $authentication,
                ];
            }

            if (!isset($authentication['password'])) {
                $authentication['password'] = $this->getContainer()
                    ->getParameter('klipper_functional_test.authentication.password')
                ;
            }

            $params = array_merge($params, [
                'PHP_AUTH_USER' => $authentication['username'],
                'PHP_AUTH_PW' => $authentication['password'],
            ]);
        }

        return static::createClient(['environment' => 'test'], $params);
    }

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
     * @param array  $classNames   List of fully qualified class names of fixtures to load
     * @param string $omName       The name of object manager to use
     * @param string $registryName The service id of manager registry to use
     * @param int    $purgeMode    Sets the ORM purge mode
     *
     * @throws
     */
    protected function loadFixtures(array $classNames, ?string $omName = null, string $registryName = 'doctrine', ?int $purgeMode = null): ?AbstractExecutor
    {
        $container = $this->getContainer();
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
        $cacheDriver = $metadataFactory instanceof  AbstractClassMetadataFactory ? $metadataFactory->getCacheDriver() : null;
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

            $this->initDatabase();
            $params = $this->getConnectionParams();
            $metadatas = $om->getMetadataFactory()->getAllMetadata();
            usort($metadatas, static function ($a, $b) {
                return strcmp($a->name, $b->name);
            });

            $schemaTool = new SchemaTool($om);
            $schemaTool->dropSchema($metadatas);

            $backup = static::getBackup($container, $params, $metadatas, $classNames);

            if ($backup) {
                $backupFile = $backup->getFile();
                $container->get('filesystem')->mkdir(\dirname($backupFile));

                if ($backup->exists() && $this->isBackupUpToDate($classNames, $backupFile)) {
                    $om->flush();
                    $om->clear();

                    $this->preFixtureRestore($om, $referenceRepository);

                    $cmd = $backup->getRestoreCommand();
                    $this->runDatabaseCommand($cmd, $params);

                    /** @var ORMExecutor $executor */
                    $executor = new $executorClass($om);
                    $executor->setReferenceRepository($referenceRepository);
                    $referenceRepository->load($backupFile);

                    $this->postFixtureRestore();

                    return $executor;
                }
            }

            if (!empty($metadatas)) {
                $schemaTool->createSchema($metadatas);
            }

            $this->postFixtureSetup();

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

        $loader = $this->getFixtureLoader($classNames);
        $fixtures = $loader->getFixtures();
        $defaultAuth = $container->getParameter('klipper_functional_test.authentication');

        foreach ($fixtures as $fixture) {
            if ($fixture instanceof DefaultAuthenticationInterface) {
                $fixture->setDefaultAuthentication($defaultAuth['username'], $defaultAuth['password']);
            }
        }

        $executor->execute($fixtures, true);

        if (null !== $backup) {
            $backupFile = $backup->getFile();
            $om = $executor->getObjectManager();
            $this->preReferenceSave($om, $executor, $backupFile);

            $referenceRepository->save($backupFile);

            $params = $this->getConnectionParams();
            $cmd = $backup->getBackupCommand();
            $this->runDatabaseCommand($cmd, $params);

            $this->postReferenceSave($om, $executor, $backupFile);
        }

        return $executor;
    }

    /**
     * Callback function to be executed after Schema creation.
     * Use this to execute acl:init or other things necessary.
     */
    protected function postFixtureSetup(): void
    {
    }

    /**
     * Callback function to be executed before Schema restore.
     *
     * @param ObjectManager            $manager             The object manager
     * @param ProxyReferenceRepository $referenceRepository The reference repository
     */
    protected function preFixtureRestore(ObjectManager $manager, ProxyReferenceRepository $referenceRepository): void
    {
    }

    /**
     * Callback function to be executed after Schema restore.
     */
    protected function postFixtureRestore(): void
    {
    }

    /**
     * Callback function to be executed before save of references.
     *
     * @param ObjectManager    $manager        The object manager
     * @param AbstractExecutor $executor       Executor of the data fixtures
     * @param string           $backupFilePath Path of file used to backup the references of the data fixtures
     */
    protected function preReferenceSave(ObjectManager $manager, AbstractExecutor $executor, $backupFilePath): void
    {
    }

    /**
     * Callback function to be executed after save of references.
     *
     * @param ObjectManager    $manager        The object manager
     * @param AbstractExecutor $executor       Executor of the data fixtures
     * @param string           $backupFilePath Path of file used to backup the references of the data fixtures
     */
    protected function postReferenceSave(ObjectManager $manager, AbstractExecutor $executor, $backupFilePath): void
    {
    }

    /**
     * Run the process database command.
     *
     * @param string $cmd    The command
     * @param array  $params The database connection parameters
     */
    protected function runDatabaseCommand(string $cmd, array $params): Process
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
    protected function initDatabase(): void
    {
        $firstChannel = getenv('ENV_TEST_IS_FIRST_ON_CHANNEL');

        if ((!self::$dbReady && false === $firstChannel) || false !== $firstChannel) {
            $this->createDatabase();
            $this->loadDatabaseExtensions();
            self::$dbReady = false === getenv('ENV_TEST_CHANNEL_READABLE');
        }
    }

    /**
     * Create the database.
     *
     * @throws
     */
    protected function createDatabase(): void
    {
        $params = $this->getConnectionParams();
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
    protected function loadDatabaseExtensions(): void
    {
        $params = $this->getConnectionParams();
        $tmpConnection = DriverManager::getConnection($params);
        $driver = str_replace('pdo_', '', $params['driver']);
        $extensions = $this->getContainer()->getParameter('klipper_functional_test.db_extensions');

        if ('pgsql' === $driver) {
            $this->loadPgsqlDatabaseExtensions($tmpConnection, $extensions[$driver] ?? []);
        }

        $tmpConnection->close();
    }

    /**
     * Load the database extensions for PostgreSQL.
     *
     * @param string[] $extensions
     */
    protected function loadPgsqlDatabaseExtensions(Connection $connection, array $extensions): void
    {
        foreach ($extensions as $extension) {
            try {
                $connection->prepare('CREATE EXTENSION '.$extension)->execute();
            } catch (\Exception $e) {
                // nothing
            }
        }
    }

    /**
     * Get the parameters of database connection.
     */
    protected function getConnectionParams(): array
    {
        /** @var EntityManagerInterface $em */
        $em = $this->getContainer()->get('doctrine')->getManager();
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
     * @param array  $classNames The fixture classnames to check
     * @param string $backupFile The fixture backup database file path
     *
     * @throws
     *
     * @return bool TRUE if the backup was made since the modifications to the
     *              fixtures; FALSE otherwise
     */
    protected function isBackupUpToDate(array $classNames, string $backupFile): bool
    {
        $backupLastModifiedDateTime = new \DateTime();
        $backupLastModifiedDateTime->setTimestamp(filemtime($backupFile));

        /** @var \Symfony\Bridge\Doctrine\DataFixtures\ContainerAwareLoader $loader */
        $loader = $this->getFixtureLoader($classNames);

        // Use loader in order to fetch all the dependencies fixtures.
        foreach ($loader->getFixtures() as $className) {
            $fixtureLastModifiedDateTime = $this->getFixtureLastModified($className);

            if ($backupLastModifiedDateTime < $fixtureLastModifiedDateTime) {
                return false;
            }
        }

        return true;
    }

    /**
     * Retrieve Doctrine DataFixtures loader.
     *
     * @param string[] $classNames
     *
     * @return ContainerAwareLoader|Loader
     */
    protected function getFixtureLoader(array $classNames)
    {
        $loaderClass = class_exists(ContainerAwareLoader::class)
            ? ContainerAwareLoader::class
            : Loader::class;

        $loader = new $loaderClass($this->getContainer());

        foreach ($classNames as $className) {
            $this->loadFixtureClass($loader, $className);
        }

        return $loader;
    }

    /**
     * Load a data fixture class.
     *
     * @param ContainerAwareLoader|Loader $loader
     */
    protected function loadFixtureClass(Loader $loader, string $className): void
    {
        $fixture = new $className();

        if ($loader->hasFixture($fixture)) {
            unset($fixture);

            return;
        }

        $loader->addFixture($fixture);

        if ($fixture instanceof DependentFixtureInterface) {
            foreach ($fixture->getDependencies() as $dependency) {
                $this->loadFixtureClass($loader, $dependency);
            }
        }
    }

    /**
     * This function finds the time when the data blocks of a class definition
     * file were being written to, that is, the time when the content of the
     * file was changed.
     *
     * @param object|string $class The fully qualified class name of the fixture class to
     *                             check modification date on
     *
     * @throws
     */
    protected function getFixtureLastModified($class): ?\DateTime
    {
        $lastModifiedDateTime = null;

        $reflClass = new \ReflectionClass($class);
        $classFileName = $reflClass->getFileName();

        if (file_exists($classFileName)) {
            $lastModifiedDateTime = new \DateTime();
            $lastModifiedDateTime->setTimestamp(filemtime($classFileName));
        }

        return $lastModifiedDateTime;
    }

    /**
     * @param ClassMetadata[] $metadatas
     * @param string[]        $classNames
     */
    protected static function getBackup(ContainerInterface $container, array $params, array $metadatas, array $classNames): ?BackupInterface
    {
        $selectedBackup = null;

        if (class_exists(Process::class) && $container->getParameter('klipper_functional_test.cache_db')) {
            $hash = md5(serialize($metadatas).serialize($classNames));

            foreach (static::getBackupClasses() as $class) {
                if (\call_user_func($class.'::supports', $params)) {
                    $selectedBackup = new $class($container, $params, $hash);

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
        $kernel->getContainer()->get('translator')->setLocale(\Locale::getDefault());

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
     * Boots the Kernel for this test.
     *
     * @return KernelInterface A KernelInterface instance
     */
    protected static function bootSystemKernel(array $options = []): KernelInterface
    {
        static::ensureSystemKernelShutdown();

        static::$systemKernel = static::createKernel($options);
        static::$systemKernel->boot();
        $container = static::$systemKernel->getContainer();
        $container->get('translator')->setLocale(\Locale::getDefault());

        if ($container->hasParameter('klipper_functional_test.manifest.file')) {
            $assetFile = $container->getParameter('klipper_functional_test.manifest.file');

            if (!file_exists($assetFile)) {
                $container->get('filesystem')->dumpFile($assetFile, '{}');
            }
        }

        return static::$systemKernel;
    }

    /**
     * Shuts the system kernel down if it was used in the test.
     */
    protected static function ensureSystemKernelShutdown(): void
    {
        if (null !== static::$systemKernel) {
            $container = static::$systemKernel->getContainer();

            if (null !== $container) {
                /** @var EntityManagerInterface $em */
                foreach ($container->get('doctrine')->getManagers() as $em) {
                    $em->close();
                }
            }

            static::$systemKernel->shutdown();

            if ($container instanceof ResetInterface) {
                $container->reset();
            }
        }
    }
}
