<?php
/**
 * Замеры Google PageSpeed Insights (API v5) — чтобы сравнить скорость до и после выкладки модуля.
 *
 * Для каждого адреса и устройства:
 *  - лаборатория (Lighthouse, один прогон на серверах Google): оценка, FCP, LCP, CLS, TBT, Speed Index, TTFB.
 *    Значения заметно скачут между прогонами, поэтому прогонов несколько и выводится медиана. Повторный запрос
 *    того же адреса PSI отдаёт из своего кеша, поэтому со второго прогона к адресу дописывается _psi=<число>;
 *  - поле (CrUX — реальные пользователи Chrome за 28 дней): LCP, CLS, INP. По этим данным Google оценивает
 *    Core Web Vitals для поиска. Они меняются постепенно: эффект выкладки виден через 2–4 недели.
 *
 *   php tools/psi.php [опции] [путь|URL ...]
 *
 *   --ab                        каждый адрес дважды: с модулем и с ?vps=off — сравнение в одном прогоне
 *   --label=ammina              метка серии (например, ammina до выкладки и vspace после)
 *   --compare=ammina            сравнить с медианами серии с этой меткой из файла результатов
 *   --runs=3                    прогонов на адрес и устройство
 *   --strategy=mobile,desktop   устройства
 *   --base=https://www.demis.ru домен для путей (тестовый сайт — --base=https://scilib.ru)
 *   --query=psi=…               дописать параметр к каждому адресу (например, пропуск через пароль тестового сайта)
 *   --out=<файл>                CSV с результатами, дописывается (по умолчанию tools/psi-results.csv)
 *   --parallel=4                одновременных запросов к API
 *   --key=<ключ>                ключ API; иначе переменная PSI_API_KEY, иначе настройка модуля
 *                               «Ключ API PageSpeed Insights» (скрипт поднимает ядро Битрикса своего сайта)
 * Без адресов измеряются основные типы страниц (DEFAULT_PATHS).
 *
 * Ключ: Google Cloud Console → включить «PageSpeed Insights API» → Учётные данные → Ключ API (бесплатно,
 * 25 000 запросов в день). Без ключа общая квота обычно исчерпана — ответ 429.
 *
 * PSI заходит на сайт с серверов Google: локальный dmsnew.ru ему недоступен, сайт за паролем (scilib.ru) —
 * только при исключении для запросов PSI: по User-Agent (PSI дописывает «Chrome-Lighthouse») или по параметру
 * (--query); по IP ненадёжно. Если страница ответила ошибкой (401, 404...), замер помечается ошибкой.
 *
 * --ab сравнивает модуль с ?vps=off на одном сервере в одно время — точнее, чем серии до и после выкладки.
 * PSI не администратор, поэтому ?vps=off сработает, только если на сайте включено «Отладочные параметры для
 * всех» (тестовый сайт); иначе оба варианта одинаковые.
 *
 * Запускать из PowerShell или cmd: Git Bash превращает аргумент «/» в путь Windows (C:/Program Files/Git/) —
 * там нужны полные адреса или MSYS_NO_PATHCONV=1.
 */

const API = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

const DEFAULT_PATHS = [
    '/',
    '/articles/',
    '/articles/poiskovaja-optimizacija-i-prodvizhenie-sajtov/',
    '/articles/link-seo-chto-eto/',
    '/services/otraslevaya-ekspertiza/prodvizhenie-medicinskih-sajtov/',
    '/services/kontekstnaja-reklama/',
];

/** Лабораторные метрики: колонка => аудит Lighthouse (numericValue, мс; CLS — без единиц). */
const LAB = [
    'fcp' => 'first-contentful-paint',
    'lcp' => 'largest-contentful-paint',
    'cls' => 'cumulative-layout-shift',
    'tbt' => 'total-blocking-time',
    'si' => 'speed-index',
    'ttfb' => 'server-response-time',
];

/** Полевые метрики CrUX: колонка => метрика (75-й процентиль; CLS приходит умноженным на 100). */
const FIELD = [
    'f_lcp' => 'LARGEST_CONTENTFUL_PAINT_MS',
    'f_cls' => 'CUMULATIVE_LAYOUT_SHIFT_SCORE',
    'f_inp' => 'INTERACTION_TO_NEXT_PAINT',
];

const COLUMNS = ['date', 'label', 'url', 'strategy', 'run', 'score', 'fcp', 'lcp', 'cls', 'tbt', 'si', 'ttfb',
    'f_lcp', 'f_cls', 'f_inp', 'f_category', 'f_scope', 'error'];

