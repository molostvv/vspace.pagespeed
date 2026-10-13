<?php

namespace Vspace\PageSpeed;

use Bitrix\Main\Application;
use Bitrix\Main\Context as BitrixContext;
use Vspace\PageSpeed\Images\WebpStore;
use Vspace\PageSpeed\Images\WebpSupport;
use Vspace\PageSpeed\Integration\AmminaBridge;

/**
 * Обработчики событий main (регистрируются в install/index.php):
 *  - OnBeforeEndBufferContent — до обработчиков вывода: на страницах, которые обработает модуль, выключает
 *    у Ammina то же самое (AMMINA_DISABLE): CSS-оптимизацию — чтобы не было двух critical, lazyload — чтобы
 *    её заглушки не держали фон hero до window.onload, картинки (её WebP) — когда наш агент WebP работает
 *    по cron (иначе копий нет, и пусть пока работает её WebP), JS — его откладывает модуль;
 *  - OnEndBufferContent (сортировка 1100 — после Ammina с её 1000) — оптимизация готового HTML; в ответах
 *    AJAX с HTML-фрагментом (блоки, которые страница подгружает скриптом) — только WebP.
 * Любая ошибка модуля только пишется в лог: страница отдаётся всегда.
 */
final class EventHandler
{
    public static function onBeforeEndBufferContent(): void
    {
        try {
            $config = Options::config();
            if (!$config->amminaDisable) {
                return;
            }
            $page = RequestGuard::skipReason($config) === null;
            $fragment = !$page && $config->webpActive && RequestGuard::skipReason($config, null, true) === null;
            if (!$page && !$fragment) {
                return;
            }
            $categories = array_keys(array_filter([
                'css' => $page && $config->criticalFor(RequestGuard::path()),
                'lazy' => $page && $config->imagesActive,
                'js' => $page && $config->jsActive,
                // её WebP выключаем, только если наши копии появляются: GD умеет WebP и агент работает по cron
                'images' => $config->webpActive && WebpSupport::server() && (new WebpStore(self::documentRoot()))->cronWorks(),
            ]));
            AmminaBridge::disable($categories);
        } catch (\Throwable $e) {
            self::log($e);
        }
    }

    public static function onEndBufferContent(&$content): void
    {
        if (!is_string($content) || $content === '') {
            return;
        }
        try {
            $config = Options::config();
            $reason = RequestGuard::skipReason($config, $content);
            $fragment = $reason === 'ajax' && $config->webpActive && RequestGuard::skipReason($config, $content, true) === null;
            if ($reason !== null && !$fragment) {
                return;
            }
            $host = RequestGuard::host();
            $server = BitrixContext::getCurrent()->getServer();
            $ctx = new Context(
                $config,
                self::documentRoot(),
                RequestGuard::path(),
                array_values(array_unique([$host, preg_replace('/^www\./', '', $host), 'www.' . preg_replace('/^www\./', '', $host)])),
                RequestGuard::debugParam('vps_critical') === 'only',
                WebpSupport::browser((string)$server->get('HTTP_ACCEPT'), (string)$server->get('HTTP_USER_AGENT')),
                $fragment,
                RequestGuard::debugParam('vps_critical') === 'off',
            );
            if ($fragment && !$ctx->webp) {
                return;
            }
            $result = Optimizer::create($config, $ctx->documentRoot, $fragment)->optimize($content, $ctx);
            foreach ($ctx->exceptions as $e) {
                self::log($e);
            }
            if (RequestGuard::debugParam('vps_debug') !== null) {
                $result = self::withDebug($result, $ctx);
            }
            $content = $result;
        } catch (\Throwable $e) {
            self::log($e);
        }
    }

    private static function documentRoot(): string
    {
        return rtrim(str_replace('\\', '/', Application::getDocumentRoot()), '/');
    }

    /** Статистика — в заголовке X-Vspace-PageSpeed и (у страницы, не у AJAX-фрагмента) в комментарии перед </body>. */
    private static function withDebug(string $html, Context $ctx): string
    {
        $info = $ctx->stats + ($ctx->errors ? ['errors' => implode('; ', $ctx->errors)] : []);
        if (!headers_sent()) {
            $pairs = [];
            foreach ($info as $key => $value) {
                $pairs[] = $key . '=' . preg_replace('/[\r\n;]+/', ' ', (string)$value);
            }
            header('X-Vspace-PageSpeed: ' . implode('; ', $pairs));
        }
        if ($ctx->fragment) {
            return $html;
        }
        $comment = '<!-- vspace.pagespeed ' . str_replace('--', '- -', json_encode($info, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . ' -->';
        $pos = strripos($html, '</body>');
        return $pos === false ? $html . $comment : substr_replace($html, $comment, $pos, 0);
    }

    private static function log(\Throwable $e): void
    {
        try {
            Application::getInstance()->getExceptionHandler()->writeToLog($e);
        } catch (\Throwable) {
        }
    }
}
