<?php
declare(strict_types=1);
defined('TYPO3') or die();

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

// Classic opt-in per root tree: "Include static (from extensions)" in the
// root sys_template record. Site-set based installations use the "FAQ" set
// (hauerheinrich/hh-ext-faqs) instead - both load the same TypoScript files.
(static function (string $extensionKey): void {
    ExtensionManagementUtility::addStaticFile(
        $extensionKey,
        'Configuration/TypoScript',
        'FAQ ('.$extensionKey.')'
    );
})('hh_ext_faqs');
