<?php

declare(strict_types=1);

namespace YoungDogs\Translate\Hooks;

use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\MathUtility;
use YoungDogs\Translate\Service\ExtensionSettings;
use YoungDogs\Translate\Service\LanguageResolver;
use YoungDogs\Translate\Service\TranslationApiClient;
use YoungDogs\Translate\Service\TranslationState;

/**
 * DataHandler hooks for the Young Dogs AI translation pipeline.
 *
 * processCmdmap              – activated by the 'youngdogsai' DataHandler command.
 * processTranslateTo         – per-field hook for fields with l10n_mode=prefixLangTitle.
 * processDatamap_preProcess  – translates all other text fields, strips prefix, auto-enables.
 */
#[Autoconfigure(public: true)]
final class TranslateHook
{
    // Field types that may contain translatable user text
    private const TRANSLATABLE_TYPES = ['input', 'text'];

    public function __construct(
        private readonly TranslationState $translationState,
        private readonly TranslationApiClient $apiClient,
        private readonly LanguageResolver $languageResolver,
        private readonly FlashMessageService $flashMessageService,
        private readonly ExtensionSettings $settings,
        private readonly ConnectionPool $connectionPool,
    ) {}

    // -------------------------------------------------------------------------
    // processCmdmapClass hook
    // -------------------------------------------------------------------------

    public function processCmdmap(
        string $command,
        string $table,
        int|string $id,
        mixed $value,
        bool &$commandIsProcessed,
        DataHandler $dataHandler,
        mixed $pasteUpdate
    ): void {
        if ($command !== 'youngdogsai' || $commandIsProcessed !== false) {
            return;
        }

        $this->translationState->activate($table, $id);

        // Resolve and cache language ISO codes so processDatamap_preProcessFieldArray
        // can use them for inline children (which don't go through processCmdmap).
        $this->cacheLanguageCodes($table, $id, (int)$value);

        // Pre-fetch all prefixLangTitle fields in one batch so processTranslateTo_copyAction
        // can do a cache lookup instead of an individual API call per field.
        $this->preFetchPrefixLangTitleFields($table, (int)$id);

        // Reflect DataHandler private property to enable transOrigPointerField
        // (same approach used by deepltranslate-core)
        $reflection = new \ReflectionProperty($dataHandler, 'useTransOrigPointerField');
        $backup = $reflection->getValue($dataHandler);
        $reflection->setValue($dataHandler, true);

        $dataHandler->localize($table, (int)$id, $value);

        $reflection->setValue($dataHandler, $backup);

        // For fields in includeFields that were not passed through processDatamap
        // (e.g. pages.nav_title which localizePage omits from $overrideValues),
        // translate them directly after the record is created.
        if ($translatedUid !== false) {
            $this->translateIncludedFieldsPostLocalize($table, (int)$id, (int)$translatedUid);
        }

        $commandIsProcessed = true;
    }

    // -------------------------------------------------------------------------
    // processDatamapClass hook
    // -------------------------------------------------------------------------

