<?php
/**
 * Настройки модуля (Настройки → Настройки продукта → Настройки модулей). Подключается из
 * bitrix/modules/main/admin/settings.php — пролог админки уже подключён, вывод буферизуется, поэтому после
 * сохранения работает LocalRedirect(). Поля строятся по схеме $vpsTabs; ключи и значения по умолчанию —
 * Vspace\PageSpeed\Config::DEFAULTS, чтение — Vspace\PageSpeed\Options.
 *
 * Свои переменные — с префиксом $vps: файл выполняется в глобальной области видимости.
 *
 * @global CMain $APPLICATION
 * @global CUser $USER
 */

use Bitrix\Main\Config\Option;
use Bitrix\Main\Context;
use Bitrix\Main\Loader;
use Vspace\PageSpeed\Agent;
use Vspace\PageSpeed\Config;
use Vspace\PageSpeed\Images\WebpStore;
use Vspace\PageSpeed\Images\WebpSupport;
use Vspace\PageSpeed\Optimizer;
use Vspace\PageSpeed\Options;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

$module_id = 'vspace.pagespeed';

if (!$USER->IsAdmin() || !Loader::includeModule($module_id)) {
    return;
}

IncludeModuleLangFile($_SERVER['DOCUMENT_ROOT'] . BX_ROOT . '/modules/main/options.php');
IncludeModuleLangFile(__FILE__);

/**
 * Схема полей по вкладкам. Тип: checkbox | text | textarea | [min, max] (целое) | список значений select.
 * Строка-ключ, начинающаяся с «#», — подзаголовок.
 */
$vpsTabs = [
    'general' => [
        'ACTIVE' => 'checkbox',
        'EXCLUDE_URLS' => 'textarea',
        'EXCLUDE_HOSTS' => 'textarea',
        'EXCLUDE_TEMPLATES' => 'textarea',
        'AMMINA_DISABLE' => 'checkbox',
        'DEBUG_PARAMS' => 'checkbox',
    ],
    'critical' => [
        'CRITICAL_ACTIVE' => 'checkbox',
        'CRITICAL_EXCLUDE_URLS' => 'textarea',
        '#FOLD' => null,
        'FOLD_START_CLASSES' => 'text',
        'FOLD_BLOCK_CLASSES' => 'textarea',
        'FOLD_BLOCKS' => [0, 50],
        'FOLD_MAX_BYTES' => [0, 2000000],
        'FOLD_SKIP_CLASSES' => 'textarea',
        'ADD_CLASSES' => 'textarea',
        'ADD_IDS' => 'textarea',
        '#FILES' => null,
        'EXCLUDE_FILES' => 'textarea',
        'INLINE_STYLES' => 'checkbox',
        'INLINE_STYLES_MIN' => [0, 10000000],
        'ASYNC_MODE' => ['preload', 'print'],
        'CRITICAL_DESKTOP_WIDTH' => [0, 10000],
        '#CONTENT' => null,
        'DROP_INTERACTION' => 'checkbox',
        'FONT_FACE' => ['used', 'all', 'none'],
        'FONT_DISPLAY_SWAP' => 'checkbox',
        'CRITICAL_MAX_SIZE' => [0, 10000000],
    ],
    'images' => [
        'IMAGES_ACTIVE' => 'checkbox',
        'WEBP_ACTIVE' => 'checkbox',
    ],
    'js' => [
        'JS_ACTIVE' => 'checkbox',
        'JS_EXCLUDE_FILES' => 'textarea',
    ],
    'cache' => [
        'CACHE_TTL_DAYS' => [1, 365],
        '#PSI' => null,
        'PSI_API_KEY' => 'text',
    ],
];

