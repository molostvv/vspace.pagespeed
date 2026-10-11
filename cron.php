<?php
/**
 * Отложенные задачи модуля — конвертация WebP (раз в минуту) и очистка кеша (раз в сутки) — для сайтов,
 * где не настроен cron Битрикса. Тот (bitrix/modules/main/tools/cron_events.php) выполняет все агенты сайта
 * и рассылает почтовые события; этот скрипт — только агенты vspace.pagespeed, которым подошло время.
 *
 * Запускать раз в минуту от пользователя сайта (bitrix), не от root — иначе web-процесс не сможет
 * дописывать очередь и копии:
 *
 *   * * * * * php -f /home/bitrix/www/local/modules/vspace.pagespeed/cron.php
 *
 *   php cron.php -v        — что выполнено и состояние WebP
 *   php cron.php --force   — выполнить агенты сейчас, не дожидаясь их времени
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    fwrite(STDERR, "Не запускайте от root: файлы очереди и копий станут недоступны сайту. Нужен пользователь сайта (bitrix).\n");
    exit(1);
}

$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__, 3);
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('NO_AGENT_CHECK', true); // другие агенты сайта этот скрипт не запускает
define('NO_AGENT_STATISTIC', true);
define('BX_NO_ACCELERATOR_RESET', true);
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
while (ob_get_level()) {
    ob_end_flush();
}
@set_time_limit(0);

if (!\Bitrix\Main\Loader::includeModule('vspace.pagespeed')) {
    fwrite(STDERR, "Модуль vspace.pagespeed не установлен.\n");
    exit(1);
}

$args = array_slice($argv, 1);
$done = \Vspace\PageSpeed\Agent::runDue(in_array('--force', $args, true));

if (in_array('-v', $args, true)) {
    echo 'Выполнено: ', $done ? implode(', ', $done) : 'ничего (время агентов не подошло)', "\n";
    $config = \Vspace\PageSpeed\Options::config();
    $stats = (new \Vspace\PageSpeed\Images\WebpStore(rtrim($_SERVER['DOCUMENT_ROOT'], '/')))->stats();
    printf("WebP: %s; копий %d (%.1f МБ), оставлено исходниками %d, в очереди %d\n",
        $config->active && $config->webpActive ? 'включён' : 'выключен в настройках — очередь не обрабатывается',
        $stats['files'], $stats['bytes'] / 1048576, $stats['originals'], $stats['queue']);
}
