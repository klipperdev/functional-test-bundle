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

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ObjectRepository;
use Klipper\Bundle\FunctionalTestBundle\Test\Token\TestModelToken;
use Klipper\Component\DataLoader\Exception\ConsoleResourceException;
use Klipper\Component\DoctrineChoice\ChoiceManagerInterface;
use Klipper\Component\DoctrineChoice\Model\ChoiceInterface;
use Klipper\Component\DoctrineExtensions\Util\SqlFilterUtil;
use Klipper\Component\DoctrineExtra\Util\ClassUtils;
use Klipper\Component\Resource\Domain\DomainInterface;
use Klipper\Component\Resource\ResourceInterface;
use Klipper\Component\Resource\ResourceListInterface;
use Klipper\Component\Security\Event\SetCurrentOrganizationEvent;
use Klipper\Component\Security\Model\UserInterface;
use Klipper\Component\Security\Organizational\OrganizationalContextInterface;
use Klipper\Component\Security\Permission\PermissionManagerInterface;
use Klipper\Component\Security\Sharing\SharingManagerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * @author François Pluchino <francois.pluchino@klipper.dev>
 */
trait ModelKitTestsTrait
{
    /**
     * @var string[]
     */
    private static array $disabledFilters = [];

    private static ?string $originalLocale = null;

    /**
     * Set the organization in context.
     *
     * @param null|string $name The organization name
     */
    public static function injectOrganisation(?string $name): void
    {
        static::getContainer()->get('klipper_security_extra.organizational_context.helper')
            ->setCurrentOrganizationUser($name)
        ;
    }

    /**
     * Get the entity manager.
     */
    public static function getEntityManager(): EntityManager
    {
        return static::getContainer()->get('doctrine.orm.entity_manager');
    }

    /**
     * Get the object repository.
     *
     * @param string $class The class name
     *
     * @return EntityRepository|ObjectRepository
     */
    public static function getObjectRepository(string $class): ObjectRepository
    {
        return static::getResourceDomain($class)->getRepository();
    }

    /**
     * Get the authorization checker.
     */
    public static function getAuthorizationChecker(): AuthorizationCheckerInterface
    {
        return static::getContainer()->get('security.authorization_checker');
    }

    /**
     * Get the resource domain.
     *
     * @param string $class The class name
     */
    public static function getResourceDomain(string $class): DomainInterface
    {
        return static::getContainer()->get('klipper_resource.domain_manager')->get($class);
    }

    /**
     * Get the permission manager.
     */
    public static function getPermissionManager(): PermissionManagerInterface
    {
        return static::getContainer()->get('klipper_security.permission_manager');
    }

    /**
     * Get the sharing manager.
     */
    public static function getSharingManager(): SharingManagerInterface
    {
        return static::getContainer()->get('klipper_security.sharing_manager');
    }

    /**
     * Get the security organizational context.
     */
    public static function getOrganizationalContext(): OrganizationalContextInterface
    {
        return static::getContainer()->get('klipper_security.organizational_context');
    }

    public static function getChoiceManager(): ChoiceManagerInterface
    {
        return static::getContainer()->get('klipper_doctrine_choice.manager');
    }

    /**
     * Get the user instance by user identifier.
     *
     * @param null|string $userIdentifier The user identifier (null value to use the default authenticated user)
     */
    public static function getDefaultUser(): UserInterface
    {
        $defaultAuth = static::getContainer()->getParameter('klipper_functional_test.authentication');

        return static::getUserByIdentifier($defaultAuth['username']);
    }

    /**
     * Get the user instance by user identifier.
     *
     * @param string $userIdentifier The user identifier
     */
    public static function getUserByIdentifier(string $userIdentifier): UserInterface
    {
        $em = static::getEntityManager();
        $filters = SqlFilterUtil::disableFilters($em, [], true);
        $userRepo = $em->getRepository(UserInterface::class);
        $testUser = $userRepo->findOneBy([
            'username' => $userIdentifier,
        ]);

        if (null === $testUser) {
            throw new \InvalidArgumentException(sprintf('The "%s" user identifier does not exist', $userIdentifier));
        }

        SqlFilterUtil::enableFilters($em, $filters);

        return $testUser;
    }

