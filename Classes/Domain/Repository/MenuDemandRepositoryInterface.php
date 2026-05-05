<?php

declare(strict_types=1);

namespace Amdeu\MenuControls\Domain\Repository;

use Amdeu\MenuControls\Dto\MenuDemand;

/**
 * Interface for repositories that support filtering by MenuDemand objects.
 *
 * Implement this interface alongside FindByMenuDemandRepositoryTrait to
 * integrate any Extbase repository with the menu controls system.
 *
 * The trait provides a full default implementation of findByMenuDemand().
 * Repositories can customise behaviour by overriding
 * getAdditionalMenuDemandConstraints() in the trait.
 */
interface MenuDemandRepositoryInterface
{
    /**
     * Finds records based on a MenuDemand configuration.
     *
     * @param MenuDemand $demand The demand object containing search criteria
     * @param bool $returnRawQueryResult When true, returns raw associative DB rows
     *   instead of mapped domain objects. Use this when performance matters and
     *   you don't need hydrated models (e.g. for potential checking or raw rendering).
     * @return array The matched records — always an array, regardless of $returnRawQueryResult
     */
    public function findByMenuDemand(MenuDemand $demand, bool $returnRawQueryResult = false): array;
}
