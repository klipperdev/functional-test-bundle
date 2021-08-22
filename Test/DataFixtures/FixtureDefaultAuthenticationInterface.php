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
 * Default authentication of fixture.
 *
 * @author François Pluchino <francois.pluchino@klipper.dev>
 */
interface FixtureDefaultAuthenticationInterface
{
    /**
     * @param string $username The username
     * @param string $password The password
     */
    public function setDefaultAuthentication(string $username, string $password): void;
}