const MAX_ATTEMPTS = 3;

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    // ключ из настроек модуля: ядро Битрикса подключается здесь, в глобальной области, как в любом скрипте сайта
    $psiSettingsKey = null;
    $psiProlog = dirname(__DIR__, 4) . '/bitrix/modules/main/include/prolog_before.php';
    if (!isset(getopt('', ['key:'])['key']) && !getenv('PSI_API_KEY') && is_file($psiProlog)) {
        $_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__, 4);
        define('NO_KEEP_STATISTIC', true);
        define('NOT_CHECK_PERMISSIONS', true);
        define('NO_AGENT_CHECK', true);
        try {
            require $psiProlog;
            while (ob_get_level()) {
                ob_end_flush();
            }
            if (\Bitrix\Main\Loader::includeModule('vspace.pagespeed')) {
                $psiSettingsKey = \Vspace\PageSpeed\Options::config()->psiApiKey ?: null;
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, 'Настройки модуля не прочитаны: ' . $e->getMessage() . "\n");
        }
    }
    exit(main($argv, $psiSettingsKey));
}

function main(array $argv, ?string $settingsKey = null): int
{
    $options = getopt('', ['ab', 'label:', 'compare:', 'runs:', 'strategy:', 'base:', 'query:', 'out:', 'parallel:', 'key:', 'help'], $restIndex);
    if (isset($options['help'])) {
        preg_match('#/\*\*(.*?)\*/#s', file_get_contents(__FILE__), $m);
        echo preg_replace('#^ ?\* ?#m', '', $m[1]), "\n";
        return 0;
    }

    $base = rtrim($options['base'] ?? 'https://www.demis.ru', '/');
    $label = $options['label'] ?? date('Y-m-d');
    $runs = max(1, (int)($options['runs'] ?? 3));
    $strategies = array_values(array_intersect(explode(',', $options['strategy'] ?? 'mobile,desktop'), ['mobile', 'desktop']));
    $parallel = max(1, (int)($options['parallel'] ?? 4));
    $out = $options['out'] ?? __DIR__ . '/psi-results.csv';
    $key = ($options['key'] ?? null) ?: (getenv('PSI_API_KEY') ?: $settingsKey);
    $ab = isset($options['ab']);

    $query = ltrim($options['query'] ?? '', '?&');
    $urls = [];
    foreach (array_slice($argv, $restIndex) ?: DEFAULT_PATHS as $item) {
        $url = preg_match('#^https?://#i', $item) ? $item : $base . '/' . ltrim($item, '/');
        $urls[] = $query === '' ? $url : $url . (str_contains($url, '?') ? '&' : '?') . $query;
    }
    if (!$strategies) {
        fwrite(STDERR, "--strategy: mobile и/или desktop\n");
        return 1;
    }
    if ($key === null) {
        fwrite(STDERR, "Ключ API не задан (--key, PSI_API_KEY или настройка модуля): без него общая квота обычно исчерпана, будут ошибки 429.\n");
    }

    // вариант off — тот же адрес с ?vps=off (страница без модуля), в CSV — с меткой «<label>-off»
    $variants = $ab ? ['on' => $label, 'off' => $label . '-off'] : ['on' => $label];
    $jobs = [];
    foreach ($urls as $url) {
        foreach ($variants as $variant => $variantLabel) {
            $request = $variant === 'off' ? $url . (str_contains($url, '?') ? '&' : '?') . 'vps=off' : $url;
            foreach ($strategies as $strategy) {
                for ($run = 1; $run <= $runs; $run++) {
                    // PSI отдаёт повторный запрос того же адреса из своего кеша (три «прогона» — одна оценка),
                    // поэтому со второго прогона — свой параметр. Первый — без него: поле (CrUX) ищется по адресу
                    $unique = $run === 1 ? $request
                        : $request . (str_contains($request, '?') ? '&' : '?') . '_psi=' . mt_rand(100000, 999999);
                    $jobs[] = ['url' => $url, 'request' => $unique, 'label' => $variantLabel, 'strategy' => $strategy,
                        'run' => $run, 'attempt' => 1, 'at' => 0.0];
                }
            }
        }
    }
    fwrite(STDERR, sprintf("Запросов к PSI: %d (адресов: %d%s, устройства: %s, прогонов: %d); каждый 15–60 с, одновременно: %d\n",
        count($jobs), count($urls), $ab ? ' × с модулем и ?vps=off' : '', implode(', ', $strategies), $runs, $parallel));

    $date = date('Y-m-d H:i');
    $rows = [];
    foreach (runJobs($jobs, $key, $parallel) as $job) {
        $rows[] = ['date' => $date, 'label' => $job['label'], 'url' => $job['url'], 'strategy' => $job['strategy'], 'run' => $job['run']] + $job['result'];
    }
    saveCsv($out, $rows);
    $current = array_filter($rows, static fn(array $row): bool => $row['label'] === $label);

    echo "\nСерия «{$label}», медиана прогонов (прогонов: {$runs}). Лаборатория — Lighthouse на серверах Google, поле — реальные пользователи Chrome за 28 дней (p75).\n\n";
    printTable(medians($current), $urls, $strategies);
    if ($ab) {
        echo "\nБез модуля (?vps=off):\n\n";
        printTable(medians(array_filter($rows, static fn(array $row): bool => $row['label'] === $label . '-off')), $urls, $strategies);
        echo "\nСравнение: без модуля → с модулем\n\n";
        printComparison(medians(array_filter($rows, static fn(array $row): bool => $row['label'] === $label . '-off')), medians($current), $urls, $strategies);
        echo "?vps=off работает для PSI, только если на сайте включено «Отладочные параметры для всех» — иначе варианты одинаковые.\n";
    }
    echo "\nРезультаты дописаны в {$out}\n";

    if (isset($options['compare'])) {
        $before = medians(array_filter(readCsv($out), static fn(array $row): bool => $row['label'] === $options['compare']));
        if (!$before) {
            echo "\nСерии «{$options['compare']}» в {$out} нет.\n";
        } else {
            echo "\nСравнение: «{$options['compare']}» → «{$label}»\n\n";
            printComparison($before, medians($current), $urls, $strategies);
        }
    }

    $failed = count(array_filter($rows, static fn(array $row): bool => $row['error'] !== ''));
    return $failed === count($rows) ? 1 : 0;
}

