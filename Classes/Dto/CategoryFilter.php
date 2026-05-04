<?php

namespace UBOS\MenuControls\Dto;

/**
 * Data Transfer Object for a category filter.
 * Contains all data needed to render filter controls in a Fluid template.
 *
 * Tree depth information is no longer stored here — it is now per-item,
 * carried during build time by CategoryItemConfig and reflected in the
 * presence or absence of children on each CategoryFilterItem.
 */
class CategoryFilter
{
    /**
     * @param CategoryFilterItem[] $items The filter items in display order
     * @param CategoryFilterItem|null $resetItem Item that clears the filter entirely.
     *   Null when no filter is active.
     */
    public function __construct(
        public array               $items = [],
        public ?CategoryFilterItem $resetItem = null,
    ) {}

    /**
     * Convenience: whether any filter is currently active.
     * Templates can use this instead of checking resetItem !== null.
     */
    public function isActive(): bool
    {
        return $this->resetItem !== null;
    }
}
