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

use Symfony\Component\Security\Core\Authentication\Token\AbstractToken;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A very limited token that is used to login in tests.
 *
 * @author François Pluchino <francois.pluchino@klipper.dev>
 */
class TestModelToken extends AbstractToken
{
    private string $firewallName;

    public function __construct(array $roles = [], UserInterface $user = null, string $firewallName = 'main')
    {
        parent::__construct($roles);

        if (null !== $user) {
            $this->setUser($user);
        }

        $this->firewallName = $firewallName;
    }

    public function __serialize(): array
    {
        return [$this->firewallName, parent::__serialize()];
    }

    public function __unserialize(array $data): void
    {
        [$this->firewallName, $parentData] = $data;

        parent::__unserialize($parentData);
    }

    public function getFirewallName(): string
    {
        return $this->firewallName;
    }

    public function getCredentials()
    {
        return null;
    }
}
