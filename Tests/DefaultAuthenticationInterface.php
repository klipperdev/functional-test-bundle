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
interface DefaultAuthenticationInterface
{
    /**
     * @param string $username The username
     * @param string $password The password
     */
    public function setDefaultAuthentication(string $username, string $password): void;
}
