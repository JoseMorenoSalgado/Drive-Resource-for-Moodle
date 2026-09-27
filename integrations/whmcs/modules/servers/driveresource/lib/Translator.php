<?php

namespace WHMCS\Module\Server\Driveresource;

/**
 * Small module-local translator for the WHMCS server/client-area UI.
 *
 * Keeping module strings local avoids hardcoded Spanish/English output and
 * does not depend on global WHMCS language-key collisions.
 */
final class Translator
{
    /** @var array<string,string> */
    private array $strings;

    /**
     * @param array<string,string> $strings Language dictionary.
     */
    private function __construct(array $strings)
    {
        $this->strings = $strings;
    }

    /**
     * Resolve the best module language from WHMCS client/admin context.
     *
     * @param array $params Standard module parameters.
     * @return self
     */
    public static function fromParams(array $params): self
    {
        $candidates = [
            (string) ($params['clientsdetails']['language'] ?? ''),
            (string) ($_SESSION['Language'] ?? ''),
            (string) ($_SESSION['adminlang'] ?? ''),
            'english',
        ];

        $language = 'english';
        foreach ($candidates as $candidate) {
            $candidate = strtolower(trim($candidate));
            if ($candidate === '') {
                continue;
            }
            if (
                in_array($candidate, ['spanish', 'es', 'espanol', 'español'], true)
                || str_starts_with($candidate, 'spanish')
            ) {
                $language = 'spanish';
            }
            break;
        }

        $path = dirname(__DIR__) . '/lang/' . $language . '.php';
        if (!is_file($path)) {
            $path = dirname(__DIR__) . '/lang/english.php';
        }

        $strings = require $path;
        return new self(is_array($strings) ? $strings : []);
    }

    /**
     * Translate one key with :placeholder replacements.
     *
     * @param string $key Translation key.
     * @param array<string,string|int|float> $replace Placeholder values.
     * @return string
     */
    public function t(string $key, array $replace = []): string
    {
        $text = $this->strings[$key] ?? $key;
        foreach ($replace as $name => $value) {
            $text = str_replace(':' . $name, (string) $value, $text);
        }

        return $text;
    }

    /**
     * Whether one stored key exists in this language dictionary.
     *
     * @param string $key Translation key.
     * @return bool
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->strings);
    }
}
