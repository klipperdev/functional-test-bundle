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

/**
 * Default authentication for fixture.
 *
 * @author François Pluchino <francois.pluchino@klipper.dev>
 */
trait FixtureDefaultAuthenticationTrait
{
    protected string $defaultUsername = '';

    protected string $defaultPassword = '';

    /**
     * @see FixtureDefaultAuthenticationInterface::setDefaultAuthentication()
     */
    public function setDefaultAuthentication(string $username, string $password): void
    {
        $this->defaultUsername = $username;
        $this->defaultPassword = $password;
    }
}
