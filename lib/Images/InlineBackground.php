<?php

namespace Vspace\PageSpeed\Images;

/**
 * Фоновая картинка в атрибуте style (background / background-image с url(...)): адрес для preload и перенос
 * картинки в data-атрибут для отложенной загрузки.
 *
 * Откладывается только простой случай — одна картинка без градиентов, image-set, !important и data:. Остальное
 * остаётся как есть: лучше загрузить фон сразу, чем показать его не таким.
 */
final class InlineBackground
{
    private const URL_PATTERN = '#url\(\s*(?:"[^"]*"|\'[^\']*\'|[^)]*)\s*\)#i';

    /** Адрес первой фоновой картинки (без кавычек) или null. */
    public static function firstUrl(string $style): ?string
    {
        foreach (self::declarations($style) as [$property, $value]) {
            if (($property === 'background' || $property === 'background-image') && preg_match(self::URL_PATTERN, $value, $m)) {
                $url = trim(substr(trim($m[0]), 4, -1), " \t\n\r\"'");
                return $url === '' || str_starts_with(strtolower($url), 'data:') ? null : $url;
            }
        }
        return null;
    }

    /**
     * Стиль без фоновой картинки и значение для background-image, которое скрипт поставит позже,
     * или null — если откладывать нечего или рискованно.
     * @return array{0:string, 1:string}|null
     */
    public static function defer(string $style): ?array
    {
        $parts = [];
        $image = null;
        foreach (self::declarations($style) as [$property, $value, $raw]) {
            if (($property !== 'background' && $property !== 'background-image') || stripos($value, 'url(') === false) {
                $parts[] = $raw;
                continue;
            }
            if ($image !== null || preg_match_all(self::URL_PATTERN, $value, $urls) !== 1
                || preg_match('#!important|gradient\(|image-set\(|data:#i', $value) || self::hasTopLevelComma($value)) {
                return null;
            }
            $image = trim($urls[0][0]);
            // background-image:none, а не удаление: иначе подставится фон из CSS-класса и загрузится он
            $parts[] = $property === 'background-image'
                ? 'background-image:none'
                : 'background:' . trim(str_replace($urls[0][0], 'none', $value));
        }
        return $image === null ? null : [implode(';', $parts), $image];
    }

    /**
     * Объявления style по порядку: [свойство в нижнем регистре, значение, исходный текст].
     * «;» внутри скобок и кавычек объявление не делит.
     * @return list<array{0:string, 1:string, 2:string}>
     */
    private static function declarations(string $style): array
    {
        $result = [];
        $depth = 0;
        $quote = null;
        $current = '';
        $length = strlen($style);
        for ($i = 0; $i <= $length; $i++) {
            $char = $style[$i] ?? ';';
            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($char === ';' && $depth === 0) {
                $raw = trim($current);
                $current = '';
                if ($raw === '') {
                    continue;
                }
                $colon = strpos($raw, ':');
                $result[] = $colon === false
                    ? ['', '', $raw]
                    : [strtolower(trim(substr($raw, 0, $colon))), trim(substr($raw, $colon + 1)), $raw];
                continue;
            }
            $current .= $char;
        }
        return $result;
    }

    private static function hasTopLevelComma(string $value): bool
    {
        $depth = 0;
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                return true;
            }
        }
        return false;
    }
}
