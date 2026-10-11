<?php

namespace Vspace\PageSpeed\Images;

/**
 * Умеет ли браузер WebP и может ли сервер его делать.
 *
 * Браузер — по заголовку Accept (Chrome и его потомки шлют image/webp и для страниц) и, где его нет, по
 * User-Agent: Safari не пишет image/webp в Accept страницы, Firefox убрал его оттуда в новых версиях.
 * Safari на macOS умеет WebP только с macOS 11, а версию системы его UA не показывает (всегда 10_15_7) —
 * поэтому порог Safari 16: эта версия на macOS 10.15 уже не выходила. На iOS все браузеры — WebKit системы,
 * WebP там с iOS 14.
 */
final class WebpSupport
{
    public static function browser(string $accept, string $userAgent): bool
    {
        if (stripos($accept, 'image/webp') !== false) {
            return true;
        }
        if ($userAgent === '') {
            return false;
        }
        if (preg_match('/\b(?:iPhone|iPad|iPod)\b.*?\bOS (\d+)_/', $userAgent, $m)) {
            return (int)$m[1] >= 14;
        }
        if (preg_match('#\bEdge/(\d+)#', $userAgent, $m)) { // старый Edge (EdgeHTML) тоже пишет Chrome/…
            return (int)$m[1] >= 18;
        }
        if (preg_match('#\b(?:Chrome|Chromium|CriOS)/(\d+)#', $userAgent, $m)) {
            return (int)$m[1] >= 32;
        }
        if (preg_match('#\bFirefox/(\d+)#', $userAgent, $m)) {
            return (int)$m[1] >= 65;
        }
        if (str_contains($userAgent, 'Macintosh') && preg_match('#\bVersion/(\d+)[.\d]* .*Safari/#', $userAgent, $m)) {
            return (int)$m[1] >= 16;
        }
        return false;
    }

    /** GD собран с WebP (на BitrixVM — да; Imagick и cwebp не нужны). */
    public static function server(): bool
    {
        return function_exists('imagewebp') && function_exists('imagecreatefromjpeg') && function_exists('imagecreatefrompng');
    }
}