/**
 * Выполняет запросы к API по $parallel одновременно; 429, 5xx и сетевые ошибки повторяются с паузой.
 * @return list<array> задания с ключом result
 */
function runJobs(array $queue, ?string $key, int $parallel): array
{
    $multi = curl_multi_init();
    $active = [];
    $done = [];
    $total = count($queue);

    while ($queue || $active) {
        $now = microtime(true);
        foreach ($queue as $i => $job) {
            if (count($active) >= $parallel) {
                break;
            }
            if ($job['at'] > $now) {
                continue;
            }
            unset($queue[$i]);
            $handle = curl_init(API . '?' . http_build_query(array_filter([
                'url' => $job['request'],
                'strategy' => $job['strategy'],
                'category' => 'performance',
                'locale' => 'ru',
                'key' => $key,
            ])));
            curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 180, CURLOPT_CONNECTTIMEOUT => 20]);
            curl_multi_add_handle($multi, $handle);
            $active[spl_object_id($handle)] = [$handle, $job];
        }

        curl_multi_exec($multi, $running);
        curl_multi_select($multi, 1.0);
        while ($info = curl_multi_info_read($multi)) {
            [$handle, $job] = $active[spl_object_id($info['handle'])];
            unset($active[spl_object_id($info['handle'])]);
            $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $body = (string)curl_multi_getcontent($handle);
            $curlError = $info['result'] !== CURLE_OK ? curl_strerror($info['result']) : '';
            curl_multi_remove_handle($multi, $handle);
            curl_close($handle);

            $retryable = $curlError !== '' || $status === 429 || $status >= 500;
            if ($retryable && $job['attempt'] < MAX_ATTEMPTS) {
                $job['attempt']++;
                $job['at'] = microtime(true) + 10 * $job['attempt'];
                $queue[] = $job;
                fwrite(STDERR, sprintf("  повтор %s %s: %s\n", $job['strategy'], $job['request'], $curlError ?: 'HTTP ' . $status));
                continue;
            }
            $job['result'] = $curlError !== '' ? emptyResult($curlError) : parseResponse($status, $body);
            $done[] = $job;
            fwrite(STDERR, sprintf("  [%d/%d] %-7s %s — %s\n", count($done), $total, $job['strategy'], $job['request'],
                $job['result']['error'] !== '' ? 'ошибка: ' . $job['result']['error'] : 'оценка ' . $job['result']['score']));
        }
        if (!$active && $queue) {
            usleep(500000); // все оставшиеся задания ждут паузы перед повтором
        }
    }
    curl_multi_close($multi);
    return $done;
}

