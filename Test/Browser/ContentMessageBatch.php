<?php

/*
 * This file is part of the Klipper package.
 *
 * (c) François Pluchino <francois.pluchino@klipper.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Klipper\Bundle\FunctionalTestBundle\Test\Browser;

use Klipper\Component\Resource\ResourceListStatutes;

/**
 * Content message batch response builder for the API request response.
 *
 * @author François Pluchino <francois.pluchino@klipper.dev>
 */
class ContentMessageBatch
{
    private string $status;

    private bool $hasErrors;

    private int $size;

    /**
     * @param string $status    The resource status
     * @param bool   $hasErrors Check if the list has errors
     * @param int    $size      The number of expected records
     */
    private function __construct(string $status, bool $hasErrors, int $size)
    {
        $this->status = $status;
        $this->hasErrors = $hasErrors;
        $this->size = $size;
    }

    /**
     * Get the status.
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * Check if the list has errors.
     */
    public function hasErrors(): bool
    {
        return $this->hasErrors;
    }

    /**
     * Get the size.
     */
    public function getSize(): int
    {
        return $this->size;
    }

    /**
     * Create the array content of content message list response.
     *
     * @param null|array $content The content
     */
    public function build(?array $content): array
    {
        $value = [
            'status' => $this->getStatus(),
            'has_errors' => $this->hasErrors(),
            'records' => [],
        ];

        if (isset($content['records'])) {
            $value['records'] = $content['records'];
        }

        return $value;
    }

    /**
     * Create the content message batch response builder.
     *
     * @param int         $size      The number of expected records
     * @param null|string $status    The status
     * @param bool        $hasErrors Check if the list has errors
     *
     * @return static
     */
    public static function create(int $size, ?string $status = null, bool $hasErrors = false): self
    {
        $status = $status ?? ResourceListStatutes::SUCCESSFULLY;

        return new self($status, $hasErrors, $size);
    }
}
