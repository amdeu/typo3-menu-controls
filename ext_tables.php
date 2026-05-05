<?php

declare(strict_types=1);

use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

defined('TYPO3') or die();

ExtensionUtility::registerPlugin(
    'MenuControls',
    'List',
    'Page Menu: List',
    'content-menu-sitemap',
    'menus',
);

ExtensionUtility::registerPlugin(
    'MenuControls',
    'PaginatedList',
    'Page Menu: Paginated List',
    'content-menu-sitemap',
    'menus',
);

ExtensionUtility::registerPlugin(
    'MenuControls',
    'FilteredList',
    'Page Menu: Filtered List',
    'content-menu-sitemap',
    'menus',
);
