<?php

declare(strict_types=1);

namespace YoungDogs\Translate\Service;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Reads the youngdogs_translate extension configuration settings.
 */
final class ExtensionSettings
{
    private string $apiUrl;
    private string $apiKey;
    private bool $autoEnableAfterTranslation;
    private bool $disableTranslateToPrefix;
    /** @var array<string, true> globally excluded field names */
    private array $excludedFields = [];
    /** @var array<string, array<string, true>> excluded field names per table */
    private array $excludedFieldsByTable = [];
    /** @var array<string, true> globally included field names */
    private array $includedFields = [];
    /** @var array<string, array<string, true>> included field names per table */
    private array $includedFieldsByTable = [];

    public function __construct(ExtensionConfiguration $extensionConfiguration)
    {
        /** @var array{apiUrl: string, apiKey: string, autoEnableAfterTranslation: string, disableTranslateToPrefix: string, excludeFields: string, includeFields: string} $config */
        $config = $extensionConfiguration->get('youngdogs_translate');
        $this->apiUrl = trim((string)($config['apiUrl'] ?? ''));
        $this->apiKey = trim((string)($config['apiKey'] ?? ''));
        $this->autoEnableAfterTranslation = (bool)($config['autoEnableAfterTranslation'] ?? false);
        $this->disableTranslateToPrefix = (bool)($config['disableTranslateToPrefix'] ?? false);

        $this->parseFieldList((string)($config['excludeFields'] ?? ''), $this->excludedFields, $this->excludedFieldsByTable);
        $this->parseFieldList((string)($config['includeFields'] ?? ''), $this->includedFields, $this->includedFieldsByTable);
    }

    /** @param array<string, true> $global @param array<string, array<string, true>> $byTable */
    private function parseFieldList(string $raw, array &$global, array &$byTable): void
    {
        foreach (explode(',', $raw) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            if (str_contains($entry, '.')) {
                [$table, $field] = explode('.', $entry, 2);
                $byTable[trim($table)][trim($field)] = true;
            } else {
                $global[$entry] = true;
            }
        }
    }

    public function getApiUrl(): string
    {
        return $this->apiUrl;
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    public function isConfigured(): bool
    {
        return $this->apiUrl !== '' && $this->apiKey !== '';
    }

    public function isAutoEnableAfterTranslation(): bool
    {
        return $this->autoEnableAfterTranslation;
    }

    public function isDisableTranslateToPrefix(): bool
    {
        return $this->disableTranslateToPrefix;
    }

    public function isFieldExcluded(string $table, string $field): bool
    {
        return isset($this->excludedFields[$field])
            || isset($this->excludedFieldsByTable[$table][$field]);
    }

    public function isFieldIncluded(string $table, string $field): bool
    {
        return isset($this->includedFields[$field])
            || isset($this->includedFieldsByTable[$table][$field]);
    }

    /** @return list<string> field names explicitly included for this table */
    public function getIncludedFieldsForTable(string $table): array
    {
        return array_keys(
            array_merge($this->includedFields, $this->includedFieldsByTable[$table] ?? [])
        );
    }
}