function emptyResult(string $error): array
{
    $result = array_fill_keys(array_slice(COLUMNS, 5), '');
    $result['error'] = $error;
    return $result;
}

function parseResponse(int $status, string $body): array
{
    $data = json_decode($body, true);
    if (!is_array($data)) {
        return emptyResult('HTTP ' . $status . ', ответ не JSON');
    }
    if (isset($data['error'])) {
        return emptyResult('HTTP ' . $status . ': ' . ($data['error']['message'] ?? 'ошибка API'));
    }
    $lighthouse = $data['lighthouseResult'] ?? [];
    $runtimeError = $lighthouse['runtimeError']['code'] ?? 'NO_ERROR';
    if ($runtimeError !== 'NO_ERROR') {
        return emptyResult($lighthouse['runtimeError']['message'] ?? $runtimeError);
    }
    // страница ответила ошибкой (401 за паролем, 404, 500) — Lighthouse всё равно ставит оценку, причём высокую:
    // мерить нечего
    $document = null;
    foreach ($lighthouse['audits']['network-requests']['details']['items'] ?? [] as $request) {
        if (($request['resourceType'] ?? '') === 'Document') {
            $document = (int)($request['statusCode'] ?? 0);
            break;
        }
    }
    if ($document === null && preg_match('/Status code: (\d{3})/', implode(' ', $lighthouse['runWarnings'] ?? []), $m)) {
        $document = (int)$m[1];
    }
    if ($document !== null && $document >= 400) {
        return emptyResult('страница ответила ' . $document . ($document === 401 ? ' (закрыта паролем, нет исключения для PSI)' : ''));
    }

    $result = emptyResult('');
    $score = $lighthouse['categories']['performance']['score'] ?? null;
    $result['score'] = $score === null ? '' : (int)round($score * 100);
    foreach (LAB as $column => $audit) {
        $value = $lighthouse['audits'][$audit]['numericValue'] ?? null;
        $result[$column] = $value === null ? '' : ($column === 'cls' ? round($value, 3) : (int)round($value));
    }

    // если по адресу мало данных, PSI отдаёт данные всего сайта (origin_fallback)
    $field = $data['loadingExperience'] ?? [];
    if (!empty($field['metrics'])) {
        $result['f_scope'] = !empty($field['origin_fallback']) ? 'origin' : 'url';
        $result['f_category'] = $field['overall_category'] ?? '';
        foreach (FIELD as $column => $metric) {
            $value = $field['metrics'][$metric]['percentile'] ?? null;
            $result[$column] = $value === null ? '' : ($column === 'f_cls' ? round($value / 100, 2) : (int)$value);
        }
    }
    return $result;
}

function saveCsv(string $file, array $rows): void
{
    $new = !is_file($file);
    $handle = fopen($file, 'ab');
    if ($new) {
        fputcsv($handle, COLUMNS);
    }
    foreach ($rows as $row) {
        fputcsv($handle, array_map(static fn(string $column) => $row[$column] ?? '', COLUMNS));
    }
    fclose($handle);
}

function readCsv(string $file): array
{
    $rows = [];
    if (($handle = @fopen($file, 'rb')) === false) {
        return $rows;
    }
    $header = fgetcsv($handle);
    while (($line = fgetcsv($handle)) !== false) {
        if (count($line) === count($header)) {
            $rows[] = array_combine($header, $line);
        }
    }
    fclose($handle);
    return $rows;
}

/** Медианы по прогонам: [url][strategy] => метрики; ошибки не учитываются. */
function medians(array $rows): array
{
    $groups = [];
    foreach ($rows as $row) {
        if ($row['error'] === '' && $row['score'] !== '') {
            $groups[$row['url']][$row['strategy']][] = $row;
        }
    }
    $result = [];
    foreach ($groups as $url => $byStrategy) {
        foreach ($byStrategy as $strategy => $list) {
            $median = ['runs' => count($list)];
            foreach (array_merge(['score'], array_keys(LAB)) as $column) {
                $values = array_map('floatval', array_filter(array_column($list, $column), static fn($v) => $v !== ''));
                sort($values);
                $n = count($values);
                $median[$column] = $n ? ($n % 2 ? $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2) : null;
            }
            // поле — из первого прогона: у остальных в адресе _psi, и CrUX отдаёт данные всего сайта вместо страницы
            $field = current(array_filter($list, static fn(array $row): bool => (int)$row['run'] === 1)) ?: end($list);
            foreach (['f_lcp', 'f_cls', 'f_inp', 'f_category', 'f_scope'] as $column) {
                $median[$column] = $field[$column];
            }
            $result[$url][$strategy] = $median;
        }
    }
    return $result;
}

