<?php

declare(strict_types=1);

namespace YoungDogs\Translate\Service;

use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

/**
 * Tracks active translation state across the DataHandler hook pipeline.
 */
#[Autoconfigure(public: true)]
final class TranslationState
{
    private const CACHE_KEY = 'youngdogs-translate-state';

    public function __construct(
        #[Autowire(service: 'cache.runtime')]
        private readonly FrontendInterface $runtimeCache,
    ) {}

    public function activate(string $table, int|string $recordId): void
    {
        $this->runtimeCache->set(self::CACHE_KEY, [
            'active' => true,
            'table' => $table,
            'recordId' => $recordId,
            'sourceIso' => null,
            'targetIso' => null,
            'fieldCache' => [],   // pre-fetched field translations for processTranslateTo hook
        ]);
    }

    public function setLanguageCodes(string $sourceIso, string $targetIso): void
    {
        $state = $this->getState();
        $state['sourceIso'] = $sourceIso;
        $state['targetIso'] = $targetIso;
        $this->runtimeCache->set(self::CACHE_KEY, $state);
    }

    /**
     * Store pre-fetched batch translations keyed by field name.
     * Used by processTranslateTo_copyAction to avoid per-field API calls.
     *
     * @param array<string, string> $cache fieldName => translatedValue
     */
    public function setFieldTranslationCache(array $cache): void
    {
        $state = $this->getState();
        $state['fieldCache'] = $cache;
        $this->runtimeCache->set(self::CACHE_KEY, $state);
    }

    public function getCachedFieldTranslation(string $fieldName): ?string
    {
        return $this->getState()['fieldCache'][$fieldName] ?? null;
    }

    public function deactivate(): void
    {
        $this->runtimeCache->remove(self::CACHE_KEY);
    }

    public function isActive(): bool
    {
        return (bool)($this->getState()['active'] ?? false);
    }

    public function getSourceIso(): ?string
    {
        return $this->getState()['sourceIso'] ?? null;
    }

    public function getTargetIso(): ?string
    {
        return $this->getState()['targetIso'] ?? null;
    }

    public function getTable(): ?string
    {
        return $this->getState()['table'] ?? null;
    }

    public function getRecordId(): int|string|null
    {
        return $this->getState()['recordId'] ?? null;
    }

    /** @return array<string, mixed> */
    private function getState(): array
    {
        if (!$this->runtimeCache->has(self::CACHE_KEY)) {
            return [];
        }
        return (array)$this->runtimeCache->get(self::CACHE_KEY);
    }
}
