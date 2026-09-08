<?php
declare(strict_types=1);
defined('TYPO3') or die();

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;
use HauerHeinrich\HhExtFaqs\Controller\FaqController;


ExtensionUtility::configurePlugin(
    'HhExtFaqs',
    'List',
    [FaqController::class => 'list'],
    [],
    ExtensionUtility::PLUGIN_TYPE_CONTENT_ELEMENT
);

ExtensionUtility::configurePlugin(
    'HhExtFaqs',
    'Search',
    [FaqController::class => 'search'],
    [],
    ExtensionUtility::PLUGIN_TYPE_CONTENT_ELEMENT
);

/*
 * configurePlugin() with PLUGIN_TYPE_CONTENT_ELEMENT unconditionally adds
 * global rendering TypoScript ("defaultContentRendering") for the CTypes,
 * which would enable the frontend rendering in EVERY site / root tree.
 *
 * We deliberately remove that global definition again to make the frontend
 * rendering OPT-IN per site: it is (re-)defined by
 * EXT:hh_ext_faqs/Configuration/TypoScript/setup.typoscript, which is loaded
 * either via the "FAQ" site set (hauerheinrich/hh-ext-faqs) or via the classic static
 * template include "FAQ (hh_ext_faqs)". Sites/trees without one of the two
 * render nothing for these content elements.
 */
ExtensionManagementUtility::addTypoScript(
    'HhExtFaqs',
    'setup',
    '
# hh_ext_faqs: frontend rendering is opt-in per site, see EXT:hh_ext_faqs/ext_localconf.php
tt_content.hhextfaqs_list >
tt_content.hhextfaqs_search >
',
    'defaultContentRendering'
);
