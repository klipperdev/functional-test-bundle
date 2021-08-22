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

use Klipper\Bundle\FunctionalTestBundle\Test\Browser\ContentMessage;
use Klipper\Bundle\FunctionalTestBundle\Test\Browser\ContentMessageBatch;
use Klipper\Bundle\FunctionalTestBundle\Test\Browser\ContentMessageList;
use Klipper\Bundle\FunctionalTestBundle\Test\Browser\ErrorMessage;
use Symfony\Component\HttpFoundation\Response;

/**
 * @author François Pluchino <francois.pluchino@klipper.dev>
 */
trait BrowserKitAssertionsTrait
{
    use BrowserKitTestsTrait;

    /**
     * Assert the content.
     *
     * @param ContentMessage $contentMessage The expected content message
     * @param null|array     $content        The actual content
     * @param null|array     $validContent   The valid content
     */
    public static function assertContentEquals(ContentMessage $contentMessage, ?array $content, ?array $validContent = null): void
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
    public static function assertContentListEquals(ContentMessageList $contentMessageList, ?array $content, ?array $validContent = null): void
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
    public static function assertContentBatchEquals(ContentMessageBatch $contentMessageBatch, ?array $content, ?string $expectedRecordStatus = null): void
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
    public static function assertErrorContentEquals(ErrorMessage $errorMessage, ?array $content): void
    {
        unset($content['exception']);
        static::assertEquals($errorMessage->build(), $content);
    }

    public static function assertStatusCode(int $expectedStatusCode, Response $response, bool $showContent = true): void
    {
        $statusCode = $response->getStatusCode();
        $statusText = Response::$statusTexts[$statusCode] ?? 'unknown status';
        $helpfulErrorMessage = '';

        if ($expectedStatusCode !== $response->getStatusCode()) {
            // Get a more useful error message, if available
            if ($exception = static::getContainer()->get('klipper_functional_test.exception_listener')->getLastThrowable()) {
                $helpfulErrorMessage = $exception->getMessage();
            } elseif (\count($validationErrors = static::getValidationErrors())) {
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
}
