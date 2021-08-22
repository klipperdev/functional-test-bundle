<?php

/*
 * This file is part of the Klipper package.
 *
 * (c) François Pluchino <francois.pluchino@klipper.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Klipper\Bundle\FunctionalTestBundle\Test\DataFixtures\Data;

use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Klipper\Bundle\FunctionalTestBundle\Test\DataFixtures\FixtureApplicationableInterface;
use Klipper\Bundle\FunctionalTestBundle\Test\DataFixtures\FixtureApplicationableTrait;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Fixtures for all entities required for the platform.
 *
 * @author François Pluchino <francois.pluchino@klipper.dev>
 */
class PlatformInitFixtures implements FixtureInterface, FixtureApplicationableInterface
{
    use FixtureApplicationableTrait;

    private static string $COMMAND_NAME = 'init:klipper';

    public function load(ObjectManager $manager): void
    {
        $this->getApplication()->get(static::$COMMAND_NAME)->run(
            new ArgvInput([
                'command' => static::$COMMAND_NAME,
            ]),
            new NullOutput()
        );
    }
}
