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
use JMS\Serializer\SerializationContext;
use Klipper\Bundle\ApiBundle\ViewGroups;
use Klipper\Component\DataLoader\Exception\ConsoleResourceException;
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
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Web test case for klipper platform.
 *
 * @author François Pluchino <francois.pluchino@klipper.dev>
 */
abstract class WebTestCase extends AbstractWebTestCase
{
    protected array $disabledFilters = [];

    protected ?string $originalLocale = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disabledFilters = [];
        $this->originalLocale = \Locale::getDefault();
        \Locale::setDefault('en');
    }

    protected function tearDown(): void
    {
        \Locale::setDefault($this->originalLocale);
        $this->cleanupContent();
        $this->reEnableFilters();

        parent::tearDown();
    }

    public static function assertStatusCode(int $expectedStatusCode, KernelBrowser $client, bool $showContent = true): void
    {
        $response = $client->getResponse();
        $statusCode = $response->getStatusCode();
        $statusText = Response::$statusTexts[$statusCode] ?? 'unknown status';
        $helpfulErrorMessage = '';

        if ($expectedStatusCode !== $client->getResponse()->getStatusCode()) {
            // Get a more useful error message, if available
            if ($exception = $client->getContainer()->get('klipper_functional_test.exception_listener')->getLastThrowable()) {
                $helpfulErrorMessage = $exception->getMessage();
            } elseif (\count($validationErrors = static::getValidationErrors($client))) {
                $helpfulErrorMessage = "Unexpected validation errors:\n";

                foreach ($validationErrors as $error) {
                    $helpfulErrorMessage .= sprintf("+ %s: %s\n", $error->getPropertyPath(), $error->getMessage());
                }
            } else {
                $helpfulErrorMessage = sprintf(
                    'HTTP/%s %s %s',
                    $response->getProtocolVersion(),
                    $statusCode,
                    $statusText
                )."\r\n".$response->headers."\r\n";
            }

            $helpfulErrorMessage .= "\r\n\r\nContent of response:\r\n\r\n";
            $data = static::jsonDecode($response->getContent(), true);

            if (isset($data['exception']) && \is_array($data['exception'])) {
                if (!isset($data['exception'][0])) {
                    $data['exception'] = [
                        'class' => $data['exception']['class'],
                        'message' => $data['exception']['message'],
                    ];
                } else {
                    foreach ($data['exception'] as $i => $e) {
                        $data['exception'][$i] = [
                            'class' => $e['class'],
                            'message' => $e['message'],
                        ];
                    }
                }
            }

            if (static::hasJsonDecodeError()) {
                $data = $response->getContent();
            }

            ob_start();
            print_r($data);
            $helpfulErrorMessage .= ob_get_clean();
        }

        static::assertEquals($expectedStatusCode, $response->getStatusCode(), $showContent ? $helpfulErrorMessage : '');
    }

    /**
     * Assert the downloaded file.
     *
     * @param KernelBrowser $client        The http client
     * @param null|string   $filename      The content disposition filename
     * @param int           $contentLength The content length
     * @param null|string   $contentType   The content type
     */
    public static function assertDownloadedFile(KernelBrowser $client, ?string $filename = null, int $contentLength = 0, ?string $contentType = null): void
    {
        $response = $client->getResponse();
        static::assertNotNull($response);

        if (null !== $filename) {
            static::assertTrue($response->headers->has('content-disposition'));
            static::assertEquals(sprintf('attachment; filename="%s"', $filename), $response->headers->get('content-disposition'));
        }

        if (null !== $contentLength) {
            static::assertTrue($response->headers->has('content-length'));
            static::assertGreaterThan($contentLength, $response->headers->get('content-length'));
        }

        if (null !== $contentType) {
            static::assertTrue($response->headers->has('content-type'));
            static::assertEquals($contentType, $response->headers->get('content-type'));
        }
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
     * Call the URI and get the response data.
     *
     * @param null|int      $expectedStatusCode The expected status code
     * @param KernelBrowser $client             The Http client
     * @param string        $method             The request method
     * @param string        $uri                The URI to fetch
     * @param null|string   $content            The raw body data of request
     * @param array         $files              The request files
     * @param array         $server             The server parameters (HTTP headers are referenced with a HTTP_ prefix as PHP does)
     * @param array         $parameters         The request parameters
     * @param bool          $changeHistory      Whether to update the history or not (only used internally for back(), forward(), and reload())
     */
    protected static function request(
        ?int $expectedStatusCode,
        KernelBrowser $client,
        string $method,
        string $uri,
        ?string $content = null,
        array $files = [],
        array $server = [],
        array $parameters = [],
        bool $changeHistory = true
    ): string {
        ob_start();
        $client->request($method, $uri, $parameters, $files, $server, $content, $changeHistory);
        ob_end_clean();

        return static::getResponseData($client, $expectedStatusCode);
    }

    /**
     * Call the URI with array json content.
     *
     * @param null|int          $expectedStatusCode The expected status code
     * @param KernelBrowser     $client             The Http client
     * @param string            $method             The request method
     * @param string            $uri                The URI to fetch
     * @param null|array|string $content            The raw body data of request
     * @param array             $files              The request files
     * @param array             $server             The server parameters (HTTP headers are referenced with a HTTP_ prefix as PHP does)
     * @param array             $parameters         The request parameters
     * @param bool              $changeHistory      Whether to update the history or not (only used internally for back(), forward(), and reload())
     */
    protected static function requestJson(
        ?int $expectedStatusCode,
        KernelBrowser $client,
        string $method,
        string $uri,
        ?string $content = null,
        array $files = [],
        array $server = [],
        array $parameters = [],
        bool $changeHistory = true
    ): ?array {
        if (\is_array($content)) {
            $content = static::jsonDecode($content);
        }

        if (null !== $content && !isset($server['CONTENT_TYPE'])) {
            $server['CONTENT_TYPE'] = 'application/json';
        }

        if (!isset($server['HTTP_ACCEPT'])) {
            $server['HTTP_ACCEPT'] = 'application/json';
        }

        ob_start();
        $client->request($method, $uri, $parameters, $files, $server, $content, $changeHistory);
        $output = ob_get_clean();

        if (!$client->getResponse()->headers->has('content-disposition')) {
            echo $output;
        }

        return static::getResponseJsonData($client, $expectedStatusCode);
    }

    /**
     * Get the response data of Http client.
     *
     * @param KernelBrowser $client             The http client
     * @param null|int      $expectedStatusCode The expected status code
     */
    protected static function getResponseData(KernelBrowser $client, ?int $expectedStatusCode = null): string
    {
        $response = $client->getResponse();
        $content = $response->getContent();

        if (null !== $expectedStatusCode) {
            static::assertStatusCode($expectedStatusCode, $client);
        }

        if (Response::HTTP_NO_CONTENT === $expectedStatusCode) {
            static::assertEmpty($content);
        }

        return $content;
    }

    /**
     * Get the response json data of Http client.
     *
     * @param KernelBrowser $client             The http client
     * @param null|int      $expectedStatusCode The expected status code
     */
    protected static function getResponseJsonData(KernelBrowser $client, ?int $expectedStatusCode = null): ?array
    {
        $data = static::getResponseData($client, $expectedStatusCode);

        if (!empty($data)) {
            static::assertJson($data);
            static::assertSame('application/json', $client->getResponse()->headers->get('Content-Type'));
            $data = static::jsonDecode($data, true);
        } else {
            $data = null;
        }

        return $data;
    }

    /**
     * Get the json content after serialization of data.
     *
     * @param mixed                                $data       The data
     * @param SerializationContext|string|string[] $viewGroups The serialization context of view groups
     *
     * @throws
     */
    protected static function getJsonSerializedData($data, $viewGroups = []): array
    {
        static::assertNotNull($data);

        if (!$viewGroups instanceof SerializationContext && null !== $viewGroups) {
            $groups = (array) $viewGroups;
            $viewGroups = SerializationContext::create();

            if (!\in_array(ViewGroups::DEFAULT_GROUP, $groups, true)) {
                /* @var string[] $viewGroups */
                $groups[] = ViewGroups::DEFAULT_GROUP;
            }

            $viewGroups->setGroups($groups);
        }

        return static::jsonDecode(static::getContainer()->get('jms_serializer')->serialize($data, 'json', $viewGroups), true);
    }

    /**
     * Get the resource domain.
     *
     * @param string $class The class name
     */
    protected static function getResourceDomain(string $class): DomainInterface
    {
        return static::getContainer()->get('klipper_resource.domain_manager')->get($class);
    }

    /**
     * Get the object repository.
     *
     * @param string $class The class name
     *
     * @return EntityRepository|ObjectRepository
     */
    protected static function getObjectRepository(string $class): ObjectRepository
    {
        return static::getResourceDomain($class)->getRepository();
    }

    /**
     * Get the permission manager.
     */
    protected static function getPermissionManager(): PermissionManagerInterface
    {
        return static::getContainer()->get('klipper_security.permission_manager');
    }

    /**
     * Get the sharing manager.
     */
    protected static function getSharingManager(): SharingManagerInterface
    {
        return static::getContainer()->get('klipper_security.sharing_manager');
    }

    /**
     * Get the authorization checker.
     */
    protected static function getAuthorizationChecker(): AuthorizationCheckerInterface
    {
        return static::getContainer()->get('security.authorization_checker');
    }

    /**
     * Get the user of in token storage.
     *
     * @param bool $nullable Check if the user can me nullable
     */
    protected static function getTokenUser(bool $nullable = false): UserInterface
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
     * Get the security organizational context.
     */
    protected static function getOrganizationalContext(): OrganizationalContextInterface
    {
        /* @var OrganizationalContextInterface $orgContext */
        return static::getContainer()->get('klipper_security.organizational_context');
    }

    /**
     * Get the entity manager.
     */
    protected static function getEntityManager(): EntityManager
    {
        return static::getContainer()->get('doctrine.orm.entity_manager');
    }

    /**
     * Create the query builder.
     *
     * @param string      $class   The class name
     * @param string      $alias   The alias
     * @param null|string $indexBy The index by
     */
    protected static function createQueryBuilder(string $class, string $alias = 'o', ?string $indexBy = null): QueryBuilder
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
    protected static function findOneBy(
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
    protected static function findBy(string $class, array $criteria, ?int $expectedCount = null, ?array $orderBy = null): array
    {
        $repo = static::getObjectRepository($class);
        $objects = $repo->findBy($criteria, $orderBy);

        if (\is_int($expectedCount)) {
            static::assertCount($expectedCount, $objects);
        }

        return $objects;
    }

    /**
     * Assert the content.
     *
     * @param ContentMessage $contentMessage The expected content message
     * @param null|array     $content        The actual content
     * @param null|array     $validContent   The valid content
     */
    protected static function assertContentEquals(ContentMessage $contentMessage, ?array $content, ?array $validContent = null): void
    {
        static::assertEquals($contentMessage->build($content), $content);

        if (null !== $validContent && null !== $content) {
            static::assertEquals($validContent, $content);
        }
    }

    /**
     * Assert the content list.
     *
     * @param ContentMessageList $contentMessageList The expected content message list
     * @param null|array         $content            The actual content
     * @param null|array         $validContent       The valid content
     */
    protected static function assertContentListEquals(ContentMessageList $contentMessageList, ?array $content, ?array $validContent = null): void
    {
        static::assertEquals($contentMessageList->build($content), $content);

        if (null !== $validContent && null !== $content) {
            static::assertArrayHasKey('results', $content);
            static::assertEquals($validContent, $content['results']);
        }
    }

    /**
     * Assert the content batch.
     *
     * @param ContentMessageBatch $contentMessageBatch  The expected content message list
     * @param null|array          $content              The actual content
     * @param null|string         $expectedRecordStatus The expected record status
     */
    protected static function assertContentBatchEquals(ContentMessageBatch $contentMessageBatch, ?array $content, ?string $expectedRecordStatus = null): void
    {
        $size = $contentMessageBatch->getSize();
        static::assertEquals($contentMessageBatch->build($content), $content);
        static::assertArrayHasKey('records', $content);
        static::assertCount($size, $content['records']);

        if (null !== $expectedRecordStatus) {
            for ($i = 0; $i < $size; ++$i) {
                $result = $content['records'][$i];
                static::assertArrayHasKey('status', $result);
                static::assertArrayHasKey('record', $result);
                static::assertSame($expectedRecordStatus, $result['status']);
            }
        }
    }

    /**
     * Assert the error content.
     *
     * @param ErrorMessage $errorMessage The expected error message
     * @param null|array   $content      The actual error content
     */
    protected static function assertErrorContentEquals(ErrorMessage $errorMessage, ?array $content): void
    {
        unset($content['exception']);
        static::assertEquals($errorMessage->build(), $content);
    }

    /**
     * Assert that the value of file field is not null and that the file is in the filesystem.
     *
     * @param array|object $object           The object
     * @param string       $field            The field name of image path
     * @param null|bool    $assertFileExists Assert the file exists
     */
    protected static function assertUploadedFile($object, string $field, bool $assertFileExists = false): void
    {
        $path = PropertyAccess::createPropertyAccessor()->getValue($object, $field);
        static::assertNotNull($path);

        if ($assertFileExists) {
            static::assertFileExists($path);
        }
    }

    /**
     * Assert that the value of file field is null and that the file is not in the filesystem.
     *
     * @param array|object $object        The object
     * @param string       $field         The field name of image path
     * @param null|bool    $assertDeleted Assert the deleted file
     */
    protected static function assertDeletedFile($object, string $field, bool $assertDeleted = false): void
    {
        $path = PropertyAccess::createPropertyAccessor()->getValue($object, $field);

        if ($assertDeleted) {
            static::assertFileDoesNotExist($path);
        }
    }

    /**
     * Create a new instance of domain resource.
     *
     * @param string $class   The class name
     * @param array  $options The options of resource default value
     */
    protected static function newInstance(string $class, array $options = []): object
    {
        return static::getResourceDomain($class)->newInstance($options);
    }

    /**
     * Create the object in database.
     *
     * @param object $object          The domain object
     * @param bool   $thrownException Thrown an exception if the result is invalid
     */
    protected static function create(object $object, bool $thrownException = true): ResourceInterface
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
    protected static function creates(array $objects, bool $thrownException = true): ResourceListInterface
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
    protected static function update(object $object, bool $thrownException = true): ResourceInterface
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
    protected static function updates(array $objects, bool $thrownException = true): ResourceListInterface
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
    protected static function delete(object $object, bool $thrownException = true): ResourceInterface
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
    protected static function deletes(array $objects, bool $thrownException = true): ResourceListInterface
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
    protected static function undelete($class, $identifier = null, bool $thrownException = true): ResourceInterface
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
    protected static function undeletes($class, array $identifiers, bool $thrownException = true): ResourceListInterface
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
    protected static function refresh(object $object): void
    {
        static::getEntityManager()->refresh($object);
    }

    /**
     * Extracts the location from the given route.
     *
     * @param string $route  The name of the route
     * @param array  $params Set of parameters
     */
    protected static function getUrl(string $route, array $params = [], int $absolute = UrlGeneratorInterface::ABSOLUTE_PATH): string
    {
        return static::getContainer()->get('router')->generate($route, $params, $absolute);
    }

    /**
     * Inject the token with user.
     *
     * @param null|string $username The username
     */
    protected static function injectUserToken(?string $username = 'user.test'): ?UserInterface
    {
        $container = static::getContainer();
        $tokenStorage = $container->get('security.token_storage');
        $blameableListener = $container->get('stof_doctrine_extensions.listener.blameable');
        $dispatcher = $container->get('event_dispatcher');
        $translator = $container->get('translator');
        $repoUser = $container->get('klipper_resource.domain_manager')->get(UserInterface::class)->getRepository();
        $token = null;
        $user = null;

        if (null !== $username) {
            $user = $repoUser->findOneBy(['username' => $username]);

            if (null === $user) {
                throw new \InvalidArgumentException(sprintf('The user "%s" does not exist', $username));
            }

            $token = new UsernamePasswordToken($user, null, 'main', $user->getRoles());
            $locale = null !== $user->getSetting()
                ? $user->getSetting()->getLocale()
                : 'en';
            \Locale::setDefault($locale);
            $translator->setLocale($locale);
        }

        $tokenStorage->setToken($token);
        $blameableListener->setUserValue($user);
        $dispatcher->dispatch(new SetCurrentOrganizationEvent(null));

        return $user;
    }

    /**
     * Set the organization in context.
     *
     * @param null|string $name The organization name
     */
    protected static function injectCurrentOrgContext(?string $name): void
    {
        static::getContainer()->get('klipper_security_extra.organizational_context.helper')
            ->setCurrentOrganizationUser($name)
        ;
    }

    /**
     * Disable the current organization in security organizational context.
     */
    protected static function disableCurrentOrganization(): void
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
    protected static function quoteIdentifier(string $value): string
    {
        $em = static::getEntityManager();
        $platform = $em->getConnection()->getDatabasePlatform();

        return $platform->quoteIdentifier($value);
    }

    /**
     * Disable the SQL Filters.
     *
     * @param array|true $filters The list of SQL Filter, if TRUE, all filters are disabled
     */
    protected function disableFilters($filters): void
    {
        $all = true === $filters;
        $filters = \is_array($filters) ? $filters : [];

        $em = static::getEntityManager();
        $filters = array_unique(array_merge($this->disabledFilters, $filters));

        $this->disabledFilters = SqlFilterUtil::findFilters($em, $filters, $all);
        SqlFilterUtil::disableFilters($em, $this->disabledFilters);
    }

    /**
     * Re-enable all disabled SQL filters.
     */
    protected function reEnableFilters(): void
    {
        SqlFilterUtil::enableFilters(static::getEntityManager(), $this->disabledFilters);
        $this->disabledFilters = [];
    }

    /**
     * Remove content files.
     */
    protected static function cleanupContent(): void
    {
        $path = FileUtil::getLocalBase(static::getContainer());

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
    protected static function cleanupEntityManager(?string $entityName = null): void
    {
        static::getEntityManager()->clear($entityName);
    }

    protected static function jsonDecode(string $content, bool $assoc = false): array
    {
        return $assoc ? (array) json_decode($content, true) : json_decode($content, false);
    }

    protected static function hasJsonDecodeError(): bool
    {
        return JSON_ERROR_NONE !== json_last_error();
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

    /**
     * @return ConstraintViolationInterface[]|ConstraintViolationListInterface
     */
    private static function getValidationErrors(KernelBrowser $client)
    {
        $container = $client->getContainer();

        if ($container->has('klipper_functional_test.validator')) {
            return $container->get('klipper_functional_test.validator')->getLastErrors();
        }

        return [];
    }
}
