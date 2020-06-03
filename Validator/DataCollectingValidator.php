<?php

/*
 * This file is part of the Klipper package.
 *
 * (c) François Pluchino <francois.pluchino@klipper.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Klipper\Bundle\FunctionalTestBundle\Validator;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Mapping\MetadataInterface;
use Symfony\Component\Validator\Validator\ContextualValidatorInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Data collecting validator.
 *
 * @author François Pluchino <francois.pluchino@klipper.dev>
 */
class DataCollectingValidator implements ValidatorInterface, EventSubscriberInterface
{
    protected ValidatorInterface $wrappedValidator;

    protected ConstraintViolationListInterface $lastErrors;

    /**
     * @param ValidatorInterface $wrappedValidator The wrapped validator
     */
    public function __construct(ValidatorInterface $wrappedValidator)
    {
        $this->wrappedValidator = $wrappedValidator;
        $this->clearLastErrors();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['clearLastErrors', 99999],
        ];
    }

    public function getMetadataFor($value): MetadataInterface
    {
        return $this->wrappedValidator->getMetadataFor($value);
    }

    public function hasMetadataFor($value): bool
    {
        return $this->wrappedValidator->hasMetadataFor($value);
    }

    public function validate($value, $constraints = null, $groups = null): ConstraintViolationListInterface
    {
        return $this->lastErrors = $this->wrappedValidator->validate($value, $constraints, $groups);
    }

    public function validateProperty($object, $propertyName, $groups = null): ConstraintViolationListInterface
    {
        return $this->wrappedValidator->validateProperty($object, $propertyName, $groups);
    }

    public function validatePropertyValue($objectOrClass, $propertyName, $value, $groups = null): ConstraintViolationListInterface
    {
        return $this->wrappedValidator->validatePropertyValue($objectOrClass, $propertyName, $value, $groups);
    }

    public function startContext(): ContextualValidatorInterface
    {
        return $this->wrappedValidator->startContext();
    }

    public function inContext(ExecutionContextInterface $context): ContextualValidatorInterface
    {
        return $this->wrappedValidator->inContext($context);
    }

    /**
     * Clear the last errors.
     */
    public function clearLastErrors(): void
    {
        $this->lastErrors = new ConstraintViolationList();
    }

    /**
     * Get the last errors.
     */
    public function getLastErrors(): ConstraintViolationListInterface
    {
        return $this->lastErrors;
    }
}
