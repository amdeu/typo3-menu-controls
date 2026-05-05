<?php

declare(strict_types=1);

namespace Amdeu\MenuControls\Builder;

use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Pagination\ArrayPaginator;
use TYPO3\CMS\Core\Pagination\SlidingWindowPagination;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Amdeu\MenuControls\Dto\Pagination;
use Amdeu\MenuControls\Dto\PaginationItem;

/**
 * Builder for Pagination DTOs.
 *
 * Wraps TYPO3's SlidingWindowPagination and produces a Pagination DTO
 * ready for consumption in Fluid templates.
 *
 * The builder is decoupled from the HTTP request. The controller provides
 * the current page number (read from the request) and URL-building closures.
 * The template decides how to present the pagination — numbered strip,
 * load-more button, infinite scroll, or any other pattern.
 *
 * Basic usage:
 *
 *   $currentPage = (int)($request->getArgument('page') ?? 1);
 *
 *   $builder = (new PaginationBuilder($allRecords, $currentPage))
 *       ->withItemsPerPage(12)
 *       ->withUrlBuilder(fn(int $page) => $this->uriBuilder->uriFor('list', ['page' => $page ?: null]))
 *       ->withFragmentUrlBuilder(fn(int $page) => ...)
 *       ->addPaginationLinksToHead();
 *
 *   $pagination = $builder->build();
 *   $pageRecords = $builder->getPaginatedItems();
 *
 * Note: addPaginationLinksToHead() is side-effectful — it writes rel=prev/next
 * link tags to the HTML head for SEO. Call it before build() when needed.
 */
#[Autoconfigure(public: true)]
class PaginationBuilder
{
    /**
     * Cached SlidingWindowPagination instance.
     * Invalidated when itemsPerPage or maximumLinks changes.
     */
    protected ?SlidingWindowPagination $slidingWindowPagination = null;

    /**
     * Number of items to display per page.
     */
    protected int $itemsPerPage = 12;

    /**
     * Maximum number of page number links to show in the sliding window.
     */
    protected int $maximumLinks = 3;

    /**
     * Builds a standard (absolute) URL for a given page number.
     *
     * @var \Closure(int $page): string
     */
    protected \Closure $urlBuilder;

    /**
     * Builds a fragment/AJAX URL for a given page number.
     * When not provided, fragmentUrl on all items will be an empty string.
     *
     * @var \Closure(int $page): string|null
     */
    protected ?\Closure $fragmentUrlBuilder = null;

    /**
     * @param array $records The full (unpaginated) record set to paginate
     * @param int $currentPage The current page number, read from the request by the controller
     */
    public function __construct(
        protected array $records,
        protected int   $currentPage = 1,
    ) {
        $this->urlBuilder = fn(int $page) => '';
    }

    // -------------------------------------------------------------------------
    // Fluent configuration
    // -------------------------------------------------------------------------

    /**
     * Sets the number of items to display per page.
     */
    public function withItemsPerPage(int $itemsPerPage): self
    {
        $this->itemsPerPage = $itemsPerPage;
        $this->slidingWindowPagination = null;
        return $this;
    }

    /**
     * Sets the maximum number of page links shown in the sliding window.
     */
    public function withMaximumLinks(int $maximumLinks): self
    {
        $this->maximumLinks = $maximumLinks;
        $this->slidingWindowPagination = null;
        return $this;
    }

    /**
     * Sets the URL builder closure.
     * Receives the target page number; must return an absolute URL string.
     *
     * Convention: page 1 should produce a URL without a page argument
     * to ensure a canonical first page URL.
     *
     * Example:
     *   fn(int $page) => $this->uriBuilder->uriFor('list', ['page' => $page > 1 ? $page : null])
     *
     * @param \Closure(int $page): string $urlBuilder
     */
    public function withUrlBuilder(\Closure $urlBuilder): self
    {
        $this->urlBuilder = $urlBuilder;
        return $this;
    }

    /**
     * Sets the fragment/AJAX URL builder closure.
     * Receives the target page number; must return a relative URL string.
     * When not provided, fragmentUrl on all items will be an empty string.
     *
     * @param \Closure(int $page): string $fragmentUrlBuilder
     */
    public function withFragmentUrlBuilder(\Closure $fragmentUrlBuilder): self
    {
        $this->fragmentUrlBuilder = $fragmentUrlBuilder;
        return $this;
    }

