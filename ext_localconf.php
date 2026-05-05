<?php

declare(strict_types=1);

use Amdeu\MenuControls\Controller\PageMenuController;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

defined('TYPO3') or die();

ExtensionUtility::configurePlugin(
	'MenuControls',
	'List',
	[PageMenuController::class => 'list'],
);

ExtensionUtility::configurePlugin(
	'MenuControls',
	'PaginatedList',
	[PageMenuController::class => 'paginatedList'],
);

ExtensionUtility::configurePlugin(
	'MenuControls',
	'FilteredList',
	[PageMenuController::class => 'filteredList'],
);

$GLOBALS['TYPO3_CONF_VARS']['SYS']['fluid']['namespaces']['menuControls'] = ['Amdeu\MenuControls\Components\ComponentCollection'];
