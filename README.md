# Young Dogs AI Translator for TYPO3

TYPO3 backend extension that integrates the **Young Dogs AI translation service** into the standard TYPO3 localization workflow. When translating pages or content elements in the backend, editors can choose "Translate with Young Dogs AI" to have all text fields automatically translated in one go.

---

## Requirements

| Dependency | Version |
|---|---|
| PHP | 8.2 – 8.5 |
| TYPO3 | 14.3 |

---

## Installation

```bash
composer require young-dogs/typo3-ai-translator
```

Activate the extension via the TYPO3 Extension Manager or CLI:

```bash
./vendor/bin/typo3 extension:setup --extension=youngdogs_translate
```

---

## Configuration

Go to **Admin Tools → Settings → Extension Configuration → `youngdogs_translate`**.

| Setting | Description |
|---|---|
| **Translation Service URL** | Full endpoint URL of the AI translation API |
| **API Key** | Secret key sent as `X-API-Key` header |
| **Auto-enable after translation** | Sets `hidden = 0` on all translated records (pages, content elements, inline children) immediately after translation |
| **Disable "Translate to" prefix** | Removes the `[Translate to Dutch:]` prefix that TYPO3 normally prepends to header fields |
| **Exclude fields** | Comma-separated fields to never translate. Use `fieldname` (global) or `table.fieldname`. Example: `tx_bootstrappackage_header_class, tt_content.layout` |
| **Always translate these fields** | Comma-separated fields to force-translate, bypassing automatic detection. Use `fieldname` or `table.fieldname`. Example: `pages.nav_title, tx_news_domain_model_news.keywords` |

---

## API Contract

The extension communicates with your translation service over HTTP POST.

### Single text
```json
// Request
{ "source_language": "EN", "destination_language": "NL", "texts": ["Hello world"] }

// Response
{ "translations": ["Hallo wereld"] }
```

### Batch (multiple fields, one request per record)
```json
// Request
{
  "source_language": "EN",
  "destination_language": "NL",
  "texts": {
    "header": "Welcome to Young Dogs",
    "bodytext": "We help businesses grow..."
  }
}

// Response
{
  "translations": {
    "header": "Welkom bij Young Dogs",
    "bodytext": "We helpen bedrijven groeien..."
  }
}
```

Headers sent with every request:
```
Content-Type: application/json
Accept: application/json
X-API-Key: <your api key>
```

---

## How it works

The extension registers a **TYPO3 14 localization handler** (`LocalizationHandlerInterface`) which appears as a third option — alongside the standard *Copy* and *Translate* modes — in the localization wizard in both the **Page Layout** and **Records** modules.

When selected:

1. All `l10n_mode = prefixLangTitle` fields (e.g. `tt_content.header`, `tt_content.bodytext`) are batch-fetched and translated in **one API call per record** before `DataHandler::localize()` runs.
2. All other translatable `input`/`text` fields (e.g. inline children like bootstrap_package cards, news fields) are batch-translated per record in `processDatamap_preProcessFieldArray`.
3. Fields listed in **Always translate** that are not normally part of the localization data map (e.g. `pages.nav_title`) are translated and written to the database after the record is created.
4. The `[Translate to Dutch:]` prefix is stripped from all fields when that setting is enabled.
5. Records are auto-enabled (`hidden = 0`) when that setting is enabled — applies to top-level records **and** inline children.

---

## Supported record types

- `pages` — page translations including fields in *Always translate*
- `tt_content` — all content element types
- Inline IRRE children — bootstrap_package cards, carousels, tabs, accordions, etc.
- `tx_news_domain_model_news` and other third-party records (via Records module)
- Any language-aware TYPO3 table

---

## License

MIT — see [LICENSE](LICENSE).

---

### 🌐 Connect with us

* **Website:** [young-dogs.com](https://www.young-dogs.com)
* **Network:** [LinkedIn](https://www.linkedin.com/company/young-dogs-ecommerce/)
* **HQ:** Young-dogs España SL, Asturias, Spain 🇪🇸

---
<sub>© 2026 Young-dogs Spain. Built with passion for innovation and operational excellence.</sub>