    // -------------------------------------------------------------------------
    // Side effects
    // -------------------------------------------------------------------------

    /**
     * Writes rel=prev and rel=next link tags to the HTML head for SEO.
     *
     * Uses the configured urlBuilder closure to produce canonical page URLs.
     * Call before build() if SEO link tags are needed.
     *
     * @param PageRenderer|null $pageRenderer Defaults to GeneralUtility::makeInstance(PageRenderer::class)
     */
    public function addPaginationLinksToHead(?PageRenderer $pageRenderer = null): self
    {
        $pageRenderer ??= GeneralUtility::makeInstance(PageRenderer::class);
        $swp = $this->getSlidingWindowPagination();

        if ($prev = $swp->getPreviousPageNumber()) {
            $pageRenderer->addHeaderData('<link rel="prev" href="' . ($this->urlBuilder)($prev) . '" />');
        }
        if ($next = $swp->getNextPageNumber()) {
            $pageRenderer->addHeaderData('<link rel="next" href="' . ($this->urlBuilder)($next) . '" />');
        }

        return $this;
    }

    // -------------------------------------------------------------------------
    // Build
    // -------------------------------------------------------------------------

    /**
     * Builds and returns the Pagination DTO.
     *
     * Always produces the full pagination structure. Templates decide which
     * parts to render — use nextItem alone for load-more or infinite scroll,
     * use the full set for a numbered pagination strip.
     */
    public function build(): Pagination
    {
        $swp = $this->getSlidingWindowPagination();

        return new Pagination(
            currentPage: $swp->getPaginator()->getCurrentPageNumber(),
            previousItem: $this->buildItem($swp->getPreviousPageNumber(), '<'),
            nextItem: $this->buildItem($swp->getNextPageNumber(), '>'),
            windowItems: array_map(
                fn(int $page) => $this->buildItem($page),
                $swp->getAllPageNumbers()
            ),
            hasSeparatorBefore: $swp->getHasLessPages(),
            hasSeparatorAfter: $swp->getHasMorePages(),
            firstItem: $swp->getFirstPageNumber() < $swp->getDisplayRangeStart()
                ? $this->buildItem($swp->getFirstPageNumber())
                : null,
            lastItem: $swp->getLastPageNumber() > $swp->getDisplayRangeEnd()
                ? $this->buildItem($swp->getLastPageNumber())
                : null,
        );
    }

    /**
     * Returns the slice of records for the current page.
     * Call this after configuring the builder to get the records to render.
     *
     * @return array The paginated record subset for the current page
     */
    public function getPaginatedItems(): array
    {
        return $this->getSlidingWindowPagination()->getPaginator()->getPaginatedItems();
    }

    /**
     * Returns the underlying SlidingWindowPagination instance.
     * Exposed for advanced use cases — prefer getPaginatedItems() and build() for typical usage.
     */
    public function getSlidingWindowPagination(): SlidingWindowPagination
    {
        if (!$this->slidingWindowPagination) {
            $this->slidingWindowPagination = new SlidingWindowPagination(
                new ArrayPaginator($this->records, $this->currentPage, $this->itemsPerPage),
                $this->maximumLinks,
            );
        }
        return $this->slidingWindowPagination;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Builds a single PaginationItem for a given page number.
     * Returns null when $page is null, signalling the item does not exist.
     *
     * @param int|null $page Target page number
     * @param string $label Custom display label. Defaults to the page number as a string.
     */
    protected function buildItem(?int $page, string $label = ''): ?PaginationItem
    {
        if ($page === null) {
            return null;
        }
        return new PaginationItem(
            label: $label !== '' ? $label : (string)$page,
            url: ($this->urlBuilder)($page),
            fragmentUrl: $this->fragmentUrl($page),
            active: $page === $this->currentPage,
        );
    }

    protected function fragmentUrl(int $page): string
    {
        return $this->fragmentUrlBuilder ? ($this->fragmentUrlBuilder)($page) : '';
    }
}
