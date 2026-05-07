<?php

declare(strict_types=1);

namespace Amdeu\MenuControls\Builder;

use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Pagination\ArrayPaginator;
use TYPO3\CMS\Core\Pagination\SlidingWindowPagination;
use TYPO3\CMS\Core\Utility\DebugUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Amdeu\MenuControls\Dto\Pagination;
use Amdeu\MenuControls\Dto\PaginationItem;
use Amdeu\MenuControls\Dto\PaginationResult;

/**
 * Produces a PaginationResult from a record set and configuration.
 *
 * Usage:
 *
 *   $result = $this->paginationBuilder->build(
 *       records: $allRecords,
 *       urlBuilder: fn(int $page) => $this->uriBuilder->uriFor('menu', [
 *           'page' => $page > 1 ? $page : null
 *       ]),
 *       fragmentUrlBuilder: fn(int $page) => $this->buildFragmentUrl(['page' => $page]),
 *       currentPage: (int)($request->getArgument('page') ?? 1),
 *       itemsPerPage: 12,
 *       addHeadLinks: true,
 *   );
 *
 *   $pages      = $result->paginatedItems;
 *   $pagination = $result->pagination;
 */
#[Autoconfigure(public: true)]
class PaginationBuilder
{
    /**
     * @param array                     $records            Full unpaginated record set
     * @param \Closure(int $p): string  $urlBuilder         Receives page number, returns absolute URL.
     *                                                      Page 1 should produce a URL without a page argument.
     * @param \Closure(int $p): string|null $fragmentUrlBuilder Optional AJAX URL builder
     * @param int                       $currentPage        Current page number from the request
     * @param int                       $itemsPerPage       Items per page (default: 12)
     * @param int                       $maximumLinks       Max page links in the sliding window (default: 3)
     * @param bool                      $addHeadLinks       When true, writes rel=prev/next to the HTML head for SEO
     * @param PageRenderer|null         $pageRenderer       Override for testing; defaults to GeneralUtility::makeInstance
     */
    public function build(
        array         $records,
        \Closure      $urlBuilder,
        ?\Closure     $fragmentUrlBuilder = null,
        int           $currentPage = 1,
        int           $itemsPerPage = 12,
        int           $maximumLinks = 3,
        bool          $addHeadLinks = false,
        ?PageRenderer $pageRenderer = null,
    ): PaginationResult {

        $paginator = new ArrayPaginator($records, $currentPage, $itemsPerPage);
        $swp = new SlidingWindowPagination($paginator, $maximumLinks);

        if ($addHeadLinks) {
            $pageRenderer ??= GeneralUtility::makeInstance(PageRenderer::class);
            if ($prev = $swp->getPreviousPageNumber()) {
                $pageRenderer->addHeaderData('<link rel="prev" href="' . $urlBuilder($prev) . '" />');
            }
            if ($next = $swp->getNextPageNumber()) {
                $pageRenderer->addHeaderData('<link rel="next" href="' . $urlBuilder($next) . '" />');
            }
        }

        $buildItem = fn(?int $page, string $label = ''): ?PaginationItem =>
            $page === null ? null : new PaginationItem(
                label: $label !== '' ? $label : (string)$page,
                url: $urlBuilder($page),
                fragmentUrl: $fragmentUrlBuilder ? $fragmentUrlBuilder($page) : '',
                active: $page === $currentPage,
            );

        return new PaginationResult(
            pagination: new Pagination(
                currentPage: $paginator->getCurrentPageNumber(),
                previousItem: $buildItem($swp->getPreviousPageNumber(), '<'),
                nextItem: $buildItem($swp->getNextPageNumber(), '>'),
                windowItems: array_map(fn(int $page) => $buildItem($page), $swp->getAllPageNumbers()),
                hasSeparatorBefore: $swp->getHasLessPages(),
                hasSeparatorAfter: $swp->getHasMorePages(),
                firstItem: $swp->getFirstPageNumber() < $swp->getDisplayRangeStart()
                    ? $buildItem($swp->getFirstPageNumber())
                    : null,
                lastItem: $swp->getLastPageNumber() > $swp->getDisplayRangeEnd()
                    ? $buildItem($swp->getLastPageNumber())
                    : null,
            ),
            paginatedItems: $paginator->getPaginatedItems(),
        );
    }
}
