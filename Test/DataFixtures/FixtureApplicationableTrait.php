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
trait FixtureApplicationableTrait
{
    protected ?Application $application = null;

    /**
     * @see FixtureApplicationableInterface::setApplication()
     */
    public function setApplication(Application $application): void
    {
        $this->application = $application;
    }

    protected function getApplication(): Application
    {
        if (null === $this->application) {
            throw new \InvalidArgumentException('The Application instance must be injected before');
        }

        return $this->application;
    }
}
