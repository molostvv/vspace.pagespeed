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
 * Где cron Битрикса не настроен, их выполняет cron.php модуля (runDue()).
 */
final class Agent
{
    /** [метод, интервал, первый запуск через N секунд] */
    private const AGENTS = [
        ['cleanup', 86400, 3600],
        ['webp', 60, 60],
    ];
    /** Время конвертации за запуск: меньше минутного интервала cron, чтобы не задерживать другие агенты. */
    private const WEBP_SECONDS = 20;
    /** На сколько runDue() захватывает агент (как CAgent::LOCK_TIME): упавший запуск не держит его вечно. */
    private const LOCK_SECONDS = 600;

    /** Регистрирует недостающие агенты (установка модуля; сохранение настроек — для обновлённого модуля). */
    public static function register(): void
    {
        foreach (self::AGENTS as [$method, $interval, $delay]) {
            \CAgent::AddAgent(self::agentName($method), Options::MODULE_ID, 'N', $interval, '', 'Y',
                DateTime::createFromTimestamp(time() + $delay)->toString(), 100, false, false);
        }
    }

    /**
     * Выполняет агенты модуля, которым подошло время, — для cron.php. Как CAgent::ExecuteAgents: агент
     * захватывается на время работы (DATE_CHECK), после — сдвигаются LAST_EXEC/NEXT_EXEC. Поэтому cron.php
     * можно оставить и после настройки cron Битрикса: агент не выполнится дважды за интервал.
     * @param bool $force не ждать NEXT_EXEC
     * @return list<string> выполненные методы
     */
    public static function runDue(bool $force = false): array
    {
        $db = Application::getConnection();
        $sql = $db->getSqlHelper();
        $now = $sql->getCurrentDateTimeFunction();
        $free = "(DATE_CHECK IS NULL OR DATE_CHECK <= $now)";
        $methods = [];
        foreach (self::AGENTS as [$method]) {
            $methods[self::agentName($method)] = $method;
        }
        $rows = $db->query("SELECT ID, NAME, AGENT_INTERVAL FROM b_agent WHERE MODULE_ID = '" . $sql->forSql(Options::MODULE_ID)
            . "' AND ACTIVE = 'Y' AND $free" . ($force ? '' : " AND NEXT_EXEC <= $now"))->fetchAll();
        $done = [];
        foreach ($rows as $row) {
            $method = $methods[$row['NAME']] ?? null;
            $id = (int)$row['ID'];
            if ($method === null) {
                continue;
            }
            $db->queryExecute('UPDATE b_agent SET DATE_CHECK = ' . $sql->addSecondsToDateTime(self::LOCK_SECONDS) . " WHERE ID = $id AND $free");
            if ($db->getAffectedRowsCount() !== 1) {
                continue; // успел захватить другой процесс
            }
            self::$method();
            $db->queryExecute("UPDATE b_agent SET LAST_EXEC = $now, NEXT_EXEC = " . $sql->addSecondsToDateTime((int)$row['AGENT_INTERVAL'])
                . ", DATE_CHECK = NULL, RUNNING = 'N', RETRY_COUNT = 0 WHERE ID = $id");
            $done[] = $method;
        }
        return $done;
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

    /** Строка агента в b_agent: \Vspace\PageSpeed\Agent::webp(); */
    private static function agentName(string $method): string
    {
        return '\\' . self::class . '::' . $method . '();';
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