$tabControl = new CAdminTabControl('tabControl', [
    ['DIV' => 'vps_general', 'TAB' => GetMessage('VPS_OPT_TAB_GENERAL'), 'TITLE' => GetMessage('VPS_OPT_TAB_GENERAL_TITLE')],
    ['DIV' => 'vps_critical', 'TAB' => GetMessage('VPS_OPT_TAB_CRITICAL'), 'TITLE' => GetMessage('VPS_OPT_TAB_CRITICAL_TITLE')],
    ['DIV' => 'vps_images', 'TAB' => GetMessage('VPS_OPT_TAB_IMAGES'), 'TITLE' => GetMessage('VPS_OPT_TAB_IMAGES_TITLE')],
    ['DIV' => 'vps_js', 'TAB' => GetMessage('VPS_OPT_TAB_JS'), 'TITLE' => GetMessage('VPS_OPT_TAB_JS_TITLE')],
    ['DIV' => 'vps_cache', 'TAB' => GetMessage('VPS_OPT_TAB_CACHE'), 'TITLE' => GetMessage('VPS_OPT_TAB_CACHE_TITLE')],
]);

$vpsRequest = Context::getCurrent()->getRequest();
$vpsRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/');
$vpsStorage = Optimizer::storage($vpsRoot);
$vpsPublicStorage = Optimizer::publicStorage($vpsRoot);
$vpsWebp = new WebpStore($vpsRoot);
$vpsErrors = [];
$vpsPosted = null; // введённые значения — чтобы при ошибке показать их, а не сохранённые
$vpsRedirect = $APPLICATION->GetCurPage() . '?mid=' . urlencode($module_id) . '&lang=' . urlencode(LANGUAGE_ID);

if ($vpsRequest->isPost() && check_bitrix_sessid()) {
    if ((string)$vpsRequest->getPost('vps_clear_cache') !== '') {
        $vpsStorage->clear();
        $vpsPublicStorage->clear();
        LocalRedirect($vpsRedirect . '&vps_cleared=Y&' . $tabControl->ActiveTabParam());
    }
    if ((string)$vpsRequest->getPost('vps_clear_webp') !== '') {
        $vpsWebp->clear();
        LocalRedirect($vpsRedirect . '&vps_webp_cleared=Y&' . $tabControl->ActiveTabParam());
    }
    if ((string)$vpsRequest->getPost('Update') !== '' || (string)$vpsRequest->getPost('Apply') !== '') {
        $vpsPosted = [];
        foreach ($vpsTabs as $vpsFields) {
            foreach ($vpsFields as $vpsCode => $vpsType) {
                if ($vpsType === null) {
                    continue;
                }
                $vpsValue = $vpsRequest->getPost($vpsCode);
                if ($vpsType === 'checkbox') {
                    $vpsPosted[$vpsCode] = $vpsValue === 'Y' ? 'Y' : 'N';
                } elseif (is_array($vpsType) && is_int($vpsType[0])) {
                    $vpsPosted[$vpsCode] = trim((string)$vpsValue);
                    if (!preg_match('/^\d+$/', $vpsPosted[$vpsCode]) || (int)$vpsPosted[$vpsCode] < $vpsType[0] || (int)$vpsPosted[$vpsCode] > $vpsType[1]) {
                        $vpsErrors[] = GetMessage('VPS_OPT_ERR_RANGE', [
                            '#NAME#' => GetMessage('VPS_OPT_' . $vpsCode),
                            '#MIN#' => $vpsType[0],
                            '#MAX#' => $vpsType[1],
                        ]);
                    }
                } elseif (is_array($vpsType)) {
                    $vpsPosted[$vpsCode] = in_array($vpsValue, $vpsType, true) ? $vpsValue : $vpsType[0];
                } else {
                    $vpsPosted[$vpsCode] = trim(str_replace("\r\n", "\n", (string)$vpsValue));
                }
            }
        }
        if (!$vpsErrors) {
            foreach ($vpsPosted as $vpsCode => $vpsValue) {
                Options::set($vpsCode, (string)$vpsValue);
            }
            Agent::register(); // агента WebP нет у модуля, установленного до появления WebP
            LocalRedirect($vpsRedirect . '&' . $tabControl->ActiveTabParam());
        }
    }
}

