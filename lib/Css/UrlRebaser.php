<?php

namespace Vspace\PageSpeed\Css;

/**
 * Переводит относительные url(...) из CSS-файла в пути от корня сайта. Нужно, потому что правила из файла
 * /local/templates/x/css/main.css попадают инлайном в страницу /services/seo/, и url(../img/a.png)
 * без переписывания указывал бы уже от адреса страницы.
 */
final class UrlRebaser
{
    // possessive-квантификаторы: на длинных data:-URI обычная альтернатива упирается в лимиты PCRE
    private const URL_PATTERN = '#url\(\s*+(?:"([^"\\\\]*+(?:\\\\.[^"\\\\]*+)*+)"|\'([^\'\\\\]*+(?:\\\\.[^\'\\\\]*+)*+)\'|([^)"\'\s]*+))\s*+\)#i';

    /** @param string $baseDir каталог исходного файла от корня сайта, со слешем в конце: /local/templates/x/css/ */
    public static function rebase(string $css, string $baseDir): string
    {
        if (stripos($css, 'url(') === false) {
            return $css;
        }
        $result = preg_replace_callback(self::URL_PATTERN, static function (array $m) use ($baseDir): string {
            if ($m[1] !== null) {
                return 'url("' . self::resolve($m[1], $baseDir) . '")';
            }
            if ($m[2] !== null) {
                return "url('" . self::resolve($m[2], $baseDir) . "')";
            }
            return 'url(' . self::resolve($m[3] ?? '', $baseDir) . ')';
        }, $css, -1, $count, PREG_UNMATCHED_AS_NULL);
        return $result ?? $css;
    }

    /** Адрес относительно $baseDir -> путь от корня; абсолютные, data:, #id и пустые не меняются. */
    public static function resolve(string $url, string $baseDir): string
    {
        if ($url === '' || preg_match('#^(?:[a-z][a-z0-9+.-]*:|/|\#|%23)#i', $url)) {
            return $url;
        }
        $suffix = '';
        if (($cut = strcspn($url, '?#')) < strlen($url)) {
            $suffix = substr($url, $cut);
            $url = substr($url, 0, $cut);
        }
        return self::normalize($baseDir . $url) . $suffix;
    }

    /** Схлопывает «.» и «..» в пути. */
    public static function normalize(string $path): string
    {
        $segments = [];
        foreach (explode('/', $path) as $i => $segment) {
            if ($segment === '..') {
                if (count($segments) > 1) {
                    array_pop($segments);
                }
            } elseif ($segment !== '.' && ($segment !== '' || $i === 0)) {
                $segments[] = $segment;
            }
        }
        $result = implode('/', $segments);
        if (str_ends_with($path, '/') || str_ends_with($path, '/.') || str_ends_with($path, '/..')) {
            $result .= '/';
        }
        return $result === '' ? '/' : $result;
    }
}
