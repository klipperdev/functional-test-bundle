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
    /**
     * @var ValidatorInterface
     */
    protected $wrappedValidator;

    /**
     * @var ConstraintViolationListInterface
     */
    protected $lastErrors;

    /**
     * Constructor.
     *
     * @param ValidatorInterface $wrappedValidator The wrapped validator
     */
    public function __construct(ValidatorInterface $wrappedValidator)
    {
        $this->wrappedValidator = $wrappedValidator;
        $this->clearLastErrors();
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['clearLastErrors', 99999],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getMetadataFor($value): MetadataInterface
    {
        return $this->wrappedValidator->getMetadataFor($value);
    }

    /**
     * {@inheritdoc}
     */
    public function hasMetadataFor($value): bool
    {
        return $this->wrappedValidator->hasMetadataFor($value);
    }

    /**
     * {@inheritdoc}
     */
    public function validate($value, $constraints = null, $groups = null): ConstraintViolationListInterface
    {
        return $this->lastErrors = $this->wrappedValidator->validate($value, $constraints, $groups);
    }

    /**
     * {@inheritdoc}
     */
    public function validateProperty($object, $propertyName, $groups = null): ConstraintViolationListInterface
    {
        return $this->wrappedValidator->validateProperty($object, $propertyName, $groups);
    }

    /**
     * {@inheritdoc}
     */
    public function validatePropertyValue($objectOrClass, $propertyName, $value, $groups = null): ConstraintViolationListInterface
    {
        return $this->wrappedValidator->validatePropertyValue($objectOrClass, $propertyName, $value, $groups);
    }

    /**
     * {@inheritdoc}
     */
    public function startContext(): ContextualValidatorInterface
    {
        return $this->wrappedValidator->startContext();
    }

    /**
     * {@inheritdoc}
     */
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
     *
     * @return ConstraintViolationListInterface
     */
    public function getLastErrors(): ConstraintViolationListInterface
    {
        return $this->lastErrors;
    }
}
