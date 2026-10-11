<?php

namespace Vspace\PageSpeed;

use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;
use Vspace\PageSpeed\Images\WebpConverter;
use Vspace\PageSpeed\Images\WebpStore;
use Vspace\PageSpeed\Images\WebpSupport;

/**
 * Агенты модуля (непериодические: при BX_CRONTAB_SUPPORT Битрикс выполняет такие только из cron):
 *  - cleanup() раз в сутки удаляет из кеша файлы, не использовавшиеся дольше CACHE_TTL_DAYS (старые индексы
 *    CSS, critical исчезнувших структур страниц), и WebP-копии удалённых картинок;
 *  - webp() раз в минуту конвертирует очередь WebP — только из cron (на хите посетителя не работает: 20 с
 *    конвертации не должны достаться случайному посетителю) и отмечает время запуска — по нему настройки
 *    показывают, работает ли cron.
 */
final class Agent
{
    /** [функция, интервал, первый запуск через N секунд] */
    private const AGENTS = [
        ['\\Vspace\\PageSpeed\\Agent::cleanup();', 86400, 3600],
        ['\\Vspace\\PageSpeed\\Agent::webp();', 60, 60],
    ];
    /** Время конвертации за запуск: меньше минутного интервала cron, чтобы не задерживать другие агенты. */
    private const WEBP_SECONDS = 20;

    /** Регистрирует недостающие агенты (установка модуля; сохранение настроек — для обновлённого модуля). */
    public static function register(): void
    {
        foreach (self::AGENTS as [$name, $interval, $delay]) {
            \CAgent::AddAgent($name, Options::MODULE_ID, 'N', $interval, '', 'Y',
                DateTime::createFromTimestamp(time() + $delay)->toString(), 100, false, false);
        }
    }

    public static function cleanup(): string
    {
        try {
            $root = self::root();
            Optimizer::storage($root)->cleanup(Options::config()->cacheTtlDays * 86400);
            (new WebpStore($root))->cleanup();
        } catch (\Throwable $e) {
            self::log($e);
        }
        return '\\' . __METHOD__ . '();';
    }

    public static function webp(): string
    {
        if ((defined('BX_CRONTAB') && BX_CRONTAB === true) || PHP_SAPI === 'cli') {
            try {
                $store = new WebpStore(self::root());
                $store->markCron();
                $config = Options::config();
                if ($config->active && $config->webpActive && WebpSupport::server()) {
                    $store->processQueue(new WebpConverter(), self::WEBP_SECONDS, static function (\Throwable $e, string $path): void {
                        self::log(new \RuntimeException('WebP ' . $path . ': ' . $e->getMessage(), 0, $e));
                    });
                }
            } catch (\Throwable $e) {
                self::log($e);
            }
        }
        return '\\' . __METHOD__ . '();';
    }

    private static function root(): string
    {
        return rtrim(str_replace('\\', '/', Application::getDocumentRoot()), '/');
    }

    private static function log(\Throwable $e): void
    {
        try {
            Application::getInstance()->getExceptionHandler()->writeToLog($e);
        } catch (\Throwable) {
        }
    }
}
