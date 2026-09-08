<?php
declare(strict_types=1);

namespace HauerHeinrich\HhExtFaqs\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use HauerHeinrich\HhExtFaqs\Domain\Model\Faq;
use HauerHeinrich\HhExtFaqs\Domain\Repository\FaqRepository;
use HauerHeinrich\HhExtFaqs\Event\ModifyListVariablesEvent;
use HauerHeinrich\HhExtFaqs\Event\ModifySearchVariablesEvent;

class FaqController extends ActionController {

    public function __construct(
        protected readonly FaqRepository $faqRepository,
    ) {
    }

    /**
     * Render a FAQ list, either from directly selected records or from
     * the configured storage folders (tt_content.pages + recursive).
     */
    public function listAction(): ResponseInterface {
        $contentObjectData = $this->request->getAttribute('currentContentObject')?->data ?? [];

        $recordUids = GeneralUtility::intExplode(',', (string)($this->settings['records'] ?? ''), true);
        $categoryUids = GeneralUtility::intExplode(',', (string)($this->settings['categories'] ?? ''), true);
        $categoryConjunction = (string)($this->settings['categoryConjunction'] ?? 'or');
        $sortField = (string)($this->settings['sortField'] ?? 'sorting');
        $sortOrder = (string)($this->settings['sortOrder'] ?? 'asc');

        if ($recordUids !== []) {
            $faqs = $this->faqRepository->findByUids(
                $recordUids,
                $categoryUids,
                $categoryConjunction,
                $sortField,
                $sortOrder
            );
        } else {
            $storagePids = $this->resolveStoragePids($contentObjectData);
            $faqs = $storagePids === []
                ? []
                : $this->faqRepository->findByDemand(
                    $storagePids,
                    $categoryUids,
                    $categoryConjunction,
                    $sortField,
                    $sortOrder
                )->toArray();
        }

        $structuredData = '';
        if (!empty($this->settings['structuredData']) && $faqs !== []) {
            $structuredData = $this->buildStructuredData($faqs);
        }

        $variables = [
            'data' => $contentObjectData,
            'settings' => $this->settings,
            'faqs' => $faqs,
            'structuredData' => $structuredData,
        ];
        $event = $this->eventDispatcher->dispatch(new ModifyListVariablesEvent($variables, $contentObjectData));
        $this->view->assignMultiple($event->getVariables());

        return $this->htmlResponse();
    }

    /**
     * Render the frontend search form. The actual filtering happens
     * client-side in Resources/Public/JavaScript/FaqSearch.js.
     */
    public function searchAction(): ResponseInterface {
        $contentObjectData = $this->request->getAttribute('currentContentObject')?->data ?? [];
        $targetUids = GeneralUtility::intExplode(',', (string)($this->settings['listElements'] ?? ''), true);

        $variables = [
            'data' => $contentObjectData,
            'targets' => implode(',', $targetUids),
            'autoExpand' => empty($this->settings['autoExpand']) ? '0' : '1',
        ];
        $event = $this->eventDispatcher->dispatch(new ModifySearchVariablesEvent($variables, $contentObjectData));
        $this->view->assignMultiple($event->getVariables());

        return $this->htmlResponse();
    }

    /**
     * Resolve storage pids from the content element's "pages" and
     * "recursive" fields. Falls back to the current page.
     *
     * @param array<string, mixed> $contentObjectData
     * @return int[]
     */
    protected function resolveStoragePids(array $contentObjectData): array {
        $pids = GeneralUtility::intExplode(',', (string)($contentObjectData['pages'] ?? ''), true);
        if ($pids === []) {
            $currentPageId = (int)($this->request->getAttribute('frontend.page.information')?->getId() ?? 0);

            return $currentPageId > 0 ? [$currentPageId] : [];
        }

        $recursive = (int)($contentObjectData['recursive'] ?? 0);
        if ($recursive > 0) {
            $pageRepository = GeneralUtility::makeInstance(PageRepository::class);
            $pids = $pageRepository->getPageIdsRecursive($pids, $recursive);
        }

        return array_values(array_unique(array_map('intval', $pids)));
    }

    /**
     * Build schema.org FAQPage JSON-LD for the given FAQ records.
     *
     * @param Faq[] $faqs
     */
    protected function buildStructuredData(array $faqs): string {
        $entities = [];
        foreach ($faqs as $faq) {
            $answer = strip_tags(
                $faq->getAnswer(),
                '<h2><h3><br><ol><ul><li><a><p><b><strong><i><em>'
            );
            $entities[] = [
                '@type' => 'Question',
                'name' => $faq->getQuestion(),
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => $answer,
                ],
            ];
        }

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => $entities,
        ];

        return (string)json_encode(
            $schema,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
    }
}