if ($vpsErrors) {
    CAdminMessage::ShowMessage(['MESSAGE' => implode('<br>', array_map('htmlspecialcharsbx', $vpsErrors)), 'TYPE' => 'ERROR']);
}
if ($vpsRequest->getQuery('vps_cleared') === 'Y') {
    CAdminMessage::ShowNote(GetMessage('VPS_OPT_CACHE_CLEARED'));
}
if ($vpsRequest->getQuery('vps_webp_cleared') === 'Y') {
    CAdminMessage::ShowNote(GetMessage('VPS_OPT_WEBP_CLEARED'));
}

// «N мин назад» — время последнего запуска агента WebP из cron
$vpsAgo = static function (int $seconds): string {
    foreach ([[86400, 'D'], [3600, 'H'], [60, 'M']] as [$unit, $code]) {
        if ($seconds >= $unit * 2) {
            return GetMessage('VPS_OPT_AGO_' . $code, ['#N#' => intdiv($seconds, $unit)]);
        }
    }
    return GetMessage('VPS_OPT_AGO_S', ['#N#' => $seconds]);
};
// WebP включён, но копии не появятся: в GD нет WebP или агент не запускается из cron
$vpsCronAge = $vpsWebp->cronAge();
if (Options::config()->active && Options::config()->webpActive) {
    if (!WebpSupport::server()) {
        CAdminMessage::ShowMessage(['MESSAGE' => GetMessage('VPS_OPT_WEBP_NO_GD'), 'TYPE' => 'ERROR']);
    } elseif (!$vpsWebp->cronWorks()) {
        $vpsCronMode = Option::get('main', 'agents_use_crontab', 'N') === 'Y' || (defined('BX_CRONTAB_SUPPORT') && BX_CRONTAB_SUPPORT === true)
            || Option::get('main', 'check_agents', 'Y') === 'N';
        CAdminMessage::ShowMessage([
            'MESSAGE' => GetMessage('VPS_OPT_WEBP_NO_CRON'),
            'DETAILS' => GetMessage($vpsCronMode ? 'VPS_OPT_WEBP_NO_CRON_SILENT' : 'VPS_OPT_WEBP_NO_CRON_HITS', [
                '#AGO#' => $vpsCronAge === null ? GetMessage('VPS_OPT_WEBP_CRON_NEVER') : GetMessage('VPS_OPT_WEBP_CRON_AGO', ['#AGO#' => $vpsAgo($vpsCronAge)]),
                '#HELP#' => '/bitrix/admin/vspace_pagespeed_help.php?lang=' . LANGUAGE_ID . '#webp',
            ]),
            'TYPE' => 'ERROR',
            'HTML' => true,
        ]);
    }
}

$vpsValue = static function (string $code) use ($module_id, $vpsPosted): string {
    return (string)($vpsPosted[$code] ?? \Bitrix\Main\Config\Option::get($module_id, $code, (string)Config::DEFAULTS[$code], ''));
};
?>
<p style="margin:0 0 12px">
    <a href="/bitrix/admin/vspace_pagespeed_help.php?lang=<?= LANGUAGE_ID ?>" target="_blank"><?= GetMessage('VPS_OPT_HELP') ?></a>
    — <?= GetMessage('VPS_OPT_HELP_HINT') ?>
