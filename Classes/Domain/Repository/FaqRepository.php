<?php
declare(strict_types=1);

namespace HauerHeinrich\HhExtFaqs\Domain\Repository;

use TYPO3\CMS\Extbase\Persistence\QueryInterface;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;
use TYPO3\CMS\Extbase\Persistence\Repository;
use HauerHeinrich\HhExtFaqs\Domain\Model\Faq;

/**
 * @extends Repository<Faq>
 */
class FaqRepository extends Repository {
    protected $defaultOrderings = [
        'sorting' => QueryInterface::ORDER_ASCENDING,
    ];

    /**
     * Find FAQ records from given storage folders, optionally filtered by categories.
     *
     * @param int[] $storagePids
     * @param int[] $categoryUids
     * @return QueryResultInterface<Faq>
     */
    public function findByDemand(
        array $storagePids,
        array $categoryUids = [],
        string $categoryConjunction = 'or',
        string $sortField = 'sorting',
        string $sortOrder = 'asc'
    ): QueryResultInterface {
        $query = $this->createQuery();
        $query->getQuerySettings()->setStoragePageIds($storagePids);
        $this->applyCategoryConstraint($query, $categoryUids, $categoryConjunction);
        $this->applyOrdering($query, $sortField, $sortOrder);

        return $query->execute();
    }

    /**
     * Find directly selected FAQ records (independent of storage page),
     * optionally filtered by categories.
     *
     * With $sortField = 'sorting' the manual selection order of the editor
     * is preserved, otherwise the given field ordering applies.
     *
     * @param int[] $uids
     * @param int[] $categoryUids
     * @return Faq[]
     */
    public function findByUids(
        array $uids,
        array $categoryUids = [],
        string $categoryConjunction = 'or',
        string $sortField = 'sorting',
        string $sortOrder = 'asc'
    ): array {
        $uids = array_values(array_filter(array_map('intval', $uids)));
        if ($uids === []) {
            return [];
        }

        $query = $this->createQuery();
        $query->getQuerySettings()->setRespectStoragePage(false);
        $constraints = [$query->in('uid', $uids)];
        $categoryConstraint = $this->buildCategoryConstraint($query, $categoryUids, $categoryConjunction);

        if ($categoryConstraint !== null) {
            $constraints[] = $categoryConstraint;
        }

        $query->matching($query->logicalAnd(...$constraints));

        if ($sortField === 'sorting') {
            // Preserve the editor's selection order
            $faqs = $query->execute()->toArray();
            usort(
                $faqs,
                static fn (Faq $a, Faq $b): int =>
                    array_search($a->getUid(), $uids, true) <=> array_search($b->getUid(), $uids, true)
            );

            if ($sortOrder === 'desc') {
                $faqs = array_reverse($faqs);
            }

            return $faqs;
        }

        $this->applyOrdering($query, $sortField, $sortOrder);

        return $query->execute()->toArray();
    }

    /**
     * @param int[] $categoryUids
     */
    protected function applyCategoryConstraint(
        QueryInterface $query,
        array $categoryUids,
        string $categoryConjunction
    ): void {
        $constraint = $this->buildCategoryConstraint($query, $categoryUids, $categoryConjunction);

        if ($constraint !== null) {
            $query->matching($constraint);
        }
    }

    /**
     * @param int[] $categoryUids
     */
    protected function buildCategoryConstraint(
        QueryInterface $query,
        array $categoryUids,
        string $categoryConjunction
    ): object|null {
        $categoryUids = array_values(array_filter(array_map('intval', $categoryUids)));

        if ($categoryUids === []) {
            return null;
        }

        $constraints = [];
        foreach ($categoryUids as $categoryUid) {
            $constraints[] = $query->contains('categories', $categoryUid);
        }

        if (count($constraints) === 1) {
            return $constraints[0];
        }

        return $categoryConjunction === 'and'
            ? $query->logicalAnd(...$constraints)
            : $query->logicalOr(...$constraints);
    }

    protected function applyOrdering(QueryInterface $query, string $sortField, string $sortOrder): void {
        $allowedFields = ['sorting', 'question', 'crdate'];

        if (!in_array($sortField, $allowedFields, true)) {
            $sortField = 'sorting';
        }

        $direction = $sortOrder === 'desc'
            ? QueryInterface::ORDER_DESCENDING
            : QueryInterface::ORDER_ASCENDING;

        $query->setOrderings([$sortField => $direction]);
    }
}
