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

use Symfony\Component\PropertyAccess\PropertyAccess;

/**
 * Content message response builder for the API request response.
 *
 * @author François Pluchino <francois.pluchino@klipper.dev>
 */
class ContentMessage
{
    /**
     * @var null|mixed
     */
    private $value;

    /**
     * @var null|ContentMessage
     */
    private $parent;

    /**
     * @var bool
     */
    private $required;

    /**
     * @var ContentMessage[]
     */
    private $fields = [];

    /**
     * @var null|int
     */
    private $fieldTemplateSize;

    /**
     * @var null|ContentMessage
     */
    private $fieldTemplate;

    /**
     * Constructor.
     *
     * @param null|string         $message  The message
     * @param null|mixed          $value    The value
     * @param null|ContentMessage $parent   The parent
     * @param bool                $required Check if the content is required
     */
    private function __construct(?string $message, $value, ?ContentMessage $parent = null, bool $required = true)
    {
        $this->value = $value;
        $this->parent = $parent;
        $this->required = $required;

        if (null !== $message) {
            $this->addField('message', $message);
        }
    }

    /**
     * Get the value.
     *
     * @param null|array $content The content
     *
     * @return null|mixed
     */
    public function getValue(?array $content = null)
    {
        $value = $this->value;

        if (\is_array($content) && \is_string($value) && 0 === strpos($value, '[')) {
            $value = PropertyAccess::createPropertyAccessor()->getValue($content, $value);
        }

        return $value;
    }

    /**
     * Get the parent.
     */
    public function getParent(): ?ContentMessage
    {
        return $this->parent;
    }

    /**
     * Check if the content is required.
     */
    public function isRequired(): bool
    {
        return $this->required;
    }

    /**
     * Add the content field.
     *
     * @param int|string $name     The field name
     * @param null|mixed $value    The field value
     * @param bool       $required Check if the field is required
     *
     * @return static The child content message instance
     */
    public function addField($name, $value = '[]', bool $required = true): self
    {
        $required = 0 === strpos($name, '?') ? false : $required;
        $name = ltrim($name, '?');
        $value = '[]' === $value ? '['.$name.']' : $value;
        $this->fields[$name] = new self(null, $value, $this, $required);

        return $this;
    }

    /**
     * Get the content message field.
     *
     * @param int|string $name The field name
     */
    public function getField($name): ContentMessage
    {
        return $this->fields[$name];
    }

    /**
     * Get the fields.
     *
     * @return ContentMessage[]
     */
    public function getFields(): array
    {
        return $this->fields;
    }

    /**
     * Check if the content message has fields.
     */
    public function hasFields(): bool
    {
        return !empty($this->fields);
    }

    /**
     * Set the template message of fields.
     *
     * @param int            $size     The number of fields
     * @param ContentMessage $template The template message of fields
     *
     * @return static
     */
    public function setFieldTemplate(int $size, ContentMessage $template): self
    {
        $this->fieldTemplateSize = $size;
        $this->fieldTemplate = $template;

        return $this;
    }

    /**
     * Get the number of field template messages.
     */
    public function getFieldTemplateSize(): ?int
    {
        return $this->fieldTemplateSize;
    }

    /**
     * Get the template message of fields.
     */
    public function getFieldTemplate(): ?ContentMessage
    {
        return $this->fieldTemplate;
    }

    /**
     * Create the array content of content message response.
     *
     * @param null|array $content The content
     *
     * @return array|mixed
     */
    public function build(?array $content = null)
    {
        $value = $this->getValue($content);

        if (null !== ($tpl = $this->getFieldTemplate()) && null !== ($size = $this->getFieldTemplateSize())) {
            $contentKeys = array_keys($content);
            $contentValues = array_values($content);

            for ($i = 0; $i < $size; ++$i) {
                $hasKey = isset($contentKeys[$i], $contentValues[$i]);
                $id = $hasKey ? $contentKeys[$i] : $i;
                $tplValue = $hasKey ? $contentValues[$i] : null;
                $value[$id] = $tpl->build($tplValue);
            }
        } elseif (!empty($this->getFields())) {
            $value = [];

            foreach ($this->getFields() as $name => $field) {
                $hasChildren = $field->hasFields() || null !== $field->getFieldTemplate();
                $fieldContent = $hasChildren && isset($content[$name]) ? $content[$name] : $content;
                $fieldValue = !$hasChildren ? $field->build($fieldContent) : null;

                if ($field->isRequired() || (!$field->isRequired() && null !== $fieldValue)) {
                    $value[$name] = $field->build($fieldContent);
                }
            }
        }

        return $value;
    }

    /**
     * Create the content message response builder.
     *
     * @param null|string $message The message
     *
     * @return static
     */
    public static function create(?string $message = null): self
    {
        return new self($message, null);
    }
}
