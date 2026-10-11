<?php

namespace Vspace\PageSpeed\Css;

/**
 * Осторожная минификация тела правила (деклараций): комментарии, лишние пробелы, последняя «;».
 * Строки не трогаются. Исходные файлы шаблона и так минифицированы — задача здесь лишь не тащить
 * в инлайн отступы и комментарии из неминифицированных файлов и <style>.
 */
final class Minifier
{
    private const STRING = '"[^"\\\\]*+(?:\\\\.[^"\\\\]*+)*+"|\'[^\'\\\\]*+(?:\\\\.[^\'\\\\]*+)*+\'';

    public static function body(string $css): string
    {
        if (str_contains($css, '/*')) {
            $css = preg_replace('#(' . self::STRING . ')|/\*.*?\*/#s', '$1', $css) ?? $css;
        }
        if (!preg_match('/\s/', $css)) {
            return rtrim($css, ';');
        }
        $parts = preg_split('#(' . self::STRING . ')#s', $css, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return trim($css);
        }
        // пробел перед «:» значим только во вложенных селекторах (CSS nesting: «& :hover»)
        $nested = str_contains($css, '{');
        foreach ($parts as $i => $part) {
            if ($i % 2 === 0) {
                $part = preg_replace('/\s+/', ' ', $part);
                $part = preg_replace('/\s*([{};,])\s*/', '$1', $part);
                $parts[$i] = preg_replace($nested ? '/:\s+/' : '/\s*:\s*/', ':', $part);
            }
        }
        return rtrim(trim(implode('', $parts)), ';');
    }
}
