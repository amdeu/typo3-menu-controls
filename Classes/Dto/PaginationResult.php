<?php

declare(strict_types=1);

namespace Amdeu\MenuControls\Dto;

/**
 * Result of PaginationBuilder::build().
 * Carries the Pagination DTO for templates and the sliced record set for the
 * current page
 */
readonly class PaginationResult
{
    public function __construct(
        public Pagination $pagination,
        public array      $paginatedItems,
    ) {}
}
