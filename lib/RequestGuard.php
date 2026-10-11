<?php

namespace Vspace\PageSpeed;

use Bitrix\Main\Context as BitrixContext;

/**
 * Решает, оптимизировать ли текущий ответ. Обрабатываются только обычные GET-страницы сайта в HTML:
 * не админка, не AJAX, не режим правки, не /bitrix/, не страницы и домены из исключений. Отдельно —
 * HTML-фрагменты из ответов AJAX (блоки, которые страница подгружает скриптом): в них подменяется только WebP.
 *
 * Отладочные параметры (для администратора или при DEBUG_PARAMS=Y):
 *   ?vps=off          — страница без оптимизации (сравнить «до/после»);
 *   ?vps_critical=only — только critical CSS, полные CSS не подключаются: видно, всё ли нужное первому
 *                       экрану попало в critical;
 *   ?vps_debug=1      — статистика в HTML-комментарии в конце страницы и в заголовке X-Vspace-PageSpeed.
 */
final class RequestGuard
{
    private const MAX_HTML_SIZE = 6 * 1024 * 1024;

    /**
     * Причина пропуска или null, если страницу можно обрабатывать.
     * @param string|null $content тело ответа; null — проверка до формирования ответа (без проверок содержимого)
     * @param bool $fragment проверка для AJAX-ответа с HTML-фрагментом (а не для страницы)
     */
    public static function skipReason(Config $config, ?string $content = null, bool $fragment = false): ?string
    {
        if (!$config->active) {
            return 'inactive';
        }
        if (PHP_SAPI === 'cli' || (defined('BX_CRONTAB') && BX_CRONTAB === true) || (defined('CHK_EVENT') && CHK_EVENT === true)) {
            return 'cli';
        }
        if (defined('ADMIN_SECTION') && ADMIN_SECTION === true) {
            return 'admin';
        }
        $request = BitrixContext::getCurrent()->getRequest();
        if (!in_array($request->getRequestMethod(), ['GET', 'HEAD'], true)) {
            return 'method';
        }
        $ajax = $request->isAjaxRequest() || $request->get('bxajaxid') !== null || (defined('PUBLIC_AJAX_MODE') && PUBLIC_AJAX_MODE === true);
        if ($ajax !== $fragment) {
            return $fragment ? 'not-ajax' : 'ajax';
        }
        $path = self::path();
        if (str_starts_with($path, '/bitrix/')) {
            return 'bitrix';
        }
        global $APPLICATION;
        if (is_object($APPLICATION) && $APPLICATION->GetShowIncludeAreas()) {
            return 'edit-mode';
        }
        if (PathMatcher::matchHost(self::host(), $config->excludeHosts)) {
            return 'host';
        }
        if (PathMatcher::matchPath($path, $config->excludeUrls)) {
            return 'url';
        }
        if (self::debugParam('vps') === 'off') {
            return 'off';
        }
        if ($content !== null) {
            if (strlen($content) > self::MAX_HTML_SIZE) {
                return 'size';
            }
            $head = substr($content, 0, 2048);
            // страница — документ HTML; фрагмент — разметка (не JSON, не XML)
            if ($fragment ? !preg_match('/^\s*<(?!\?xml)/i', $head) : !preg_match('/^\s*(?:<!--.*?-->\s*)*<(?:!doctype\s+html|html)[\s>]/is', $head)) {
                return 'not-html';
            }
            foreach (headers_list() as $header) {
                if (stripos($header, 'content-type:') === 0 && stripos($header, 'text/html') === false) {
                    return 'content-type';
                }
            }
        }
        return null;
    }

    /** Значение отладочного параметра, если отладка разрешена этому посетителю. */
    public static function debugParam(string $name): ?string
    {
        $value = BitrixContext::getCurrent()->getRequest()->getQuery($name);
        if ($value === null || !self::debugAllowed()) {
            return null;
        }
        return (string)$value;
    }

    public static function path(): string
    {
        $uri = (string)BitrixContext::getCurrent()->getRequest()->getRequestUri();
        return (string)(parse_url($uri, PHP_URL_PATH) ?: '/');
    }

    public static function host(): string
    {
        return strtolower(preg_replace('/:\d+$/', '', (string)BitrixContext::getCurrent()->getServer()->getHttpHost()));
    }

    private static function debugAllowed(): bool
    {
        if (Options::config()->debugParams) {
            return true;
        }
        global $USER;
        return is_object($USER) && $USER->IsAdmin();
    }
}
