<?php

declare(strict_types=1);

namespace YoungDogs\Translate\Service;

use TYPO3\CMS\Core\Http\RequestFactory;
use YoungDogs\Translate\Service\ExtensionSettings;

/**
 * Sends translation requests to the Young Dogs AI translation service.
 *
 * Single-text call (unchanged):
 *   Body:     {"source_language":"EN","destination_language":"NL","texts":["Hello"]}
 *   Response: {"translations":["Hallo"]}
 *
 * Batch call with named keys (new – requires translation service to support object texts):
 *   Body:     {"source_language":"EN","destination_language":"NL","texts":{"header":"Hello","bodytext":"World"}}
 *   Response: {"translations":{"header":"Hallo","bodytext":"Wereld"}}
 *   Each key is returned in translations so order does not matter.
 */
final class TranslationApiClient
{
    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly ExtensionSettings $settings,
    ) {}

    public function isConfigured(): bool
    {
        return $this->settings->isConfigured();
    }

    /**
     * Translates a single text string.
     *
     * @return string Translated text, or the original on failure.
     */
    public function translate(string $text, string $sourceLanguage, string $targetLanguage): string
    {
        if (!$this->isConfigured() || $text === '') {
            return $text;
        }
        /** @var array{translations?: string[]} $data */
        $data = $this->post([$text], $sourceLanguage, $targetLanguage);
        $translations = $data['translations'] ?? [];
        return (string)($translations[0] ?? $text);
    }

    /**
     * Translates a map of named texts in a single API call.
     * Keys (field names) are sent as a JSON object so the response maps them back
     * unambiguously regardless of processing order.
     *
     * @param array<string, string> $texts  fieldName => sourceText
     * @return array<string, string>        fieldName => translatedText (original on failure)
     */
    public function translateBatch(array $texts, string $sourceLanguage, string $targetLanguage): array
    {
        if (!$this->isConfigured() || $texts === []) {
            return $texts;
        }

        // Skip empty values but remember them so we return a complete map
        $toSend = array_filter($texts, static fn(string $v): bool => $v !== '');
        if ($toSend === []) {
            return $texts;
        }

        // Single-text: use array format ["text"] so the service handles it the same as translate()
        if (count($toSend) === 1) {
            $key = (string)array_key_first($toSend);
            $data = $this->post(array_values($toSend), $sourceLanguage, $targetLanguage);
            $translated = $data['translations'][0] ?? '';
            $result = $texts;
            if (is_string($translated) && $translated !== '') {
                $result[$key] = $translated;
            }
            return $result;
        }

        /** @var array{translations?: array<string, string>} $data */
        $data = $this->post($toSend, $sourceLanguage, $targetLanguage);
        $translations = $data['translations'] ?? [];

        $result = $texts;
        foreach ($toSend as $key => $original) {
            $translated = $translations[$key] ?? '';
            // Skip if the service returned an array/object instead of a string
            if (!is_string($translated) || $translated === '') {
                continue;
            }
            $result[$key] = $translated;
        }
        return $result;
    }

    /**
     * @param array<array-key, string> $texts  array or string-keyed object payload
     * @return array<string, mixed>
     */
    private function post(array $texts, string $sourceLanguage, string $targetLanguage): array
    {
        $payload = json_encode([
            'source_language' => strtoupper($sourceLanguage),
            'destination_language' => strtoupper($targetLanguage),
            'texts' => $texts,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $response = $this->requestFactory->request(
            $this->settings->getApiUrl(),
            'POST',
            [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'X-API-Key' => $this->settings->getApiKey(),
                ],
                'body' => $payload,
                'timeout' => 30,
            ]
        );

        if ($response->getStatusCode() !== 200) {
            return [];
        }

        return (array)json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
