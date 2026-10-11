<?php

namespace Vspace\PageSpeed;

use Bitrix\Main\Application;
use Vspace\PageSpeed\Integration\AmminaBridge;

/**
 * Обработчики событий main (регистрируются в install/index.php):
 *  - OnBeforeEndBufferContent — до обработчиков вывода: на страницах, которые обработает модуль, выключает
 *    у Ammina то же самое (AMMINA_DISABLE): CSS-оптимизацию — чтобы не было двух critical, lazyload — чтобы
 *    её заглушки не держали фон hero до window.onload;
 *  - OnEndBufferContent (сортировка 1100 — после Ammina с её 1000) — оптимизация готового HTML.
 * Любая ошибка модуля только пишется в лог: страница отдаётся всегда.
 */
final class EventHandler
{
    public static function onBeforeEndBufferContent(): void
    {
        try {
            $config = Options::config();
            $categories = array_keys(array_filter(['css' => $config->criticalFor(RequestGuard::path()), 'lazy' => $config->imagesActive]));
            if ($config->amminaDisable && $categories && RequestGuard::skipReason($config) === null) {
                AmminaBridge::disable($categories);
            }
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
            if (RequestGuard::skipReason($config, $content) !== null) {
                return;
            }
            $host = RequestGuard::host();
            $ctx = new Context(
                $config,
                rtrim(str_replace('\\', '/', Application::getDocumentRoot()), '/'),
                RequestGuard::path(),
                array_values(array_unique([$host, preg_replace('/^www\./', '', $host), 'www.' . preg_replace('/^www\./', '', $host)])),
                RequestGuard::debugParam('vps_critical') === 'only',
            );
            $result = Optimizer::create($config, $ctx->documentRoot)->optimize($content, $ctx);
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