function seconds(?float $ms): string
{
    return $ms === null ? '—' : number_format($ms / 1000, 1, '.', '') . ' с';
}

function millis(?float $ms): string
{
    return $ms === null ? '—' : (int)round($ms) . ' мс';
}

/** Путь страницы; домен — только если в замере адреса с разных доменов. */
function shortUrl(string $url, bool $withHost = false): string
{
    $path = ($withHost ? parse_url($url, PHP_URL_HOST) : '') . (parse_url($url, PHP_URL_PATH) ?: '/');
    return mb_strlen($path) > 44 ? mb_substr($path, 0, 20) . '…' . mb_substr($path, -23) : $path;
}

function pad(string $text, int $width): string
{
    return $text . str_repeat(' ', max(0, $width - mb_strlen($text)));
}

function printTable(array $medians, array $urls, array $strategies): void
{
    $withHost = count(array_unique(array_map(static fn(string $url) => parse_url($url, PHP_URL_HOST), $urls))) > 1;
    $header = ['Страница', 'Устр.', 'Оценка', 'FCP', 'LCP', 'CLS', 'TBT', 'SI', 'TTFB', '│ Поле: LCP', 'CLS', 'INP', ''];
    $widths = [46, 8, 7, 7, 7, 7, 8, 7, 8, 12, 6, 8, 0];
    echo implode('', array_map('pad', $header, $widths)), "\n";
    foreach ($urls as $url) {
        foreach ($strategies as $strategy) {
            $m = $medians[$url][$strategy] ?? null;
            if ($m === null) {
                echo pad(shortUrl($url, $withHost), 46), pad($strategy, 8), "нет данных (ошибки — в выводе выше и в CSV)\n";
                continue;
            }
            $field = $m['f_scope'] === '' ? ['│ нет данных', '', '', '']
                : ['│ ' . seconds($m['f_lcp'] === '' ? null : (float)$m['f_lcp']), (string)$m['f_cls'],
                    $m['f_inp'] === '' ? '—' : $m['f_inp'] . ' мс', $m['f_scope'] === 'origin' ? '(весь сайт)' : ''];
            echo implode('', array_map('pad', [
                shortUrl($url, $withHost), $strategy, (string)(int)$m['score'], seconds($m['fcp']), seconds($m['lcp']),
                $m['cls'] === null ? '—' : number_format($m['cls'], 3, '.', ''), millis($m['tbt']), seconds($m['si']),
                millis($m['ttfb']), ...$field,
            ], $widths)), "\n";
        }
    }
}

function printComparison(array $before, array $after, array $urls, array $strategies): void
{
    $withHost = count(array_unique(array_map(static fn(string $url) => parse_url($url, PHP_URL_HOST), $urls))) > 1;
    $header = ['Страница', 'Устр.', 'Оценка', 'FCP', 'LCP', 'CLS', 'TBT'];
    $widths = [46, 8, 14, 16, 16, 18, 0];
    echo implode('', array_map('pad', $header, $widths)), "\n";
    $pair = static function (?float $a, ?float $b, callable $format): string {
        return $a === null || $b === null ? '—' : $format($a) . ' → ' . $format($b);
    };
    foreach ($urls as $url) {
        foreach ($strategies as $strategy) {
            $a = $before[$url][$strategy] ?? null;
            $b = $after[$url][$strategy] ?? null;
            if ($a === null || $b === null) {
                echo pad(shortUrl($url, $withHost), 46), pad($strategy, 8), "нет данных в одной из серий\n";
                continue;
            }
            echo implode('', array_map('pad', [
                shortUrl($url, $withHost), $strategy,
                $pair($a['score'], $b['score'], static fn(float $v): string => (string)(int)$v),
                $pair($a['fcp'], $b['fcp'], static fn(float $v): string => number_format($v / 1000, 1, '.', '')),
                $pair($a['lcp'], $b['lcp'], static fn(float $v): string => number_format($v / 1000, 1, '.', '')),
                $pair($a['cls'], $b['cls'], static fn(float $v): string => number_format($v, 3, '.', '')),
                $pair($a['tbt'], $b['tbt'], static fn(float $v): string => (string)(int)$v),
            ], $widths)), "\n";
        }
    }
    echo "\nFCP и LCP — в секундах, TBT — в мс.\n";
}
