<?php
declare(strict_types=1);

/**
 * Ziel: Classes/Updates/FlexFormToColumnsUpdate.php
 *
 * Migriert die bisherigen FlexForm-Werte (pi_flexform) der beiden
 * FAQ-Plugins in die neuen tx_hhextfaqs_*-Spalten und leert pi_flexform.
 *
 * Registrierung erfolgt automatisch über das #[UpgradeWizard]-Attribut
 * (TYPO3 v12.4+). Ausführen im Install Tool unter "Upgrade Wizards"
 * oder per CLI: vendor/bin/typo3 upgrade:run hhExtFaqs_flexFormToColumns
 */

namespace HauerHeinrich\HhExtFaqs\Updates;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Service\FlexFormService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Install\Attribute\UpgradeWizard;
use TYPO3\CMS\Install\Updates\DatabaseUpdatedPrerequisite;
use TYPO3\CMS\Install\Updates\UpgradeWizardInterface;

#[UpgradeWizard('hhExtFaqs_flexFormToColumns')]
final class FlexFormToColumnsUpdate implements UpgradeWizardInterface {
    /**
     * FlexForm-Setting => tt_content-Spalte, je CType
     */
    private const MAPPING = [
        'hhextfaqs_list' => [
            'records' => 'tx_hhextfaqs_records',
            'categories' => 'tx_hhextfaqs_categories',
            'categoryConjunction' => 'tx_hhextfaqs_category_conjunction',
            'sortField' => 'tx_hhextfaqs_sort_field',
            'sortOrder' => 'tx_hhextfaqs_sort_order',
            'structuredData' => 'tx_hhextfaqs_structured_data',
        ],
        'hhextfaqs_search' => [
            'listElements' => 'tx_hhextfaqs_list_elements',
            'autoExpand' => 'tx_hhextfaqs_auto_expand',
        ],
    ];

    private const INT_COLUMNS = [
        'tx_hhextfaqs_structured_data',
        'tx_hhextfaqs_auto_expand',
    ];

    public function getTitle(): string {
        return 'FAQ-Plugins: FlexForm-Werte in tt_content-Spalten migrieren';
    }

    public function getDescription(): string {
        return 'Überträgt die Plugin-Einstellungen aus pi_flexform in die neuen '
            . 'tx_hhextfaqs_*-Spalten und leert pi_flexform anschließend.';
    }

    public function updateNecessary(): bool {
        return (int)$this->createQueryBuilder()
            ->count('uid')
            ->executeQuery()
            ->fetchOne() > 0;
    }

    public function executeUpdate(): bool {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tt_content');
        $flexFormService = GeneralUtility::makeInstance(FlexFormService::class);

        $rows = $this->createQueryBuilder()
            ->select('uid', 'CType', 'pi_flexform')
            ->executeQuery()
            ->fetchAllAssociative();

        foreach ($rows as $row) {
            $settings = $flexFormService
                ->convertFlexFormContentToArray((string)$row['pi_flexform'])['settings'] ?? [];

            $update = ['pi_flexform' => null];
            foreach (self::MAPPING[$row['CType']] as $settingKey => $column) {
                if (!isset($settings[$settingKey])) {
                    continue;
                }
                $update[$column] = in_array($column, self::INT_COLUMNS, true)
                    ? (int)$settings[$settingKey]
                    : (string)$settings[$settingKey];
            }

            $connection->update('tt_content', $update, ['uid' => (int)$row['uid']]);
        }

        return true;
    }

    public function getPrerequisites(): array {
        return [
            DatabaseUpdatedPrerequisite::class,
        ];
    }

    private function createQueryBuilder(): QueryBuilder {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tt_content');
        // Auch versteckte/gelöschte Elemente migrieren
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->in(
                    'CType',
                    $queryBuilder->createNamedParameter(array_keys(self::MAPPING), Connection::PARAM_STR_ARRAY)
                ),
                $queryBuilder->expr()->isNotNull('pi_flexform'),
                $queryBuilder->expr()->neq('pi_flexform', $queryBuilder->createNamedParameter(''))
            );
    }
}
