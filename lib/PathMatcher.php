<?php

namespace Vspace\PageSpeed;

/**
 * Сопоставление адресов со списками исключений из настроек.
 *
 * Пути (EXCLUDE_URLS, EXCLUDE_FILES), по строке на правило:
 *   /personal/            — префикс: всё, что начинается с /personal/
 *   /services/*-redesign/ — маска: * = любые символы, сравнивается весь путь
 *   ~^/test/\d+/$         — регулярное выражение (после ~, без ограничителей)
 *
 * Домены (EXCLUDE_HOSTS):
 *   design-demis.ru       — этот домен и его www.
 *   crm.                  — домены, начинающиеся с crm. (то же, что crm.*)
 *   *.demis.ru            — маска
 */
final class PathMatcher
{
    /** @param list<string> $rules */
    public static function matchPath(string $path, array $rules): bool
    {
        foreach ($rules as $rule) {
            if ($rule[0] === '~') {
                if (@preg_match('#' . str_replace('#', '\#', substr($rule, 1)) . '#u', $path) === 1) {
                    return true;
                }
            } elseif (str_contains($rule, '*')) {
                if (self::glob($rule, $path)) {
                    return true;
                }
            } elseif (str_starts_with($path, $rule)) {
                return true;
            }
        }
        return false;
    }

    /** @param list<string> $rules */
    public static function matchHost(string $host, array $rules): bool
    {
        $host = strtolower(preg_replace('/:\d+$/', '', $host));
        foreach ($rules as $rule) {
            $rule = strtolower($rule);
            if (str_ends_with($rule, '.') && !str_contains($rule, '*')) {
                $rule .= '*';
            }
            if (str_contains($rule, '*')) {
                if (self::glob($rule, $host)) {
                    return true;
                }
            } elseif ($host === $rule || $host === 'www.' . $rule) {
                return true;
            }
        }
        return false;
    }

    private static function glob(string $mask, string $value): bool
    {
        $pattern = '#^' . str_replace('\*', '.*', preg_quote($mask, '#')) . '$#u';
        return preg_match($pattern, $value) === 1;
    }
}
