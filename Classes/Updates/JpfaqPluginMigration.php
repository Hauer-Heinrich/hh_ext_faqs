<?php
declare(strict_types=1);

namespace HauerHeinrich\HhExtFaqs\Updates;

use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Install\Attribute\UpgradeWizard;
use TYPO3\CMS\Install\Updates\ChattyInterface;
use TYPO3\CMS\Install\Updates\DatabaseUpdatedPrerequisite;
use TYPO3\CMS\Install\Updates\RepeatableInterface;
use TYPO3\CMS\Install\Updates\UpgradeWizardInterface;

/**
 * Migrates tt_content plugins of EXT:jpfaq to the hh_ext_faqs list plugin.
 *
 * Covers both plugin registration variants of jpfaq:
 * - CType = "jpfaq_faq" (jpfaq for TYPO3 v13)
 * - CType = "list" with list_type = "jpfaq_faq" (older versions)
 *
 * FlexForm mapping (jpfaq FlexForm => tt_content columns):
 * - settings.startingpoint            => tt_content.pages
 * - settings.flexform.selectCategory  => tt_content.tx_hhextfaqs_categories
 *   (via the uid mapping stored in sys_registry by the record migration wizard)
 *
 * The old jpfaq pi_flexform is cleared afterwards, since the list plugin
 * no longer uses FlexForms at all.
 *
 * Run the record migration wizard first, otherwise category references
 * cannot be mapped.
 */
#[UpgradeWizard('hhExtFaqs_jpfaqPluginMigration')]
final class JpfaqPluginMigration implements UpgradeWizardInterface, ChattyInterface, RepeatableInterface {

    private const REGISTRY_NAMESPACE = 'tx_hhextfaqs';
    private const REGISTRY_CATEGORY_MAP = 'jpfaqCategoryMap';

    private const OLD_SIGNATURE = 'jpfaq_faq';
    private const NEW_CTYPE = 'hhextfaqs_list';

