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
 * Error message response builder for the API request response.
 *
 * @author François Pluchino <francois.pluchino@klipper.dev>
 */
class ErrorMessage
{
    /**
     * @var string
     */
    public const BODY_JSON_REQUIRED = 'Request body should be a JSON object';

    /**
     * @var string
     */
    public const UNPROCESSABLE_ENTITY = 'Unable to process the request because it\'s incomplete';

    /**
     * @var string
     */
    public const NOT_FOUND = 'This page is unfortunately not available.';

    /**
     * @var null|string
     */
    private $message;

    /**
     * @var null|int
     */
    private $code;

    /**
     * @var null|ErrorMessage
     */
    private $parent;

    /**
     * @var string[]
     */
    private $errors = [];

    /**
     * @var ErrorMessage[]
     */
    private $children = [];

    /**
     * Constructor.
     *
     * @param null|string       $message The error message
     * @param null|int          $code    The error status code
     * @param null|ErrorMessage $parent  The parent
     */
    private function __construct(?string $message = null, ?int $code = null, ?ErrorMessage $parent = null)
    {
        $this->message = $message;
        $this->code = $code;
        $this->parent = $parent;
    }

    /**
     * Get the error message.
     *
     * @return null|string
     */
    public function getMessage(): ?string
    {
        return $this->message;
    }

    /**
     * Get the error status code.
     *
     * @return int
     */
    public function getCode(): int
    {
        return $this->code;
    }

    /**
     * Get the parent.
     *
     * @return null|ErrorMessage
     */
    public function getParent(): ?ErrorMessage
    {
        return $this->parent;
    }

    /**
     * Add the error message.
     *
     * @param string $message The error message
     *
     * @return static
     */
    public function addError(string $message): self
    {
        $this->errors[] = $message;

        return $this;
    }

    /**
     * Get the error messages.
     *
     * @return string[]
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Add the error child.
     *
     * @param string $name The child name
     *
     * @return static The child error message instance
     */
    public function addChild(string $name): self
    {
        $this->children[$name] = new self(null, null, $this);

        return $this->children[$name];
    }

    /**
     * Get the children.
     *
     * @return ErrorMessage[]
     */
    public function getChildren(): array
    {
        return $this->children;
    }

    /**
     * Create the array content of error response.
     *
     * @return array
     */
    public function build(): array
    {
        $content = [];
        $hasParent = null !== $this->getParent();

        if (null !== $this->getMessage()) {
            $content['message'] = $this->getMessage();
        }

        if (null !== $this->getCode()) {
            $content['code'] = $this->getCode();
        }

        foreach ($this->getErrors() as $error) {
            if ($hasParent) {
                $content['errors'][] = $error;
            } else {
                $content['errors']['errors'][] = $error;
            }
        }

        foreach ($this->getChildren() as $name => $child) {
            if ($hasParent) {
                $content['children'][$name] = $child->build();
            } else {
                $content['errors']['children'][$name] = $child->build();
            }
        }

        return $content;
    }

    /**
     * Create the error message response builder.
     *
     * @param null|string $message The error message
     * @param null|int    $code    The error status code
     *
     * @return static
     */
    public static function create(?string $message = null, ?int $code = null): self
    {
        return new self($message, $code);
    }
}
