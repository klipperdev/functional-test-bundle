<?php

/*
 * This file is part of the Klipper package.
 *
 * (c) François Pluchino <francois.pluchino@klipper.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Klipper\Bundle\FunctionalTestBundle\Tests;

/**
 * Interface of default authentication.
 *
 * @author François Pluchino <francois.pluchino@klipper.dev>
 */
trait DefaultAuthenticationTrait
{
    protected string $defaultUsername = '';

    protected string $defaultPassword = '';

    /**
     * @see DefaultAuthenticationInterface::setDefaultAuthentication()
     */
    public function setDefaultAuthentication(string $username, string $password): void
    {
        $this->defaultUsername = $username;
        $this->defaultPassword = $password;
    }
}
