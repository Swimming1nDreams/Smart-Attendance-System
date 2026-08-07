<?php

declare(strict_types=1);

namespace App\Core;

final class Localization
{
    private const SESSION_KEY = 'locale';

    private static ?self $current = null;

    private string $languagePath;
    private string $defaultLocale;
    /** @var list<string> */
    private array $supportedLocales;
    private string $locale;
    /** @var array<string, array<string, mixed>> */
    private array $translations = [];

    /**
     * @param list<string> $supportedLocales
     */
    public function __construct(string $languagePath, string $defaultLocale = 'en', array $supportedLocales = ['en', 'my'])
    {
        $this->languagePath = rtrim($languagePath, '/\\');
        $this->defaultLocale = $defaultLocale;
        $this->supportedLocales = $supportedLocales;
        $this->locale = $this->resolveStoredLocale();
    }

    public static function setCurrent(self $localization): void
    {
        self::$current = $localization;
    }

    public static function current(): self
    {
        if (self::$current === null) {
            self::$current = new self(__DIR__ . '/../../lang');
        }

        return self::$current;
    }

    /**
     * @param array<string, mixed> $queryParams
     */
    public function handleQueryLanguage(array $queryParams): void
    {
        $requestedLocale = isset($queryParams['lang']) ? trim((string) $queryParams['lang']) : '';

        if ($requestedLocale === '' || !$this->isSupportedLocale($requestedLocale)) {
            return;
        }

        $this->locale = $requestedLocale;
        $_SESSION[self::SESSION_KEY] = $requestedLocale;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function htmlLang(): string
    {
        return $this->locale === 'my' ? 'my' : 'en';
    }

    public function translate(string $key): string
    {
        $value = $this->lookup($this->locale, $key);

        if (is_string($value)) {
            return $value;
        }

        $fallback = $this->lookup($this->defaultLocale, $key);

        return is_string($fallback) ? $fallback : $key;
    }

    /**
     * @return array<string, string>
     */
    public function languageOptions(): array
    {
        return [
            'en' => $this->translate('language.english'),
            'my' => $this->translate('language.myanmar'),
        ];
    }

    public function switchUrl(string $locale): string
    {
        if (!$this->isSupportedLocale($locale)) {
            $locale = $this->defaultLocale;
        }

        $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($requestUri, PHP_URL_PATH) ?: '/';
        $query = [];
        $rawQuery = parse_url($requestUri, PHP_URL_QUERY);

        if (is_string($rawQuery) && $rawQuery !== '') {
            parse_str($rawQuery, $query);
        }

        $query['lang'] = $locale;
        $queryString = http_build_query($query);

        return $path . ($queryString === '' ? '' : '?' . $queryString);
    }

    private function resolveStoredLocale(): string
    {
        $storedLocale = isset($_SESSION[self::SESSION_KEY]) ? (string) $_SESSION[self::SESSION_KEY] : '';

        return $this->isSupportedLocale($storedLocale) ? $storedLocale : $this->defaultLocale;
    }

    private function isSupportedLocale(string $locale): bool
    {
        return in_array($locale, $this->supportedLocales, true);
    }

    private function lookup(string $locale, string $key): mixed
    {
        $translations = $this->loadTranslations($locale);
        $segments = explode('.', $key);
        $value = $translations;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadTranslations(string $locale): array
    {
        if (array_key_exists($locale, $this->translations)) {
            return $this->translations[$locale];
        }

        $file = $this->languagePath . DIRECTORY_SEPARATOR . $locale . '.php';

        if (!is_file($file)) {
            $this->translations[$locale] = [];
            return [];
        }

        $translations = require $file;

        $this->translations[$locale] = is_array($translations) ? $translations : [];

        return $this->translations[$locale];
    }
}
