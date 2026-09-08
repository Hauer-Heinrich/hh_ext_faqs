<?php
declare(strict_types=1);

namespace HauerHeinrich\HhExtFaqs\Event;

final class ModifyListVariablesEvent {
    public function __construct(
        private array $variables,
        private readonly array $contentElementData,
    ) {}

    public function getVariables(): array {
        return $this->variables;
    }

    public function addVariable(string $key, mixed $value): void {
        $this->variables[$key] = $value;
    }

    public function getContentElementData(): array {
        return $this->contentElementData;
    }
}
