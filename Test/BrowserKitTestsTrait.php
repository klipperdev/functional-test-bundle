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

use JMS\Serializer\SerializationContext;
use Klipper\Bundle\ApiBundle\ViewGroups;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * @author François Pluchino <francois.pluchino@klipper.dev>
 */
trait BrowserKitTestsTrait
{
    /**
     * Call the URI and get the response data.
     *
     * @param KernelBrowser $client             The Http client
     * @param null|int      $expectedStatusCode The expected status code
     * @param string        $method             The request method
     * @param string        $uri                The URI to fetch
     * @param null|string   $content            The raw body data of request
     * @param array         $files              The request files
     * @param array         $server             The server parameters (HTTP headers are referenced with a HTTP_ prefix as PHP does)
     * @param array         $parameters         The request parameters
     * @param bool          $changeHistory      Whether to update the history or not (only used internally for back(), forward(), and reload())
     */
    public static function request(
        KernelBrowser $client,
        ?int $expectedStatusCode,
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
     * @param KernelBrowser     $client             The Http client
     * @param null|int          $expectedStatusCode The expected status code
     * @param string            $method             The request method
     * @param string            $uri                The URI to fetch
     * @param null|array|string $content            The raw body data of request
     * @param array             $files              The request files
     * @param array             $server             The server parameters (HTTP headers are referenced with a HTTP_ prefix as PHP does)
     * @param array             $parameters         The request parameters
     * @param bool              $changeHistory      Whether to update the history or not (only used internally for back(), forward(), and reload())
     */
    public static function requestJson(
        KernelBrowser $client,
        ?int $expectedStatusCode,
        string $method,
        string $uri,
        ?string $content = null,
        array $files = [],
        array $server = [],
        array $parameters = [],
        bool $changeHistory = true
    ): ?array {
        if (\is_array($content)) {
            $content = static::jsonEncode($content);
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
     * Extracts the location from the given route.
     *
     * @param string $route  The name of the route
     * @param array  $params Set of parameters
     */
    public static function getUrl(string $route, array $params = [], int $absolute = UrlGeneratorInterface::ABSOLUTE_PATH): string
    {
        return static::getContainer()->get('router')->generate($route, $params, $absolute);
    }

    /**
     * Get the response data of Http client.
     *
     * @param KernelBrowser $client             The Http client
     * @param null|int      $expectedStatusCode The expected status code
     */
    public static function getResponseData(KernelBrowser $client, ?int $expectedStatusCode = null): string
    {
        $response = $client->getResponse();
        $content = $response->getContent();

        if (null !== $expectedStatusCode && method_exists(static::class, 'assertStatusCode')) {
            static::assertStatusCode($expectedStatusCode, $response);
        }

        if (Response::HTTP_NO_CONTENT === $expectedStatusCode) {
            static::assertEmpty($content);
        }

        return $content;
    }

    /**
     * Get the response json data of Http client.
     *
     * @param KernelBrowser $client             The Http client
     * @param null|int      $expectedStatusCode The expected status code
     */
    public static function getResponseJsonData(KernelBrowser $client, ?int $expectedStatusCode = null): ?array
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
    public static function getJsonSerializedData($data, $viewGroups = []): array
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

    public static function jsonEncode(array $content): string
    {
        $val = json_encode($content);

        return false !== $val ? $val : '';
    }

    public static function jsonDecode(string $content, bool $assoc = false): array
    {
        return $assoc ? (array) json_decode($content, true) : json_decode($content, false);
    }

    public static function hasJsonDecodeError(): bool
    {
        return JSON_ERROR_NONE !== json_last_error();
    }

    /**
     * @return ConstraintViolationInterface[]|ConstraintViolationListInterface
     */
    private static function getValidationErrors()
    {
        $container = static::getContainer();

        if ($container->has('klipper_functional_test.validator')) {
            return $container->get('klipper_functional_test.validator')->getLastErrors();
        }

        return [];
    }
}
