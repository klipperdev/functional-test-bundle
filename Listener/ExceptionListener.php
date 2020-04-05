<?php

/*
 * This file is part of the Klipper package.
 *
 * (c) François Pluchino <francois.pluchino@klipper.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Klipper\Bundle\FunctionalTestBundle\Listener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Exception listener.
 *
 * @author François Pluchino <francois.pluchino@klipper.dev>
 */
class ExceptionListener implements EventSubscriberInterface
{
    /**
     * @var null|\Exception
     */
    private $lastException;

    /**
     * Set exception.
     *
     * @param ExceptionEvent $event The event
     */
    public function setException(ExceptionEvent $event): void
    {
        $this->lastException = $event->getException();
    }

    /**
     * Clear the last exception.
     *
     * @param RequestEvent $event The event
     */
    public function clearLastException(RequestEvent $event): void
    {
        if (HttpKernelInterface::MASTER_REQUEST === $event->getRequestType()) {
            $this->lastException = null;
        }
    }

    /**
     * Get the last exception.
     *
     * @return null|\Exception
     */
    public function getLastException(): ?\Exception
    {
        return $this->lastException;
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['setException', 99999],
            KernelEvents::REQUEST => ['clearLastException', 99999],
        ];
    }
}
