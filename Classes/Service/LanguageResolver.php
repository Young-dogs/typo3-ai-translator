<?php

declare(strict_types=1);

namespace YoungDogs\Translate\Service;

use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Resolves TYPO3 site language ISO codes from language UIDs.
 */
final class LanguageResolver
{
    public function __construct(
        private readonly SiteFinder $siteFinder,
    ) {}

    public function getSiteByPageId(int $pageId): ?Site
    {
        try {
            return $this->siteFinder->getSiteByPageId($pageId);
        } catch (SiteNotFoundException) {
            return null;
        }
    }

    /**
     * Returns lowercase two-letter ISO code (e.g. "en", "nl", "de") for a language UID within a site.
     * Falls back to null when the language is not configured in the site.
     */
    public function getIsoCodeForLanguageUid(Site $site, int $languageUid): ?string
    {
        try {
            return $site->getLanguageById($languageUid)->getLocale()->getLanguageCode();
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