    /**
     * Get the user of in token storage.
     *
     * @param bool $nullable Check if the user can me nullable
     */
    public static function getTokenUser(bool $nullable = false): UserInterface
    {
        /** @var null|TokenInterface $token */
        $token = static::getContainer()->get('security.token_storage')->getToken();
        static::assertNotNull($token);
        /** @var null|UserInterface $user */
        $user = $token->getUser();

        if (!$nullable) {
            static::assertNotNull($user);
        }

        return $user;
    }

    /**
     * Get the uploaded file instance with the filename.
     *
     * @param string $filename The filename
     */
    public static function getUploadedFile(string $filename): UploadedFile
    {
        $file = new File($filename);

        return new UploadedFile(
            $filename,
            $file->getBasename(),
            $file->getMimeType(),
            $file->getSize()
        );
    }

    /**
     * @return ChoiceInterface[]
     */
    public static function getChoices(string $type): array
    {
        return static::getChoiceManager()->getChoices($type);
    }

    public static function getChoice(string $type, ?string $value): ?ChoiceInterface
    {
        return static::getChoiceManager()->getChoice($type, $value);
    }

    /**
     * Create the query builder.
     *
     * @param string      $class   The class name
     * @param string      $alias   The alias
     * @param null|string $indexBy The index by
     */
    public static function createQueryBuilder(string $class, string $alias = 'o', ?string $indexBy = null): QueryBuilder
    {
        return static::getResourceDomain($class)->createQueryBuilder($alias, $indexBy);
    }

    /**
     * Find an object by criteria.
     *
     * @param string     $class        The class name
     * @param array      $criteria     The object repository criteria
     * @param bool       $nullable     Check if the value can be null
     * @param bool       $requiredNull Check if the null value is required
     * @param null|array $orderBy      The order by
     * @param null|int   $limit        The limit
     * @param null|int   $offset       The offset
     */
    public static function findOneBy(
        string $class,
        array $criteria,
        bool $nullable = false,
        bool $requiredNull = false,
        ?array $orderBy = null,
        ?int $limit = null,
        ?int $offset = null
    ): ?object {
        $repo = static::getObjectRepository($class);
        $object = $repo->findOneBy($criteria, $orderBy, $limit, $offset);

        if (!$nullable) {
            static::assertNotNull($object);
        } elseif ($requiredNull) {
            static::assertNull($object);
        }

        return $object;
    }

    /**
     * Find objects by criteria.
     *
     * @param string     $class         The class name
     * @param array      $criteria      The object repository criteria
     * @param null|int   $expectedCount The expected count
     * @param null|array $orderBy       The order by
     *
     * @return object[]
     */
    public static function findBy(string $class, array $criteria, ?int $expectedCount = null, ?array $orderBy = null): array
    {
        $repo = static::getObjectRepository($class);
        $objects = $repo->findBy($criteria, $orderBy);

        if (\is_int($expectedCount)) {
            static::assertCount($expectedCount, $objects);
        }

        return $objects;
    }

    /**
     * Create a new instance of domain resource.
     *
     * @param string $class   The class name
     * @param array  $options The options of resource default value
     */
    public static function newInstance(string $class, array $options = []): object
    {
        return static::getResourceDomain($class)->newInstance($options);
    }

    /**
     * Create the object in database.
     *
     * @param object $object          The domain object
     * @param bool   $thrownException Thrown an exception if the result is invalid
     */
    public static function create(object $object, bool $thrownException = true): ResourceInterface
    {
        $res = static::getResourceDomain(ClassUtils::getClass($object))->create($object);

        if ($thrownException && !$res->isValid()) {
            static::thrownConsoleException($res);
        }

        return $res;
    }

    /**
     * Create the objects in database.
     *
     * @param array $objects         The domain objects
     * @param bool  $thrownException Thrown an exception if the result is invalid
     */
    public static function creates(array $objects, bool $thrownException = true): ResourceListInterface
    {
        $res = static::getResourceDomain(ClassUtils::getClass($objects[0]))->creates($objects);

        if ($thrownException && $res->hasErrors()) {
            static::thrownConsoleException($res);
        }

        return $res;
    }

