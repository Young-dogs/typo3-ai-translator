<?php

declare(strict_types=1);

namespace YoungDogs\Translate\Backend\Localization;

use TYPO3\CMS\Backend\Domain\Repository\Localization\LocalizationRepository;
use TYPO3\CMS\Backend\Localization\Finisher\NoopLocalizationFinisher;
use TYPO3\CMS\Backend\Localization\Finisher\RedirectLocalizationFinisher;
use TYPO3\CMS\Backend\Localization\Finisher\ReloadLocalizationFinisher;
use TYPO3\CMS\Backend\Localization\LocalizationHandlerInterface;
use TYPO3\CMS\Backend\Localization\LocalizationInstructions;
use TYPO3\CMS\Backend\Localization\LocalizationMode;
use TYPO3\CMS\Backend\Localization\LocalizationResult;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use YoungDogs\Translate\Service\TranslationApiClient;

/**
 * TYPO3 14 localization handler that delegates translation to the Young Dogs AI service.
 *
 * Registered automatically via the 'backend.localization.handler' DI tag
 * (TYPO3 autoconfigures all LocalizationHandlerInterface implementations).
 */
final readonly class YdTranslateLocalizationHandler implements LocalizationHandlerInterface
{
    public function __construct(
        private TranslationApiClient $apiClient,
        private LocalizationRepository $localizationRepository,
        private UriBuilder $uriBuilder,
    ) {}

    public function getIdentifier(): string
    {
        return 'youngdogsai';
    }

    public function getLabel(): string
    {
        return 'LLL:EXT:youngdogs_translate/Resources/Private/Language/locallang.xlf:handler.label';
    }

    public function getDescription(): string
    {
        return 'LLL:EXT:youngdogs_translate/Resources/Private/Language/locallang.xlf:handler.description';
    }

    public function getIconIdentifier(): string
    {
        return 'actions-localize';
    }

    public function isAvailable(LocalizationInstructions $instructions): bool
    {
        // Only available for the translate (not copy) mode
        if ($instructions->mode !== LocalizationMode::TRANSLATE) {
            return false;
        }

        // Requires API URL and key to be configured in extension settings
        return $this->apiClient->isConfigured();
    }

    public function processLocalization(LocalizationInstructions $instructions): LocalizationResult
    {
        return match ($instructions->mainRecordType) {
            'pages' => $this->processPageLocalization(
                $instructions->recordUid,
                $instructions->targetLanguageId,
                $instructions->additionalData
            ),
            default => $this->processSingleRecordLocalization(
                $instructions->mainRecordType,
                $instructions->recordUid,
                $instructions->targetLanguageId,
                $instructions->returnUrl
            ),
        };
    }

    // -------------------------------------------------------------------------

    private function processSingleRecordLocalization(string $table, int $uid, int $targetLanguageId, ?string $returnUrl = null): LocalizationResult
    {
        $existingTranslation = $this->localizationRepository->getRecordTranslation($table, $uid, $targetLanguageId);
        if ($existingTranslation !== null) {
            return LocalizationResult::success(new NoopLocalizationFinisher());
        }

        $cmd = [
            $table => [
                $uid => ['youngdogsai' => $targetLanguageId],
            ],
        ];

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], $cmd);
        $dataHandler->process_cmdmap();

        if ($dataHandler->errorLog !== []) {
            return LocalizationResult::error($dataHandler->errorLog);
        }

        $newUid = $dataHandler->copyMappingArray_merged[$table][$uid] ?? null;
        if ($newUid === null) {
            $translation = $this->localizationRepository->getRecordTranslation($table, $uid, $targetLanguageId);
            $newUid = $translation?->getUid();
        }

        $redirectUrl = $this->buildRedirectUrl($table, $uid, $targetLanguageId, $returnUrl);

        return LocalizationResult::success(
            $redirectUrl !== null
                ? new RedirectLocalizationFinisher($redirectUrl)
                : new ReloadLocalizationFinisher()
        );
    }

    /** @param array{selectedRecordUids?: int[]} $additionalData */
    private function processPageLocalization(int $pageUid, int $targetLanguageId, array $additionalData): LocalizationResult
    {
        $cmd = [];

        $existingPageTranslation = $this->localizationRepository->getPageTranslations(
            $pageUid,
            [$targetLanguageId],
            $GLOBALS['BE_USER']->workspace ?? 0
        );
        if ($existingPageTranslation === []) {
            $cmd['pages'] = [
                $pageUid => ['youngdogsai' => $targetLanguageId],
            ];
        }

        $selectedContent = $additionalData['selectedRecordUids'] ?? [];
        if ($selectedContent !== []) {
            $cmd['tt_content'] = [];
            foreach ($selectedContent as $contentUid) {
                $cmd['tt_content'][(int)$contentUid] = ['youngdogsai' => $targetLanguageId];
            }
        }

        if ($cmd === []) {
            return LocalizationResult::success(new NoopLocalizationFinisher());
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], $cmd);
        $dataHandler->process_cmdmap();

        if ($dataHandler->errorLog !== []) {
            return LocalizationResult::error($dataHandler->errorLog);
        }

        $redirectUrl = $this->buildRedirectUrl('pages', $pageUid, $targetLanguageId);

        return LocalizationResult::success(
            $redirectUrl !== null
                ? new RedirectLocalizationFinisher($redirectUrl)
                : new ReloadLocalizationFinisher()
        );
    }

    private function buildRedirectUrl(string $table, int $uid, int $targetLanguageId, ?string $returnUrl = null): ?string
    {
        if ($table === 'pages') {
            return (string)$this->uriBuilder->buildUriFromRoute('web_layout', [
                'id' => $uid,
                'languages' => [$targetLanguageId],
            ]);
        }

        $record = BackendUtility::getRecord($table, $uid);
        if (!$record) {
            return null;
        }

        // Use the caller's returnUrl (e.g. Records module URL) when available;
        // fall back to the page layout module so the Layout module path still works.
        $returnUrl ??= (string)$this->uriBuilder->buildUriFromRoute('web_layout', [
            'id' => $record['pid'],
            'languages' => [$targetLanguageId],
        ]);

        return (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
            'edit' => [$table => [$uid => 'edit']],
            'returnUrl' => $returnUrl,
        ]);
    }
}
