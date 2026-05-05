<?php

declare(strict_types=1);

namespace Amdeu\MenuControls\Builder;

use Amdeu\MenuControls\Domain\Repository\MenuDemandRepositoryInterface;
use Amdeu\MenuControls\Dto\MenuDemand;

/**
 * Internal value object carrying CategoryFilterBuilder::build() parameters
 * through tree recursion.
 */
readonly class CategoryFilterBuildContext
{
    public function __construct(
        public \Closure                       $urlBuilder,
        public ?\Closure                      $fragmentUrlBuilder,
        public string                         $activeUids,
        public bool                           $multiSelect,
        public bool                           $checkPotential,
        public ?MenuDemandRepositoryInterface $potentialRepository,
        public ?MenuDemand                    $potentialDemand,
        public string                         $potentialGroupKey,
        public string                         $labelField,
        public string                         $labelFieldFallback,
    ) {}
}