    /**
     * For every new translation record (top-level and inline children):
     *   1. Strips the "[Translate to X:]" prefix when the setting is enabled.
     *   2. Translates all non-excluded text/input fields that were NOT already
     *      translated by processTranslateTo_copyAction (i.e. fields without
     *      l10n_mode=prefixLangTitle like bootstrap_package card titles).
     *   3. Auto-enables the record when the setting is enabled.
     *
     * @param array<string, mixed> $fieldArray
     * @param int|string $id
     */
    public function processDatamap_preProcessFieldArray(
        array &$fieldArray,
        string $table,
        int|string $id,
        DataHandler $dataHandler
    ): void {
        // Only act on new records (localized records are always created as 'NEW...')
        if (!str_starts_with((string)$id, 'NEW')) {
            return;
        }

        // Only translation records have a transOrigPointerField value
        $transOrigPointerField = $GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField'] ?? null;
        if ($transOrigPointerField === null || empty($fieldArray[$transOrigPointerField])) {
            return;
        }

        $isOurMode = $this->translationState->isActive();
        $sourceIso = $this->translationState->getSourceIso();
        $targetIso = $this->translationState->getTargetIso();
        $canTranslate = $isOurMode && $sourceIso !== null && $targetIso !== null;
        $columns = $GLOBALS['TCA'][$table]['columns'] ?? [];

        // Strip prefix first (all fields, independent of translation)
        if ($this->settings->isDisableTranslateToPrefix()) {
            foreach ($fieldArray as $field => &$value) {
                if (!is_string($value) || $value === '') {
                    continue;
                }
                $value = preg_replace('/^\[[^\]]+\]\s+/', '', $value) ?? $value;
                $value = preg_replace('/(<[^>]+>)\[[^\]]+\]\s+/', '$1', $value) ?? $value;
            }
            unset($value);
        }

        // Collect all fields that need translation in a single pass, then batch-translate.
        if ($canTranslate) {
            $toTranslate = []; // field => value
            foreach ($fieldArray as $field => $value) {
                if (!is_string($value) || $value === '' || MathUtility::canBeInterpretedAsInteger($value)) {
                    continue;
                }
                $l10nMode = $columns[$field]['l10n_mode'] ?? '';
                $fieldType = $columns[$field]['config']['type'] ?? '';

                $isForceIncluded = $this->settings->isFieldIncluded($table, $field)
                    && !$this->settings->isFieldExcluded($table, $field)
                    && $l10nMode !== 'exclude';

                $isAutoTranslatable = $l10nMode !== 'exclude'
                    && $l10nMode !== 'prefixLangTitle'
                    && in_array($fieldType, self::TRANSLATABLE_TYPES, true)
                    && $this->isContentField($columns[$field]['config'] ?? [], $fieldType)
                    && !$this->looksLikeIdentifier($value)
                    && !$this->settings->isFieldExcluded($table, $field);

                if ($isForceIncluded || $isAutoTranslatable) {
                    $toTranslate[$field] = $value;
                }
            }

            if ($toTranslate !== []) {
                // translateBatch sends {fieldName: text} → returns {fieldName: translation} — no index dependency
                $translated = $this->apiClient->translateBatch($toTranslate, $sourceIso, $targetIso);
                foreach ($translated as $field => $translation) {
                    // Only apply when translation actually differs from the source and is a plain string
                    if (is_string($translation) && $translation !== '' && $translation !== ($toTranslate[$field] ?? '')) {
                        $fieldArray[$field] = $translation;
                    }
                }
            }
        }

        // Auto-enable: set hidden=0 for all new translation records (top-level + inline children)
        if ($this->settings->isAutoEnableAfterTranslation()) {
            $disabledField = $GLOBALS['TCA'][$table]['ctrl']['enablecolumns']['disabled'] ?? null;
            if ($disabledField !== null && array_key_exists($disabledField, $fieldArray)) {
                $fieldArray[$disabledField] = 0;
            }
        }
    }

    // -------------------------------------------------------------------------
    // processTranslateToClass hook
    // -------------------------------------------------------------------------

