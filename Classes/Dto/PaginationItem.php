<?php

declare(strict_types=1);

namespace UBOS\MenuControls\Dto;

/**
 * Data Transfer Object for a single pagination link.
 * Contains all information needed to render one item in a pagination control.
 *
 * A null item (e.g. null previousItem on the first page) signals absence —
 * there is no disabled state, only present or absent.
 */
class PaginationItem
{
    /**
     * @param string $label Display text for the item (page number, '<', '>', etc.)
     * @param string $url Absolute URL for standard navigation
     * @param string $fragmentUrl Relative URL for AJAX/fragment-based navigation.
     *   Empty string when fragment rendering is not configured.
     * @param bool $active Whether this item represents the currently active page.
     */
    public function __construct(
        public string $label,
        public string $url = '',
        public string $fragmentUrl = '',
        public bool   $active = false,
    ) {}
}
