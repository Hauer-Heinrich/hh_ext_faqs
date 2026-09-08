<?php

declare(strict_types=1);

namespace HauerHeinrich\HhExtFaqs\Updates;

use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Install\Attribute\UpgradeWizard;
use TYPO3\CMS\Install\Updates\ChattyInterface;
use TYPO3\CMS\Install\Updates\DatabaseUpdatedPrerequisite;
use TYPO3\CMS\Install\Updates\RepeatableInterface;
use TYPO3\CMS\Install\Updates\UpgradeWizardInterface;

/**
 * Migrates EXT:jpfaq questions and categories to EXT:hh_ext_faqs.
 *
 * - jpfaq categories become sys_category records below a common
 *   parent category "FAQ Categories"
 * - jpfaq questions become tx_hhextfaqs_domain_model_faq records
 * - question/category relations are rebuilt in sys_category_record_mm
 *
 * The uid mapping (old => new) is stored in sys_registry (namespace
 * "tx_hhextfaqs") so the plugin migration wizard can reuse it. The wizard
 * is repeatable and idempotent: already migrated records are skipped.
 */
#[UpgradeWizard('hhExtFaqs_jpfaqRecordMigration')]
final class JpfaqRecordMigration implements UpgradeWizardInterface, ChattyInterface, RepeatableInterface {

    private const REGISTRY_NAMESPACE = 'tx_hhextfaqs';
    private const REGISTRY_CATEGORY_MAP = 'jpfaqCategoryMap';
    private const REGISTRY_QUESTION_MAP = 'jpfaqQuestionMap';
    private const REGISTRY_PARENT_CATEGORY = 'jpfaqParentCategory';

    private const TABLE_JPFAQ_QUESTION = 'tx_jpfaq_domain_model_question';
    private const TABLE_JPFAQ_CATEGORY = 'tx_jpfaq_domain_model_category';
    private const TABLE_JPFAQ_MM = 'tx_jpfaq_question_category_mm';
    private const TABLE_FAQ = 'tx_hhextfaqs_domain_model_faq';
    private const TABLE_SYS_CATEGORY = 'sys_category';
    private const TABLE_SYS_CATEGORY_MM = 'sys_category_record_mm';

