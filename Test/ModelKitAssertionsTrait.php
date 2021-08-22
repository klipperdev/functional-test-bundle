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

use Symfony\Component\PropertyAccess\PropertyAccess;

/**
 * @author François Pluchino <francois.pluchino@klipper.dev>
 */
trait ModelKitAssertionsTrait
{
    use ModelKitTestsTrait;

    /**
     * Assert that the value of file field is not null and that the file is in the filesystem.
     *
     * @param array|object $object           The object
     * @param string       $field            The field name of image path
     * @param null|bool    $assertFileExists Assert the file exists
     */
    public static function assertUploadedFile($object, string $field, bool $assertFileExists = false): void
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
    public static function assertDeletedFile($object, string $field, bool $assertDeleted = false): void
    {
        $path = PropertyAccess::createPropertyAccessor()->getValue($object, $field);

        if ($assertDeleted) {
            static::assertFileDoesNotExist($path);
        }
    }
}
