<?php
declare(strict_types=1);

namespace HauerHeinrich\HhExtFaqs\Domain\Model;

use TYPO3\CMS\Extbase\Annotation as Extbase;
use TYPO3\CMS\Extbase\Domain\Model\Category;
use TYPO3\CMS\Extbase\Domain\Model\FileReference;
use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;
use TYPO3\CMS\Extbase\Persistence\ObjectStorage;

class Faq extends AbstractEntity {
    protected string $question = '';
    protected string $answer = '';

    /**
     * @var ObjectStorage<FileReference>
     */
    #[Extbase\ORM\Lazy]
    protected ObjectStorage $media;

    /**
     * @var ObjectStorage<Category>
     */
    #[Extbase\ORM\Lazy]
    protected ObjectStorage $categories;

    public function __construct() {
        $this->initializeObject();
    }

    /**
     * Called again with persistence, as __construct() is not invoked then.
     */
    public function initializeObject(): void {
        $this->media ??= new ObjectStorage();
        $this->categories ??= new ObjectStorage();
    }

    public function getQuestion(): string { return $this->question; }
    public function setQuestion(string $question): void { $this->question = $question; }

    public function getAnswer(): string { return $this->answer; }
    public function setAnswer(string $answer): void { $this->answer = $answer; }

    /**
     * @return ObjectStorage<FileReference>
     */
    public function getMedia(): ObjectStorage { return $this->media; }
    /**
     * @param ObjectStorage<FileReference> $media
     */
    public function setMedia(ObjectStorage $media): void { $this->media = $media; }

    /**
     * @return ObjectStorage<Category>
     */
    public function getCategories(): ObjectStorage { return $this->categories; }
    /**
     * @param ObjectStorage<Category> $categories
     */
    public function setCategories(ObjectStorage $categories): void { $this->categories = $categories; }
}