    private const PARENT_CATEGORY_TITLE = 'FAQ Categories';

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
        return 'Migrate jpfaq records to hh_ext_faqs';
    }

    public function getDescription(): string {
        return 'Migrates questions from EXT:jpfaq to tx_hhextfaqs_domain_model_faq and jpfaq categories '
            . 'to sys_category below a new parent category "' . self::PARENT_CATEGORY_TITLE . '". '
            . 'Relations between questions and categories are rebuilt. Comments, helpful counters and '
            . 'additional content elements (IRRE) of jpfaq are not migrated. '
            . 'Run this wizard before "Migrate jpfaq plugins to hh_ext_faqs".';
    }

    public function getPrerequisites(): array {
        return [
            DatabaseUpdatedPrerequisite::class,
        ];
    }

    public function updateNecessary(): bool {
        if (!$this->jpfaqTablesExist()) {
            return false;
        }

        return $this->countUnmigrated(self::TABLE_JPFAQ_CATEGORY, $this->getCategoryMap()) > 0
            || $this->countUnmigrated(self::TABLE_JPFAQ_QUESTION, $this->getQuestionMap()) > 0;
    }

    public function executeUpdate(): bool {
        if (!$this->jpfaqTablesExist()) {
            $this->output->writeln('jpfaq tables not found, nothing to do.');

            return true;
        }

        $categoryMap = $this->getCategoryMap();
        $questionMap = $this->getQuestionMap();

        $parentCategoryUid = $this->ensureParentCategory();
        $this->output->writeln(sprintf(
            'Using parent category "%s" (sys_category:%d).',
            self::PARENT_CATEGORY_TITLE,
            $parentCategoryUid
        ));

        $categoryMap = $this->migrateCategories($parentCategoryUid, $categoryMap);
        $this->registry->set(self::REGISTRY_NAMESPACE, self::REGISTRY_CATEGORY_MAP, $categoryMap);

        $questionMap = $this->migrateQuestions($questionMap);
        $this->registry->set(self::REGISTRY_NAMESPACE, self::REGISTRY_QUESTION_MAP, $questionMap);

        $this->migrateRelations($categoryMap, $questionMap);
        $this->updateCategoryCounters($questionMap);

        $this->output->writeln(sprintf(
            'Migration finished: %d categories, %d questions mapped.',
            count($categoryMap),
            count($questionMap)
        ));

        return true;
    }

    /**
     * Find or create the common parent category for all migrated categories.
     */
    private function ensureParentCategory(): int {
        $storedUid = (int)($this->registry->get(self::REGISTRY_NAMESPACE, self::REGISTRY_PARENT_CATEGORY) ?? 0);
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE_SYS_CATEGORY);

        if ($storedUid > 0) {
            $queryBuilder = $connection->createQueryBuilder();
            $exists = $queryBuilder
                ->count('uid')
                ->from(self::TABLE_SYS_CATEGORY)
                ->where(
                    $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($storedUid, Connection::PARAM_INT))
                )
                ->executeQuery()
                ->fetchOne();

            if ((int)$exists > 0) {
                return $storedUid;
            }
        }

        // Try to find an existing category with the expected title on root level
        $queryBuilder = $connection->createQueryBuilder();
        $existingUid = $queryBuilder
            ->select('uid')
            ->from(self::TABLE_SYS_CATEGORY)
            ->where(
                $queryBuilder->expr()->eq('title', $queryBuilder->createNamedParameter(self::PARENT_CATEGORY_TITLE)),
                $queryBuilder->expr()->eq('parent', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->executeQuery()
            ->fetchOne();

        if ($existingUid !== false && (int)$existingUid > 0) {
            $this->registry->set(self::REGISTRY_NAMESPACE, self::REGISTRY_PARENT_CATEGORY, (int)$existingUid);

            return (int)$existingUid;
        }

        // Create it on the pid of the first jpfaq category (fallback: 0)
        $jpfaqConnection = $this->connectionPool->getConnectionForTable(self::TABLE_JPFAQ_CATEGORY);
        $queryBuilder = $jpfaqConnection->createQueryBuilder();
        $pid = $queryBuilder
            ->select('pid')
            ->from(self::TABLE_JPFAQ_CATEGORY)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();
        $pid = $pid === false ? 0 : (int)$pid;

        $now = time();
        $connection->insert(self::TABLE_SYS_CATEGORY, [
            'pid' => $pid,
            'title' => self::PARENT_CATEGORY_TITLE,
            'parent' => 0,
            'sys_language_uid' => 0,
            'crdate' => $now,
            'tstamp' => $now,
        ]);

        $parentUid = (int)$connection->lastInsertId();
        $this->registry->set(self::REGISTRY_NAMESPACE, self::REGISTRY_PARENT_CATEGORY, $parentUid);

        return $parentUid;
    }

    /**
     * @param array<int, int> $categoryMap old jpfaq category uid => new sys_category uid
     * @return array<int, int>
     */
    private function migrateCategories(int $parentCategoryUid, array $categoryMap): array {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE_SYS_CATEGORY);
        $rows = $this->fetchSourceRows(self::TABLE_JPFAQ_CATEGORY);

        // First pass: records in default language / language "all"
        foreach ($rows as $row) {
            if ((int)$row['sys_language_uid'] > 0 || isset($categoryMap[(int)$row['uid']])) {
                continue;
            }

            $connection->insert(self::TABLE_SYS_CATEGORY, [
                'pid' => (int)$row['pid'],
                'title' => (string)$row['category'],
                'description' => (string)$row['description'],
                'parent' => $parentCategoryUid,
                'sorting' => (int)$row['sorting'],
                'hidden' => (int)$row['hidden'],
                'starttime' => (int)$row['starttime'],
                'endtime' => (int)$row['endtime'],
                'sys_language_uid' => (int)$row['sys_language_uid'],
                'crdate' => (int)$row['crdate'],
                'tstamp' => (int)$row['tstamp'],
            ]);

            $categoryMap[(int)$row['uid']] = (int)$connection->lastInsertId();
        }

        // Second pass: translations, pointing to the mapped default language record
        foreach ($rows as $row) {
            if ((int)$row['sys_language_uid'] <= 0 || isset($categoryMap[(int)$row['uid']])) {
                continue;
            }

            $oldParent = (int)$row['l10n_parent'];
            $newL10nParent = $categoryMap[$oldParent] ?? 0;

            if ($oldParent > 0 && $newL10nParent === 0) {
                $this->output->writeln(sprintf(
                    'Skipped category translation %d: default language record %d not migrated.',
                    (int)$row['uid'],
                    $oldParent
                ));
                continue;
            }

            $connection->insert(self::TABLE_SYS_CATEGORY, [
                'pid' => (int)$row['pid'],
                'title' => (string)$row['category'],
                'description' => (string)$row['description'],
                'parent' => $parentCategoryUid,
                'sorting' => (int)$row['sorting'],
                'hidden' => (int)$row['hidden'],
                'starttime' => (int)$row['starttime'],
                'endtime' => (int)$row['endtime'],
                'sys_language_uid' => (int)$row['sys_language_uid'],
                'l10n_parent' => $newL10nParent,
                'crdate' => (int)$row['crdate'],
                'tstamp' => (int)$row['tstamp'],
            ]);

            $categoryMap[(int)$row['uid']] = (int)$connection->lastInsertId();
        }

        return $categoryMap;
    }

    /**
     * @param array<int, int> $questionMap old jpfaq question uid => new faq uid
     * @return array<int, int>
     */
    private function migrateQuestions(array $questionMap): array {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE_FAQ);
        $rows = $this->fetchSourceRows(self::TABLE_JPFAQ_QUESTION);

        // First pass: default language / language "all"
        foreach ($rows as $row) {
            if ((int)$row['sys_language_uid'] > 0 || isset($questionMap[(int)$row['uid']])) {
                continue;
            }

            $connection->insert(self::TABLE_FAQ, [
                'pid' => (int)$row['pid'],
                'question' => (string)$row['question'],
                'answer' => (string)$row['answer'],
                'sorting' => (int)$row['sorting'],
                'hidden' => (int)$row['hidden'],
                'starttime' => (int)$row['starttime'],
                'endtime' => (int)$row['endtime'],
                'sys_language_uid' => (int)$row['sys_language_uid'],
                'crdate' => (int)$row['crdate'],
                'tstamp' => (int)$row['tstamp'],
            ]);

            $questionMap[(int)$row['uid']] = (int)$connection->lastInsertId();
        }

        // Second pass: translations
        foreach ($rows as $row) {
            if ((int)$row['sys_language_uid'] <= 0 || isset($questionMap[(int)$row['uid']])) {
                continue;
            }
            $oldParent = (int)$row['l10n_parent'];
            $newL10nParent = $questionMap[$oldParent] ?? 0;

            if ($oldParent > 0 && $newL10nParent === 0) {
                $this->output->writeln(sprintf(
                    'Skipped question translation %d: default language record %d not migrated.',
                    (int)$row['uid'],
                    $oldParent
                ));
                continue;
            }

            $connection->insert(self::TABLE_FAQ, [
                'pid' => (int)$row['pid'],
                'question' => (string)$row['question'],
                'answer' => (string)$row['answer'],
                'sorting' => (int)$row['sorting'],
                'hidden' => (int)$row['hidden'],
                'starttime' => (int)$row['starttime'],
                'endtime' => (int)$row['endtime'],
                'sys_language_uid' => (int)$row['sys_language_uid'],
                'l10n_parent' => $newL10nParent,
                'l10n_source' => $newL10nParent,
                'crdate' => (int)$row['crdate'],
                'tstamp' => (int)$row['tstamp'],
            ]);

            $questionMap[(int)$row['uid']] = (int)$connection->lastInsertId();
        }

        return $questionMap;
    }

    /**
     * Rebuild question/category relations in sys_category_record_mm.
     *
     * jpfaq MM: uid_local = question, uid_foreign = category
     * sys_category MM: uid_local = category, uid_foreign = record
     *
     * @param array<int, int> $categoryMap
     * @param array<int, int> $questionMap
     */
    private function migrateRelations(array $categoryMap, array $questionMap): void {
        $mmConnection = $this->connectionPool->getConnectionForTable(self::TABLE_SYS_CATEGORY_MM);
        $sourceConnection = $this->connectionPool->getConnectionForTable(self::TABLE_JPFAQ_MM);

        $relations = $sourceConnection
            ->createQueryBuilder()
            ->select('*')
            ->from(self::TABLE_JPFAQ_MM)
            ->executeQuery()
            ->fetchAllAssociative();

        $migrated = 0;
        $skipped = 0;
        foreach ($relations as $relation) {
            $newFaqUid = $questionMap[(int)$relation['uid_local']] ?? 0;
            $newCategoryUid = $categoryMap[(int)$relation['uid_foreign']] ?? 0;

            if ($newFaqUid === 0 || $newCategoryUid === 0) {
                $skipped++;
                continue;
            }

            $queryBuilder = $mmConnection->createQueryBuilder();
            $exists = $queryBuilder
                ->count('*')
                ->from(self::TABLE_SYS_CATEGORY_MM)
                ->where(
                    $queryBuilder->expr()->eq('uid_local', $queryBuilder->createNamedParameter($newCategoryUid, Connection::PARAM_INT)),
                    $queryBuilder->expr()->eq('uid_foreign', $queryBuilder->createNamedParameter($newFaqUid, Connection::PARAM_INT)),
                    $queryBuilder->expr()->eq('tablenames', $queryBuilder->createNamedParameter(self::TABLE_FAQ)),
                    $queryBuilder->expr()->eq('fieldname', $queryBuilder->createNamedParameter('categories'))
                )
                ->executeQuery()
                ->fetchOne();

            if ((int)$exists > 0) {
                continue;
            }

            $mmConnection->insert(self::TABLE_SYS_CATEGORY_MM, [
                'uid_local' => $newCategoryUid,
                'uid_foreign' => $newFaqUid,
                'tablenames' => self::TABLE_FAQ,
                'fieldname' => 'categories',
                'sorting' => (int)$relation['sorting'],
                'sorting_foreign' => (int)$relation['sorting_foreign'],
            ]);

            $migrated++;
        }

        $this->output->writeln(sprintf('Relations: %d migrated, %d skipped.', $migrated, $skipped));
    }

    /**
     * Update the "categories" counter field of all migrated FAQ records.
     *
     * @param array<int, int> $questionMap
     */
    private function updateCategoryCounters(array $questionMap): void {
        $faqConnection = $this->connectionPool->getConnectionForTable(self::TABLE_FAQ);
        $mmConnection = $this->connectionPool->getConnectionForTable(self::TABLE_SYS_CATEGORY_MM);

        foreach ($questionMap as $newFaqUid) {
            $queryBuilder = $mmConnection->createQueryBuilder();
            $count = $queryBuilder
                ->count('*')
                ->from(self::TABLE_SYS_CATEGORY_MM)
                ->where(
                    $queryBuilder->expr()->eq('uid_foreign', $queryBuilder->createNamedParameter($newFaqUid, Connection::PARAM_INT)),
                    $queryBuilder->expr()->eq('tablenames', $queryBuilder->createNamedParameter(self::TABLE_FAQ)),
                    $queryBuilder->expr()->eq('fieldname', $queryBuilder->createNamedParameter('categories'))
                )
                ->executeQuery()
                ->fetchOne();

            $faqConnection->update(
                self::TABLE_FAQ,
                ['categories' => (int)$count],
                ['uid' => $newFaqUid]
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchSourceRows(string $table): array {
        $queryBuilder = $this->connectionPool->getConnectionForTable($table)->createQueryBuilder();

        return $queryBuilder
            ->select('*')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->orderBy('sorting', 'ASC')
            ->addOrderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    private function countUnmigrated(string $table, array $map): int {
        $queryBuilder = $this->connectionPool->getConnectionForTable($table)->createQueryBuilder();
        $queryBuilder
            ->count('uid')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
            );

        $migratedUids = array_keys($map);
        if ($migratedUids !== []) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->notIn(
                    'uid',
                    $queryBuilder->createNamedParameter($migratedUids, Connection::PARAM_INT_ARRAY)
                )
            );
        }

        return (int)$queryBuilder->executeQuery()->fetchOne();
    }

    private function jpfaqTablesExist(): bool {
        $schemaManager = $this->connectionPool
            ->getConnectionForTable(self::TABLE_JPFAQ_QUESTION)
            ->createSchemaManager();
        $tableNames = $schemaManager->listTableNames();

        return in_array(self::TABLE_JPFAQ_QUESTION, $tableNames, true)
            && in_array(self::TABLE_JPFAQ_CATEGORY, $tableNames, true)
            && in_array(self::TABLE_JPFAQ_MM, $tableNames, true);
    }

    /**
     * @return array<int, int>
     */
    private function getCategoryMap(): array {
        $map = $this->registry->get(self::REGISTRY_NAMESPACE, self::REGISTRY_CATEGORY_MAP);

        return is_array($map) ? array_map('intval', $map) : [];
    }

    /**
     * @return array<int, int>
     */
    private function getQuestionMap(): array {
        $map = $this->registry->get(self::REGISTRY_NAMESPACE, self::REGISTRY_QUESTION_MAP);

        return is_array($map) ? array_map('intval', $map) : [];
    }
}
