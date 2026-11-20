# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

### Added
- API contract documentation moved to `Documentation/ApiContract.md`
- This changelog

### Changed
- README now links to `Documentation/ApiContract.md` instead of inlining the API spec

### Fixed
- Trailing dot removed from `version` field in `composer.json`

---

## [1.0.0] - 2026-11-19

### Added
- Initial release of the Young Dogs AI Translator extension for TYPO3 14
- `LocalizationHandlerInterface` integration — appears as a third option in the TYPO3 localization wizard
- Batch translation: all text fields for a record are sent in one API call
- Support for `pages`, `tt_content`, inline IRRE children, `tx_news_domain_model_news`, and any language-aware table
- Extension configuration: Translation Service URL, API Key, auto-enable after translation, disable prefix, exclude fields, always-translate fields
- MIT licence

[Unreleased]: https://github.com/Young-dogs/typo3-ai-translator/compare/1.0.0...HEAD
[1.0.0]: https://github.com/Young-dogs/typo3-ai-translator/releases/tag/1.0.0