    /**
     * Update the object in database.
     *
     * @param object $object          The domain object
     * @param bool   $thrownException Thrown an exception if the result is invalid
     */
    public static function update(object $object, bool $thrownException = true): ResourceInterface
    {
        $res = static::getResourceDomain(ClassUtils::getClass($object))->update($object);

        if ($thrownException && !$res->isValid()) {
            static::thrownConsoleException($res);
        }

        return $res;
    }

    /**
     * Update the objects in database.
     *
     * @param array $objects         The domain objects
     * @param bool  $thrownException Thrown an exception if the result is invalid
     */
    public static function updates(array $objects, bool $thrownException = true): ResourceListInterface
    {
        $res = static::getResourceDomain(ClassUtils::getClass($objects[0]))->updates($objects);

        if ($thrownException && $res->hasErrors()) {
            static::thrownConsoleException($res);
        }

        return $res;
    }

    /**
     * Delete the object in database.
     *
     * @param object $object          The domain object
     * @param bool   $thrownException Thrown an exception if the result is invalid
     */
    public static function delete(object $object, bool $thrownException = true): ResourceInterface
    {
        $res = static::getResourceDomain(ClassUtils::getClass($object))->delete($object);

        if ($thrownException && !$res->isValid()) {
            static::thrownConsoleException($res);
        }

        return $res;
    }

    /**
     * Delete the objects in database.
     *
     * @param array $objects         The domain objects
     * @param bool  $thrownException Thrown an exception if the result is invalid
     */
    public static function deletes(array $objects, bool $thrownException = true): ResourceListInterface
    {
        $res = static::getResourceDomain(ClassUtils::getClass($objects[0]))->deletes($objects);

        if ($thrownException && $res->hasErrors()) {
            static::thrownConsoleException($res);
        }

        return $res;
    }

    /**
     * Delete the object in database.
     *
     * @param object|string          $class           The class name or the object instance
     * @param null|int|object|string $identifier      The domain identifier
     * @param bool                   $thrownException Thrown an exception if the result is invalid
     */
    public static function undelete($class, $identifier = null, bool $thrownException = true): ResourceInterface
    {
        if (\is_object($class)) {
            $identifier = $class;
            $class = ClassUtils::getClass($class);
        }

        $res = static::getResourceDomain($class)->undelete($identifier);

        if ($thrownException && !$res->isValid()) {
            static::thrownConsoleException($res);
        }

        return $res;
    }

    /**
     * Undelete the objects in database.
     *
     * @param object[]|string              $class           The class name or the object instances
     * @param null|int[]|object[]|string[] $identifiers     The domain identifiers
     * @param bool                         $thrownException Thrown an exception if the result is invalid
     */
    public static function undeletes($class, array $identifiers, bool $thrownException = true): ResourceListInterface
    {
        if (\is_array($class)) {
            $identifiers = (array) $class;
            $class = ClassUtils::getClass($class[0]);
        }

        $res = static::getResourceDomain($class)->undeletes($identifiers);

        if ($thrownException && $res->hasErrors()) {
            static::thrownConsoleException($res);
        }

        return $res;
    }

    /**
     * Refresh the object managed by doctrine.
     *
     * @param object $object The object managed by doctrine
     *
     * @throws
     */
    public static function refresh(object $object): void
    {
        static::getEntityManager()->refresh($object);
    }

    /**
     * Disable the current organization in security organizational context.
     */
    public static function disableCurrentOrganization(): void
    {
        static::getOrganizationalContext()->setCurrentOrganization(false);
    }

    /**
     * Quote the identifier defined by the database platform.
     *
     * @param string $value The value
     *
     * @throws
     */
    public static function quoteIdentifier(string $value): string
    {
        $em = static::getEntityManager();
        $platform = $em->getConnection()->getDatabasePlatform();

        return $platform->quoteIdentifier($value);
    }

    /**
     * Load the files in content directory.
     *
     * @param string[] $files The names of uploaded files
     *
     * @return string[]
     */
    public static function loadFiles(array $files): array
    {
        $copyFiles = [];
        $fs = new Filesystem();
        $localBase = static::getLocalBase().'/';
        $originalFilename = __DIR__.'/DataFixtures/Resources/assets/file.jpg';

        foreach ($files as $filename) {
            $fs->copy($originalFilename, $localBase.$filename);
            $copyFiles[] = $localBase.$filename;
        }

        return $copyFiles;
    }