    /**
     * Translates fields with l10n_mode=prefixLangTitle (e.g. tt_content header, bodytext).
     * Called by TYPO3 DataHandler for every such field during localize().
     *
     * @param string $content Field value (passed by reference).
     * @param array{uid: int} $languageRecord Target language record.
     * @param string $field Field name passed by TYPO3 as 4th argument.
     */
    public function processTranslateTo_copyAction(
        string &$content,
        array $languageRecord,
        DataHandler $dataHandler,
        string $field = ''
    ): void {
        if (MathUtility::canBeInterpretedAsInteger($content) || $content === '') {
            return;
        }

        if (!$this->translationState->isActive()) {
            return;
        }

        $sourceIso = $this->translationState->getSourceIso();
        $targetIso = $this->translationState->getTargetIso();

        if ($sourceIso === null || $targetIso === null) {
            return;
        }

        // Use pre-fetched batch translation when available — but only when it differs
        // from the original; if the cache holds the source text (e.g. API failed during
        // pre-fetch) we fall through to the individual translate() call instead.
        if ($field !== '') {
            $cached = $this->translationState->getCachedFieldTranslation($field);
            if ($cached !== null && $cached !== '' && $cached !== $content) {
                $content = $cached;
                return;
            }
        }

        $translated = $this->apiClient->translate($content, $sourceIso, $targetIso);

        if ($translated !== '' && $translated !== $content) {
            $content = $translated;
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Translates explicitly included fields that were not saved via processDatamap
     * (e.g. pages.nav_title, which localizePage omits from $overrideValues).
     * Fetches source value, translates, and updates the translated record directly.
     */
    private function translateIncludedFieldsPostLocalize(string $table, int $sourceUid, int $translatedUid): void
    {
        $sourceIso = $this->translationState->getSourceIso();
        $targetIso = $this->translationState->getTargetIso();
        if ($sourceIso === null || $targetIso === null) {
            return;
        }

        $fields = $this->settings->getIncludedFieldsForTable($table);
        if ($fields === []) {
            return;
        }

        $sourceRecord = BackendUtility::getRecord($table, $sourceUid, implode(',', $fields));
        $translatedRecord = BackendUtility::getRecord($table, $translatedUid, implode(',', $fields));
        if ($sourceRecord === null || $translatedRecord === null) {
            return;
        }

        $toTranslate = [];
        foreach ($fields as $field) {
            $sourceValue = (string)($sourceRecord[$field] ?? '');
            $translatedValue = (string)($translatedRecord[$field] ?? '');
            if ($sourceValue === '' || MathUtility::canBeInterpretedAsInteger($sourceValue)) {
                continue;
            }
            if ($translatedValue !== '' && $translatedValue !== $sourceValue) {
                continue;
            }
            $toTranslate[$field] = $sourceValue;
        }

        if ($toTranslate === []) {
            return;
        }

        $translated = $this->apiClient->translateBatch($toTranslate, $sourceIso, $targetIso);
        $updates = [];
        foreach ($toTranslate as $field => $original) {
            $result = $translated[$field] ?? '';
            if ($result !== '' && $result !== $original) {
                $updates[$field] = $result;
            }
        }

        if ($updates !== []) {
            $this->connectionPool
                ->getConnectionForTable($table)
                ->update($table, $updates, ['uid' => $translatedUid]);
        }
    }

    /**
     * Batch-translates all prefixLangTitle fields of a record before localize() runs.
     * Results are stored in TranslationState so processTranslateTo_copyAction can do
     * a cache lookup (1 API call per record instead of 1 per field).
     */
    private function preFetchPrefixLangTitleFields(string $table, int $id): void
    {
        $sourceIso = $this->translationState->getSourceIso();
        $targetIso = $this->translationState->getTargetIso();
        if ($sourceIso === null || $targetIso === null) {
            return;
        }

        $record = BackendUtility::getRecord($table, $id);
        if ($record === null) {
            return;
        }

        $fields = [];
        $texts = []; // fieldName => value (keyed so translateBatch returns a named map)
        foreach ($GLOBALS['TCA'][$table]['columns'] ?? [] as $fieldName => $fieldConfig) {
            if (($fieldConfig['l10n_mode'] ?? '') !== 'prefixLangTitle') {
                continue;
            }
            $value = (string)($record[$fieldName] ?? '');
            if ($value === '' || MathUtility::canBeInterpretedAsInteger($value)) {
                continue;
            }
            $texts[$fieldName] = $value;
        }

        if ($texts === []) {
            return;
        }

        // translateBatch sends {fieldName: text} and receives {fieldName: translation} — order-safe
        $translated = $this->apiClient->translateBatch($texts, $sourceIso, $targetIso);
        $this->translationState->setFieldTranslationCache($translated);
    }

    private function cacheLanguageCodes(string $table, int|string $id, int $targetLanguageUid): void
    {
        $record = BackendUtility::getRecord($table, (int)$id);
        if ($record === null) {
            return;
        }

        $pageId = match ($table) {
            'pages' => (int)($record['uid'] ?? 0),
            default => (int)($record['pid'] ?? 0),
        };

        $site = $this->languageResolver->getSiteByPageId($pageId);
        if ($site === null) {
            return;
        }

        $sourceLanguageUid = 0;
        $languageField = $GLOBALS['TCA'][$table]['ctrl']['languageField'] ?? null;
        if ($languageField !== null && isset($record[$languageField])) {
            $sourceLanguageUid = (int)$record[$languageField];
        }

        $sourceIso = $this->languageResolver->getIsoCodeForLanguageUid($site, $sourceLanguageUid);
        $targetIso = $this->languageResolver->getIsoCodeForLanguageUid($site, $targetLanguageUid);

        if ($sourceIso !== null && $targetIso !== null) {
            $this->translationState->setLanguageCodes($sourceIso, $targetIso);
        }
    }

    private function addFlashMessage(string $message, ContextualFeedbackSeverity $severity): void
    {
        $flashMessage = new FlashMessage($message, '', $severity);
        $this->flashMessageService
            ->getMessageQueueByIdentifier()
            ->addMessage($flashMessage);
    }

    /**
     * Returns true only for fields that contain user-facing text content.
     *
     * - type=text (multi-line) is always user content.
     * - type=input must have at least one content signal to distinguish it from
     *   internal reference fields like sys_file_reference.tablenames / .fieldname
     *   which are type=input but store PHP identifiers, not human text.
     *
     * @param array<string, mixed> $config TCA field config array
     */
    private function isContentField(array $config, string $fieldType): bool
    {
        if ($fieldType === 'text') {
            return true;
        }
        // input: require at least one "user content" signal
        $eval = $config['eval'] ?? '';
        $required = (bool)($config['required'] ?? false);
        $softref = $config['softref'] ?? '';
        $enableRichtext = (bool)($config['enableRichtext'] ?? false);
        return $required || str_contains($eval, 'trim') || $softref !== '' || $enableRichtext;
    }

    /**
     * Returns true when the value looks like a PHP/TYPO3 internal identifier
     * (snake_case table name, field name, CSS class, etc.) rather than user text.
     *
     * e.g. "tx_bootstrappackage_carousel_item", "sys_file_reference", "my_field"
     * are all-lowercase with at least one underscore — never human-readable content.
     */
    private function looksLikeIdentifier(string $value): bool
    {
        return (bool)preg_match('/^[a-z][a-z0-9]*(_[a-z0-9]+)+$/', $value);
    }
}

