<?php

namespace Amdeu\MenuControls\Dto;

/**
 * Data Transfer Object for a single category filter item.
 * Contains all information needed to render a filter item in a Fluid template.
 *
 * Tree structure is expressed through the $children array — presence of children
 * indicates this item is a tree node. Depth is implicit in the nesting.
 */
class CategoryFilterItem
{
    /**
     * @param string $label Display text for the item
     *
     * @param string $url URL that applies this item's toggle to the filter state.
     *   Empty string when the item is disabled (non-selectable header).
     *
     * @param string $fragmentUrl URL for AJAX/fragment-based rendering of the same state.
     *   Empty string when fragment rendering is not configured or item is disabled.
     *
     * @param CategoryFilterItem[] $children Child items for hierarchical filters.
     *
     * @param CategoryFilterItem|null $closeItem Deselects all active children of this item.
     *   Present only when one or more children are currently active.
     *   Label contains the count of active children as a string.
     *
     * @param bool $active Whether this item is currently selected.
     *
     * @param bool $disabled Whether this item is a non-selectable structural element
     *   (e.g. a collapsible group header). Disabled items have no URL.
     *
     * @param int|null $potentialCount Number of records that would match if this item
     *   were selected given the current demand. Null when potential checking is not
     *   configured. 0 means selecting this item yields no results — use to hide or
     *   disable the item in templates. Any positive value can be displayed as a count tag.
     *
     * @param int[] $activeChildren UIDs of child categories that are currently active.
     *
     * @param CategoryFilterItem|null $exclusiveItem Represents this item selected exclusively
     *   (all other active categories deselected). Useful for rendering a "only this" affordance
     *   alongside a multi-select toggle.
     */
    public function __construct(
        public string              $label,
        public string              $url = '',
        public string              $fragmentUrl = '',
        public array               $children = [],
        public ?CategoryFilterItem $closeItem = null,
        public bool                $active = false,
        public bool                $disabled = false,
        public ?int                $potentialCount = null,
        public array               $activeChildren = [],
        public ?CategoryFilterItem $exclusiveItem = null,
    ) {}

	public function hasNoPotential(): bool
	{
		return $this->potentialCount === 0;
	}
}
