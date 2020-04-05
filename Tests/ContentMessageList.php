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
 * Content message response builder for the API request response.
 *
 * @author François Pluchino <francois.pluchino@klipper.dev>
 */
class ContentMessageList
{
    /**
     * @var int
     */
    private $total;

    /**
     * @var int
     */
    private $limit;

    /**
     * @var int
     */
    private $page;

    /**
     * @var int
     */
    private $pages;

    /**
     * @var null|ContentMessage
     */
    private $template;

    /**
     * Constructor.
     *
     * @param int $total The total of row
     * @param int $limit The limit of row by page
     * @param int $page  The page number
     * @param int $pages The number of pages
     */
    private function __construct(int $total, int $limit, int $page, int $pages)
    {
        $this->total = $total;
        $this->limit = $limit;
        $this->page = $page;
        $this->pages = $pages;
    }

    /**
     * Get the total of row.
     *
     * @return int
     */
    public function getTotal(): int
    {
        return $this->total;
    }

    /**
     * Get the limit of row by page.
     *
     * @return int
     */
    public function getLimit(): int
    {
        return $this->limit;
    }

    /**
     * Get the page number.
     *
     * @return int
     */
    public function getPage(): int
    {
        return $this->page;
    }

    /**
     * Get the number of pages.
     *
     * @return int
     */
    public function getPages(): int
    {
        return $this->pages;
    }

    /**
     * Set the result template message.
     *
     * @param ContentMessage $template The template message
     *
     * @return static
     */
    public function setTemplate(ContentMessage $template): self
    {
        $this->template = $template;

        return $this;
    }

    /**
     * Get the result template message.
     *
     * @return null|ContentMessage
     */
    public function getTemplate(): ?ContentMessage
    {
        return $this->template;
    }

    /**
     * Create the array content of content message list response.
     *
     * @param null|array $content The content
     *
     * @return array
     */
    public function build(?array $content = null): array
    {
        $value = [
            'total' => $this->getTotal(),
            'limit' => $this->getLimit(),
            'page' => $this->getPage(),
            'pages' => $this->getPages(),
            'results' => [],
        ];

        if (null !== ($tpl = $this->getTemplate())) {
            $count = min($this->getTotal(), $this->getLimit());

            for ($i = 0; $i < $count; ++$i) {
                if (isset($content['results'][$i])) {
                    $value['results'][$i] = $tpl->build($content['results'][$i]);
                }
            }
        } elseif (isset($content['results'])) {
            $value['results'] = $content['results'];
        }

        return $value;
    }

    /**
     * Create the content message response builder.
     *
     * @param int      $total The total of row
     * @param int      $limit The limit of row by page
     * @param int      $page  The page number
     * @param null|int $pages The number of pages
     *
     * @return static
     */
    public static function create(int $total, int $limit = 20, int $page = 1, ?int $pages = null): self
    {
        $pages = null !== (int) $pages
            ? $pages
            : (int) ceil($total / $limit);

        if ($pages < 1) {
            $pages = 1;
        }

        return new self($total, $limit, $page, (int) $pages);
    }
}
