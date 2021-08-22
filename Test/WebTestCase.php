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

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase as BaseWebTestCase;

/**
 * Web test case for klipper platform.
 *
 * @author François Pluchino <francois.pluchino@klipper.dev>
 */
abstract class WebTestCase extends BaseWebTestCase
{
    use BrowserKitAssertionsTrait;
    use DatabaseKitTestsTrait;
    use ModelKitAssertionsTrait;

    protected function setUp(): void
    {
        parent::setUp();
        static::setUpModelTests();
    }

    protected function tearDown(): void
    {
        static::tearDownModelTests();
        parent::tearDown();
    }
}
