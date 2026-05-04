<?php

declare(strict_types=1);

namespace UBOS\MenuControls\Dto;

/**
 * Data Transfer Object for pagination state.
 * Contains all data needed to render a pagination control in a Fluid template.
 *
 * Templates decide how to present this data — numbered strip, load-more button,
 * infinite scroll trigger, or any other pattern. The DTO always contains the
 * full picture; templates use what they need.
 */
class Pagination
{
    /**
     * @param int $currentPage The currently active page number.
     *
     * @param PaginationItem|null $previousItem Link to the previous page.
     *   Null when the current page is the first page.
     *
     * @param PaginationItem|null $nextItem Link to the next page.
     *   Null when the current page is the last page.
     *   Use this alone to implement load-more or infinite scroll patterns.
     *
     * @param PaginationItem[] $windowItems Page links within the current sliding window.
     *
     * @param bool $hasSeparatorBefore Whether a separator should be shown between
     *   $firstItem and the start of $windowItems (i.e. there are pages between them).
     *
     * @param bool $hasSeparatorAfter Whether a separator should be shown between
     *   the end of $windowItems and $lastItem.
     *
     * @param string $separatorLabel Text to render for separators (default: '...').
     *
     * @param PaginationItem|null $firstItem Link to the first page.
     *   Null when the first page is already within the window.
     *
     * @param PaginationItem|null $lastItem Link to the last page.
     *   Null when the last page is already within the window.
     */
    public function __construct(
        public int             $currentPage = 1,
        public ?PaginationItem $previousItem = null,
        public ?PaginationItem $nextItem = null,
        public array           $windowItems = [],
        public bool            $hasSeparatorBefore = false,
        public bool            $hasSeparatorAfter = false,
        public string          $separatorLabel = '...',
        public ?PaginationItem $firstItem = null,
        public ?PaginationItem $lastItem = null,
    ) {}
}
