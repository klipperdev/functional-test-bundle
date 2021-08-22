<?php

/*
 * This file is part of the Klipper package.
 *
 * (c) François Pluchino <francois.pluchino@klipper.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Klipper\Bundle\FunctionalTestBundle\Test\DataFixtures;

use Symfony\Component\Console\Application;

/**
 * @author François Pluchino <francois.pluchino@klipper.dev>
 */
interface FixtureApplicationableInterface
{
    public function setApplication(Application $application): void;
}