    /**
     * Remove content files.
     */
    public static function cleanupContent(): void
    {
        $path = static::getLocalBase();

        if (file_exists($path)) {
            $cmd = 'rm -rf '.escapeshellarg($path);

            if (\defined('PHP_WINDOWS_VERSION_MAJOR')) {
                $cmd = 'rd /s /q '.escapeshellarg($path);
            }

            $process = Process::fromShellCommandline($cmd);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }
        }
    }

    /**
     * Clean the entity manager.
     *
     * @param null|string $entityName The entity name
     *
     * @throws
     */
    public static function cleanupEntityManager(?string $entityName = null): void
    {
        static::getEntityManager()->clear($entityName);
    }

    /**
     * Disable the SQL Filters.
     *
     * @param null|string[] $filters The list of SQL Filter, if NULL, all filters are disabled
     */
    public static function disableFilters(?array $filters = null): void
    {
        $all = null === $filters;
        $filters = \is_array($filters) ? $filters : [];

        $em = static::getEntityManager();
        $filters = array_unique(array_merge(static::$disabledFilters, $filters));

        static::$disabledFilters = SqlFilterUtil::findFilters($em, $filters, $all);
        SqlFilterUtil::disableFilters($em, static::$disabledFilters);
    }

    /**
     * Disable the SQL Filters.
     *
     * @param null|string[] $filters The list of SQL Filter, if NULL, all filters are disabled
     */
    public static function enableFilters(array $filters = []): void
    {
        SqlFilterUtil::enableFilters(static::getEntityManager(), $filters);
    }

    /**
     * Re-enable all disabled SQL filters.
     */
    public static function resetFilters(): void
    {
        static::enableFilters(static::$disabledFilters);
        static::$disabledFilters = [];
    }

    protected static function injectDefaultUser(string $firewallContext = 'main'): UserInterface
    {
        return static::injectUser(static::getDefaultUser(), $firewallContext);
    }

    protected static function injectUserByIdentifier(string $userIdentifier, string $firewallContext = 'main'): UserInterface
    {
        return static::injectUser(static::getUserByIdentifier($userIdentifier), $firewallContext);
    }

    protected static function injectUser(UserInterface $user, string $firewallContext = 'main'): UserInterface
    {
        $token = new TestModelToken($user->getRoles(), $user, $firewallContext);
        $token->setAuthenticated(true);

        $container = static::getContainer();
        $container->get('security.untracked_token_storage')->setToken($token);

        $container->get('event_dispatcher')->dispatch(new SetCurrentOrganizationEvent(null));
        $container->get('translator')->setLocale(\Locale::getDefault());

        if ($container->has('stof_doctrine_extensions.listener.blameable')) {
            $container->get('stof_doctrine_extensions.listener.blameable')->setUserValue($user);
        }

        return $user;
    }

    protected static function getLocalBase(): string
    {
        return static::getContainer()->getParameter('kernel.cache_dir').'/content_local';
    }

    private static function setUpModelTests(): void
    {
        static::$disabledFilters = [];
        static::$originalLocale = \Locale::getDefault();
        \Locale::setDefault('en');
    }

    private static function tearDownModelTests(): void
    {
        \Locale::setDefault(static::$originalLocale);
        static::cleanupContent();
        static::resetFilters();
    }

    /**
     * Thrown the console exception.
     *
     * @param ResourceInterface|ResourceListInterface $resource
     */
    private static function thrownConsoleException($resource): void
    {
        static::tryToThrownConstraintCauseException($resource->getErrors());

        if ($resource instanceof ResourceListInterface) {
            foreach ($resource->all() as $childResource) {
                static::tryToThrownConstraintCauseException($childResource->getErrors());
            }
        }

        throw new ConsoleResourceException($resource);
    }

    /**
     * Try to thrown the exception in the cause of constraint violation.
     *
     * @param ConstraintViolationListInterface $errors The constraint violation list
     */
    private static function tryToThrownConstraintCauseException(ConstraintViolationListInterface $errors): void
    {
        if ($errors->count() > 0) {
            $error = $errors->get(0);

            if ($error instanceof ConstraintViolation && ($cause = $error->getCause()) instanceof \Exception) {
                throw $cause;
            }
        }
    }
}