</p>
<form method="post" action="<?= htmlspecialcharsbx($vpsRedirect) ?>">
    <?php $tabControl->Begin(); ?>
    <?php foreach ($vpsTabs as $vpsTab => $vpsFields): ?>
        <?php $tabControl->BeginNextTab(); ?>
        <?php foreach ($vpsFields as $vpsCode => $vpsType): ?>
            <?php if ($vpsType === null): ?>
                <tr class="heading"><td colspan="2"><?= GetMessage('VPS_OPT_HEADING_' . substr($vpsCode, 1)) ?></td></tr>
                <?php continue; ?>
            <?php endif; ?>
            <?php $vpsHint = GetMessage('VPS_OPT_' . $vpsCode . '_HINT'); ?>
            <tr>
                <td width="40%" class="adm-detail-valign-top">
                    <label for="vps_<?= $vpsCode ?>"><?= GetMessage('VPS_OPT_' . $vpsCode) ?></label>
                </td>
                <td width="60%">
                    <?php if ($vpsType === 'checkbox'): ?>
                        <input type="hidden" name="<?= $vpsCode ?>" value="N">
                        <input type="checkbox" id="vps_<?= $vpsCode ?>" name="<?= $vpsCode ?>" value="Y" <?= $vpsValue($vpsCode) === 'Y' ? 'checked' : '' ?>>
                    <?php elseif ($vpsType === 'textarea'): ?>
                        <textarea id="vps_<?= $vpsCode ?>" name="<?= $vpsCode ?>" rows="5" cols="60"><?= htmlspecialcharsbx($vpsValue($vpsCode)) ?></textarea>
                    <?php elseif ($vpsType === 'text'): ?>
                        <input type="text" id="vps_<?= $vpsCode ?>" name="<?= $vpsCode ?>" size="60" value="<?= htmlspecialcharsbx($vpsValue($vpsCode)) ?>">
                    <?php elseif (is_int($vpsType[0])): ?>
                        <input type="text" id="vps_<?= $vpsCode ?>" name="<?= $vpsCode ?>" size="10" value="<?= htmlspecialcharsbx($vpsValue($vpsCode)) ?>">
                    <?php else: ?>
                        <select id="vps_<?= $vpsCode ?>" name="<?= $vpsCode ?>">
                            <?php foreach ($vpsType as $vpsOption): ?>
                                <option value="<?= $vpsOption ?>" <?= $vpsValue($vpsCode) === $vpsOption ? 'selected' : '' ?>><?= GetMessage('VPS_OPT_' . $vpsCode . '_' . strtoupper($vpsOption)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                    <?php if ($vpsHint): ?>
                        <div style="margin-top:4px;color:#7a7a7a;max-width:640px"><?= $vpsHint ?></div>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>

        <?php if ($vpsTab === 'images'): ?>
            <?php $vpsWebpStats = $vpsWebp->stats(); ?>
            <tr class="heading"><td colspan="2"><?= GetMessage('VPS_OPT_HEADING_WEBP') ?></td></tr>
            <tr>
                <td width="40%"><?= GetMessage('VPS_OPT_WEBP_CRON') ?></td>
                <td width="60%"><?= $vpsCronAge === null ? GetMessage('VPS_OPT_WEBP_CRON_NEVER') : GetMessage('VPS_OPT_WEBP_CRON_AGO', ['#AGO#' => $vpsAgo($vpsCronAge)]) ?></td>
            </tr>
            <tr>
                <td><?= GetMessage('VPS_OPT_WEBP_FILES') ?></td>
                <td><?= GetMessage('VPS_OPT_STATS_VALUE', ['#FILES#' => $vpsWebpStats['files'], '#SIZE#' => CFile::FormatSize($vpsWebpStats['bytes'])]) ?></td>
            </tr>
            <tr>
                <td class="adm-detail-valign-top"><?= GetMessage('VPS_OPT_WEBP_ORIGINALS') ?></td>
                <td>
                    <?= (int)$vpsWebpStats['originals'] ?>
                    <div style="margin-top:4px;color:#7a7a7a;max-width:640px"><?= GetMessage('VPS_OPT_WEBP_ORIGINALS_HINT') ?></div>
                </td>
            </tr>
            <tr>
                <td><?= GetMessage('VPS_OPT_WEBP_QUEUE') ?></td>
                <td><?= (int)$vpsWebpStats['queue'] ?></td>
            </tr>
            <tr>
                <td></td>
                <td><input type="submit" name="vps_clear_webp" value="<?= GetMessage('VPS_OPT_WEBP_CLEAR') ?>"></td>
            </tr>
        <?php endif; ?>

        <?php if ($vpsTab === 'cache'): ?>
            <?php
            $vpsIndex = $vpsStorage->stats('index');
            $vpsCritical = $vpsStorage->stats('critical');
            $vpsPublic = $vpsPublicStorage->stats();
            ?>
            <tr class="heading"><td colspan="2"><?= GetMessage('VPS_OPT_HEADING_STATS') ?></td></tr>
            <tr>
                <td width="40%"><?= GetMessage('VPS_OPT_STATS_CRITICAL') ?></td>
                <td width="60%"><?= GetMessage('VPS_OPT_STATS_VALUE', ['#FILES#' => $vpsCritical['files'], '#SIZE#' => CFile::FormatSize($vpsCritical['bytes'])]) ?></td>
            </tr>
            <tr>
                <td><?= GetMessage('VPS_OPT_STATS_INDEX') ?></td>
                <td><?= GetMessage('VPS_OPT_STATS_VALUE', ['#FILES#' => $vpsIndex['files'], '#SIZE#' => CFile::FormatSize($vpsIndex['bytes'])]) ?></td>
            </tr>
            <tr>
                <td><?= GetMessage('VPS_OPT_STATS_PUBLIC') ?></td>
                <td><?= GetMessage('VPS_OPT_STATS_VALUE', ['#FILES#' => $vpsPublic['files'], '#SIZE#' => CFile::FormatSize($vpsPublic['bytes'])]) ?></td>
            </tr>
            <tr>
                <td></td>
                <td><input type="submit" name="vps_clear_cache" value="<?= GetMessage('VPS_OPT_CLEAR_CACHE') ?>"></td>
            </tr>

            <tr class="heading"><td colspan="2"><?= GetMessage('VPS_OPT_HEADING_CHECK') ?></td></tr>
            <tr>
                <td class="adm-detail-valign-top"><label for="vps_check_url"><?= GetMessage('VPS_OPT_CHECK_URL') ?></label></td>
                <td>
                    <input type="text" id="vps_check_url" size="60" value="/">
                    <div style="margin-top:6px">
                        <a href="/?vps_critical=only" target="_blank" data-vps-check="vps_critical=only"><?= GetMessage('VPS_OPT_CHECK_CRITICAL') ?></a> ·
                        <a href="/?vps=off" target="_blank" data-vps-check="vps=off"><?= GetMessage('VPS_OPT_CHECK_OFF') ?></a> ·
                        <a href="/?vps_debug=1" target="_blank" data-vps-check="vps_debug=1"><?= GetMessage('VPS_OPT_CHECK_DEBUG') ?></a>
                    </div>
                    <div style="margin-top:4px;color:#7a7a7a;max-width:640px"><?= GetMessage('VPS_OPT_CHECK_HINT') ?></div>
                    <script>
                        (function () {
                            var input = document.getElementById('vps_check_url');
                            var links = document.querySelectorAll('[data-vps-check]');
                            function update() {
                                var url = input.value.trim() || '/';
                                for (var i = 0; i < links.length; i++) {
                                    links[i].href = url + (url.indexOf('?') === -1 ? '?' : '&') + links[i].getAttribute('data-vps-check');
                                }
                            }
                            input.addEventListener('input', update);
                        })();
                    </script>
                </td>
            </tr>
        <?php endif; ?>
    <?php endforeach; ?>

    <?php $tabControl->Buttons(); ?>
    <input type="submit" name="Update" value="<?= GetMessage('MAIN_SAVE') ?>" class="adm-btn-save">
    <input type="submit" name="Apply" value="<?= GetMessage('MAIN_OPT_APPLY') ?>">
    <?= bitrix_sessid_post() ?>
    <?php $tabControl->End(); ?>
</form>
