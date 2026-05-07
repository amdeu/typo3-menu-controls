<?php

declare(strict_types=1);

use Amdeu\MenuControls\Controller\MenuController;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

defined('TYPO3') or die();

ExtensionUtility::configurePlugin(
	'MenuControls',
	'PageMenu',
	[MenuController::class => 'pageMenu'],
);

$GLOBALS['TYPO3_CONF_VARS']['SYS']['fluid']['namespaces']['menuControls'] = ['Amdeu\MenuControls\Components\ComponentCollection'];
