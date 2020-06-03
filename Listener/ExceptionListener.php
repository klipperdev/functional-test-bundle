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
    private ?\Throwable $lastThrowable;

    /**
     * Set exception.
     *
     * @param ExceptionEvent $event The event
     */
    public function setThrowable(ExceptionEvent $event): void
    {
        $this->lastThrowable = $event->getThrowable();
    }

    /**
     * Clear the last exception.
     *
     * @param RequestEvent $event The event
     */
    public function clearLastThrowable(RequestEvent $event): void
    {
        if (HttpKernelInterface::MASTER_REQUEST === $event->getRequestType()) {
            $this->lastThrowable = null;
        }
    }

    /**
     * Get the last throwable.
     */
    public function getLastThrowable(): ?\Throwable
    {
        return $this->lastThrowable;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['setThrowable', 99999],
            KernelEvents::REQUEST => ['clearLastThrowable', 99999],
        ];
    }
}