    private OutputInterface $output;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly Registry $registry,
    ) {
    }

    public function setOutput(OutputInterface $output): void {
        $this->output = $output;
    }

    public function getTitle(): string {
        return 'Migrate jpfaq plugins to hh_ext_faqs';
    }

    public function getDescription(): string {
        return 'Switches tt_content elements of EXT:jpfaq (CType "jpfaq_faq" or list_type "jpfaq_faq") '
            . 'to the hh_ext_faqs list plugin (CType "hhextfaqs_list"). Storage folders (startingpoint) are moved '
            . 'to the "pages" field, selected jpfaq categories are mapped to the migrated sys_category '
            . 'records and written to the tx_hhextfaqs_categories column. The old pi_flexform is cleared. '
            . 'Important: run "Migrate jpfaq records to hh_ext_faqs" first.';
    }

    public function getPrerequisites(): array {
        return [
            DatabaseUpdatedPrerequisite::class,
        ];
    }

    public function updateNecessary(): bool {
        return $this->buildSelectQuery()->count('uid')->executeQuery()->fetchOne() > 0;
    }

    public function executeUpdate(): bool {
        $categoryMap = $this->getCategoryMap();
        if ($categoryMap === []) {
            $this->output->writeln(
                '<warning>No jpfaq category mapping found in sys_registry. '
                . 'Category selections of the old plugins cannot be mapped. '
                . 'Run "Migrate jpfaq records to hh_ext_faqs" first if you have not done so.</warning>'
            );
        }

        $connection = $this->connectionPool->getConnectionForTable('tt_content');
        $rows = $this->buildSelectQuery()->select('*')->executeQuery()->fetchAllAssociative();

        foreach ($rows as $row) {
            $uid = (int)$row['uid'];
            $flexValues = $this->extractFlexValues((string)($row['pi_flexform'] ?? ''));

            $update = [
                'CType' => self::NEW_CTYPE,
                // The list plugin does not use FlexForms anymore,
                // remove the old jpfaq FlexForm data
                'pi_flexform' => null,
            ];
            if (array_key_exists('list_type', $row)) {
                $update['list_type'] = '';
            }

            // startingpoint => pages (keep an already filled pages field)
            $startingPoint = trim((string)($flexValues['settings.startingpoint'] ?? ''));
            if ($startingPoint !== '' && trim((string)($row['pages'] ?? '')) === '') {
                $update['pages'] = implode(',', GeneralUtility::intExplode(',', $startingPoint, true));
            }

            // selectCategory => tx_hhextfaqs_categories via mapping
            $oldCategoryUids = GeneralUtility::intExplode(
                ',',
                (string)($flexValues['settings.flexform.selectCategory'] ?? ''),
                true
            );
            $newCategoryUids = [];
            $unmapped = [];
            foreach ($oldCategoryUids as $oldUid) {
                if (isset($categoryMap[$oldUid])) {
                    $newCategoryUids[] = $categoryMap[$oldUid];
                } else {
                    $unmapped[] = $oldUid;
                }
            }
            if ($unmapped !== []) {
                $this->output->writeln(sprintf(
                    '<warning>tt_content:%d references jpfaq categories without mapping: %s</warning>',
                    $uid,
                    implode(', ', $unmapped)
                ));
            }

            $update['tx_hhextfaqs_categories'] = implode(',', $newCategoryUids);
            // jpfaq combined categories with OR, which matches the column default,
            // but set it explicitly to be independent of future default changes
            $update['tx_hhextfaqs_category_conjunction'] = 'or';

            $connection->update('tt_content', $update, ['uid' => $uid]);
            $this->output->writeln(sprintf('Migrated tt_content:%d ("%s").', $uid, (string)$row['header']));
        }

        $this->output->writeln(sprintf('%d plugin(s) migrated.', count($rows)));

        return true;
    }

    private function buildSelectQuery(): QueryBuilder {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();

        $constraints = [
            $queryBuilder->expr()->eq(
                'CType',
                $queryBuilder->createNamedParameter(self::OLD_SIGNATURE)
            ),
        ];

        if ($this->contentTableHasColumn('list_type')) {
            $constraints[] = $queryBuilder->expr()->and(
                $queryBuilder->expr()->eq('CType', $queryBuilder->createNamedParameter('list')),
                $queryBuilder->expr()->eq('list_type', $queryBuilder->createNamedParameter(self::OLD_SIGNATURE))
            );
        }

        return $queryBuilder
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->or(...$constraints)
            );
    }

    private function contentTableHasColumn(string $column): bool {
        $schemaManager = $this->connectionPool
            ->getConnectionForTable('tt_content')
            ->createSchemaManager();

        return array_key_exists($column, $schemaManager->listTableColumns('tt_content'));
    }

    /**
     * Extract all vDEF values from all sheets of a FlexForm XML string.
     *
     * @return array<string, string>
     */
    private function extractFlexValues(string $flexFormXml): array {
        if (trim($flexFormXml) === '') {
            return [];
        }

        $data = GeneralUtility::xml2array($flexFormXml);
        if (!is_array($data) || !isset($data['data']) || !is_array($data['data'])) {
            return [];
        }

        $values = [];
        foreach ($data['data'] as $sheet) {
            if (!is_array($sheet) || !isset($sheet['lDEF']) || !is_array($sheet['lDEF'])) {
                continue;
            }
            foreach ($sheet['lDEF'] as $fieldName => $field) {
                if (is_array($field) && array_key_exists('vDEF', $field)) {
                    $values[$fieldName] = is_scalar($field['vDEF']) ? (string)$field['vDEF'] : '';
                }
            }
        }

        return $values;
    }

    /**
     * @return array<int, int>
     */
    private function getCategoryMap(): array {
        $map = $this->registry->get(self::REGISTRY_NAMESPACE, self::REGISTRY_CATEGORY_MAP);

        return is_array($map) ? array_map('intval', $map) : [];
    }
}
