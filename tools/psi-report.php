<?php
/**
 * HTML-отчёт по истории замеров PSI (CSV из tools/psi.php): последний замер, графики и таблица по датам —
 * с модулем и без него (?vps=off), по сайтам и устройствам. Файл самодостаточный (без внешних библиотек),
 * открывается в браузере. Пересобирается после каждого замера psi.php и по `php tools/psi.php --report`.
 *
 * Подключается из psi.php: использует его medians().
 */

/** Записывает отчёт рядом с CSV; путь к отчёту. */
function writeReport(string $csv): string
{
    $file = dirname($csv) . '/psi-report.html';
    file_put_contents($file, psiReportHtml(psiReportData(readCsv($csv)), $csv));
    return $file;
}

/**
 * Серии (один запуск psi.php: дата + метка; «<метка>-off» — та же серия без модуля) и медианы по страницам.
 * @return array{series: list<array{date:string, label:string}>, points: list<array>}
 */
function psiReportData(array $rows): array
{
    $series = [];
    $groups = [];
    foreach ($rows as $row) {
        if (($row['error'] ?? '') !== '' || ($row['score'] ?? '') === '') {
            continue;
        }
        $off = str_ends_with($row['label'], '-off');
        $label = $off ? substr($row['label'], 0, -4) : $row['label'];
        $id = $row['date'] . '|' . $label;
        $series[$id] ??= ['date' => $row['date'], 'label' => $label];
        $groups[$id][$off ? 'off' : 'on'][] = $row;
    }
    ksort($series, SORT_STRING); // «Y-m-d H:i|метка» — по времени

    $points = [];
    $index = 0;
    foreach ($series as $id => $info) {
        $on = medians($groups[$id]['on'] ?? []);
        $off = medians($groups[$id]['off'] ?? []);
        foreach (array_unique(array_merge(array_keys($on), array_keys($off))) as $url) {
            foreach (['mobile', 'desktop'] as $strategy) {
                if (!isset($on[$url][$strategy]) && !isset($off[$url][$strategy])) {
                    continue;
                }
                $points[] = [
                    's' => $index,
                    'host' => (string)parse_url($url, PHP_URL_HOST),
                    'path' => (string)(parse_url($url, PHP_URL_PATH) ?: '/'),
                    'device' => $strategy,
                    'on' => psiReportMetrics($on[$url][$strategy] ?? null, true),
                    'off' => psiReportMetrics($off[$url][$strategy] ?? null, false),
                ];
            }
        }
        $index++;
    }
    return ['series' => array_values($series), 'points' => $points];
}

function psiReportMetrics(?array $median, bool $withField): ?array
{
    if ($median === null) {
        return null;
    }
    $result = ['runs' => $median['runs']];
    foreach (['score', 'fcp', 'lcp', 'cls', 'tbt', 'si', 'ttfb'] as $key) {
        $result[$key] = $median[$key];
    }
    if ($withField) {
        foreach (['f_lcp', 'f_cls', 'f_inp', 'f_scope'] as $key) {
            $result[$key] = ($median[$key] ?? '') === '' ? null : (is_numeric($median[$key]) ? (float)$median[$key] : $median[$key]);
        }
    }
    return $result;
}

function psiReportHtml(array $data, string $csv): string
{
    $data['generated'] = date('d.m.Y H:i');
    $data['source'] = basename($csv);
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_PRESERVE_ZERO_FRACTION);
    return str_replace('/*DATA*/', $json, PSI_REPORT_TEMPLATE);
}

const PSI_REPORT_TEMPLATE = <<<'HTML'
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Замеры скорости</title>
<style>
:root {
  color-scheme: light;
  --page: #f9f9f7;
  --surface: #fcfcfb;
  --ink: #0b0b0b;
  --ink-2: #52514e;
  --muted: #898781;
  --grid: #e1e0d9;
  --axis: #c3c2b7;
  --border: rgba(11,11,11,0.10);
  --accent: #2a78d6;
  --other: #898781;
  --good: #0ca30c;
  --warning: #fab219;
  --critical: #d03b3b;
  --wash: rgba(42,120,214,0.08);
  --good-text: #006300;
  --critical-text: #d03b3b;
}
@media (prefers-color-scheme: dark) {
  :root:not([data-theme="light"]) {
    color-scheme: dark;
    --page: #0d0d0d;
    --surface: #1a1a19;
    --ink: #f0efec;
    --ink-2: #c3c2b7;
    --muted: #898781;
    --grid: #2c2c2a;
    --axis: #383835;
    --border: rgba(255,255,255,0.10);
    --accent: #3987e5;
    --other: #898781;
    --wash: rgba(57,135,229,0.14);
    --good-text: #0ca30c;
    --critical-text: #e66767;
  }
}
:root[data-theme="dark"] {
  color-scheme: dark;
  --page: #0d0d0d;
  --surface: #1a1a19;
  --ink: #f0efec;
  --ink-2: #c3c2b7;
  --muted: #898781;
  --grid: #2c2c2a;
  --axis: #383835;
  --border: rgba(255,255,255,0.10);
  --accent: #3987e5;
  --other: #898781;
  --wash: rgba(57,135,229,0.14);
  --good-text: #0ca30c;
  --critical-text: #e66767;
}
* { box-sizing: border-box; }
body { margin: 0; background: var(--page); color: var(--ink); font: 14px/1.45 system-ui, -apple-system, "Segoe UI", sans-serif; }
main { max-width: 1200px; margin: 0 auto; padding: 24px 16px 48px; }
h1 { font-size: 22px; font-weight: 600; margin: 0 0 4px; }
h2 { font-size: 16px; font-weight: 600; margin: 32px 0 4px; }
.sub { color: var(--ink-2); margin: 0 0 4px; }
.note { color: var(--muted); font-size: 12px; margin: 4px 0 12px; }
.controls { display: flex; flex-wrap: wrap; gap: 12px 20px; align-items: center; margin: 20px 0 4px; }
.controls label { display: flex; gap: 8px; align-items: center; color: var(--ink-2); }
select { font: inherit; color: var(--ink); background: var(--surface); border: 1px solid var(--border); border-radius: 6px; padding: 5px 8px; }
.seg { display: inline-flex; border: 1px solid var(--border); border-radius: 6px; overflow: hidden; }
.seg button { font: inherit; color: var(--ink-2); background: var(--surface); border: 0; padding: 5px 12px; cursor: pointer; }
.seg button + button { border-left: 1px solid var(--border); }
.seg button[aria-pressed="true"] { color: var(--ink); background: var(--wash); font-weight: 600; }
.card { background: var(--surface); border: 1px solid var(--border); border-radius: 8px; }
.scroll { overflow-x: auto; }
table { border-collapse: collapse; width: 100%; font-variant-numeric: tabular-nums; }
th, td { text-align: left; padding: 8px 12px; border-bottom: 1px solid var(--grid); vertical-align: top; white-space: nowrap; }
th { color: var(--ink-2); font-weight: 600; font-size: 12px; }
th small { display: block; font-weight: 400; color: var(--muted); }
tr:last-child td { border-bottom: 0; }
td.page { white-space: normal; word-break: break-all; min-width: 160px; max-width: 320px; }
.v { font-weight: 600; }
.off { display: block; color: var(--muted); font-size: 12px; font-weight: 400; }
.st { display: inline-block; width: 10px; height: 10px; margin-right: 6px; vertical-align: 0; }
.st.good { background: var(--good); border-radius: 50%; }
.st.warning { width: 0; height: 0; border-left: 6px solid transparent; border-right: 6px solid transparent; border-bottom: 10px solid var(--warning); }
.st.critical { background: var(--critical); border-radius: 1px; }
.legend { display: flex; flex-wrap: wrap; gap: 6px 18px; color: var(--ink-2); font-size: 12px; margin: 6px 0 12px; }
.legend span { display: inline-flex; align-items: center; gap: 6px; }
.key { display: inline-block; width: 16px; height: 2px; border-radius: 1px; }
.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px; }
.chart { padding: 12px 12px 8px; }
.chart h3 { font-size: 13px; font-weight: 600; margin: 0 0 6px; word-break: break-all; }
.chart svg { display: block; width: 100%; height: 170px; overflow: visible; }
.chart text { fill: var(--muted); font-size: 11px; font-variant-numeric: tabular-nums; }
.hit { fill: transparent; cursor: default; }
.hit:focus { outline: none; }
.hit:focus-visible { stroke: var(--accent); stroke-width: 1; }
#tip { position: fixed; z-index: 10; pointer-events: none; background: var(--surface); color: var(--ink); border: 1px solid var(--border); border-radius: 6px; padding: 8px 10px; font-size: 12px; box-shadow: 0 4px 16px rgba(0,0,0,0.12); max-width: 280px; display: none; }
#tip .t { color: var(--ink-2); margin-bottom: 4px; }
#tip .row { display: flex; align-items: center; gap: 8px; }
#tip .row b { font-size: 13px; }
.empty { color: var(--muted); padding: 16px; }
.dline { display: block; font-size: 12px; }
.delta.better { color: var(--good-text); }
.delta.worse { color: var(--critical-text); }
tr.total td { border-top: 2px solid var(--axis); font-weight: 600; }
</style>
</head>
<body>
<main>
  <h1>Замеры скорости</h1>
  <p class="sub">vspace.pagespeed — PageSpeed Insights, лаборатория (Lighthouse на серверах Google), медиана прогонов серии.</p>
  <p class="note" id="meta"></p>

  <div class="controls">
    <div class="seg" role="group" aria-label="Устройство">
      <button type="button" data-device="mobile" aria-pressed="true">Телефон</button>
      <button type="button" data-device="desktop" aria-pressed="false">Компьютер</button>
    </div>
  </div>

  <h2>Сравнение</h2>
  <p class="note">Для каждого сайта — последний замер каждого вида: модуль, Ammina (метка серии содержит «ammina»),
    без оптимизации (<code>?vps=off</code>); все запуски — в «Истории сайта» ниже. Страницы сопоставляются по адресу
    без домена — можно сравнить разные сайты (demis.ru с Ammina и scilib.ru с модулем) или один сайт до и после
    переноса. Разные серверы отличаются и сами по себе — смотрите и на TTFB (ответ сервера).</p>
  <div class="controls">
    <label>Было <select id="cmp-a"></select></label>
    <label>Стало <select id="cmp-b"></select></label>
  </div>
  <div class="card scroll"><table id="compare"></table></div>

  <h2>История сайта</h2>
  <div class="controls">
    <label>Сайт <select id="host"></select></label>
    <label>Показатель на графиках <select id="metric"></select></label>
  </div>

  <h2 id="last-title">Последний замер</h2>
  <p class="note">Жирным — с модулем, серым — та же страница без модуля (<code>?vps=off</code>) в том же запуске. Пороги — как у Lighthouse:
    <span class="st good"></span>хорошо, <span class="st warning"></span>требует улучшения, <span class="st critical"></span>плохо.</p>
  <div class="card scroll"><table id="last"></table></div>

  <h2 id="trend-title">Динамика</h2>
  <div class="legend">
    <span><i class="key" style="background:var(--accent)"></i>с модулем</span>
    <span><i class="key" style="background:var(--other)"></i>без модуля (?vps=off)</span>
  </div>
  <div class="grid" id="charts"></div>

  <h2 id="history-title">По датам</h2>
  <p class="note">Столбцы — запуски psi.php (дата и метка серии), слева направо от старых к новым.</p>
  <div class="card scroll"><table id="history"></table></div>
</main>
<div id="tip" role="tooltip"></div>
<script type="application/json" id="psi-data">/*DATA*/</script>
<script>
(function () {
  var DATA = JSON.parse(document.getElementById('psi-data').textContent);
  var sec = function (v) { return (v / 1000).toFixed(1) + ' с'; };
  var METRICS = {
    score: { name: 'Оценка', fmt: function (v) { return String(Math.round(v)); }, up: true, th: [90, 50], fixedMax: 100 },
    lcp: { name: 'LCP', fmt: sec, th: [2500, 4000] },
    cls: { name: 'CLS', fmt: function (v) { return v.toFixed(3); }, th: [0.1, 0.25] },
    tbt: { name: 'TBT', fmt: function (v) { return Math.round(v) + ' мс'; }, th: [200, 600] },
    fcp: { name: 'FCP', fmt: sec, th: [1800, 3000] },
    ttfb: { name: 'TTFB', fmt: function (v) { return Math.round(v) + ' мс'; }, th: [800, 1800] }
  };
  // разница меньше — «без изменений»
  var SAME = { score: 1, lcp: 100, cls: 0.005, tbt: 20, fcp: 100, ttfb: 20 };
  var COMPARE_METRICS = ['score', 'lcp', 'cls', 'tbt', 'fcp', 'ttfb'];
  var TABLE_METRICS = ['score', 'lcp', 'cls', 'tbt', 'fcp'];
  var state = { host: null, device: 'mobile', metric: 'score' };

  function status(key, v) {
    var m = METRICS[key];
    if (v === null || v === undefined) return null;
    if (m.up) return v >= m.th[0] ? 'good' : v >= m.th[1] ? 'warning' : 'critical';
    return v <= m.th[0] ? 'good' : v <= m.th[1] ? 'warning' : 'critical';
  }
  var STATUS_NAME = { good: 'хорошо', warning: 'требует улучшения', critical: 'плохо' };
  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text !== undefined && text !== null) e.textContent = text;
    return e;
  }
  function svgEl(tag, attrs) {
    var e = document.createElementNS('http://www.w3.org/2000/svg', tag);
    for (var k in attrs) e.setAttribute(k, attrs[k]);
    return e;
  }
  function seriesTitle(s) {
    var d = s.date.split(' '), ymd = d[0].split('-');
    return ymd[2] + '.' + ymd[1] + '.' + ymd[0] + ' ' + (d[1] || '');
  }
  function shortDate(s) {
    var ymd = s.date.split(' ')[0].split('-');
    return ymd[2] + '.' + ymd[1];
  }

  // сайты: сначала тот, где последний замер свежее
  var hosts = {};
  DATA.points.forEach(function (p) { hosts[p.host] = Math.max(hosts[p.host] === undefined ? -1 : hosts[p.host], p.s); });
  var hostList = Object.keys(hosts).sort(function (a, b) { return hosts[b] - hosts[a]; });
  var hostSel = document.getElementById('host');
  hostList.forEach(function (h) { var o = el('option', null, h); o.value = h; hostSel.appendChild(o); });
  state.host = hostList[0] || null;
  var metricSel = document.getElementById('metric');
  ['score', 'lcp', 'cls', 'tbt', 'fcp'].forEach(function (k) { var o = el('option', null, METRICS[k].name); o.value = k; metricSel.appendChild(o); });

  document.getElementById('meta').textContent = 'Обновлено ' + DATA.generated + ' · данные: tools/' + DATA.source
    + ' · запусков: ' + DATA.series.length + ' · график и таблицы строятся заново после каждого замера psi.php';

  function current() {
    return DATA.points.filter(function (p) { return p.host === state.host && p.device === state.device; });
  }
  function pagesOf(points) {
    var seen = {}, list = [];
    points.forEach(function (p) { if (!seen[p.path]) { seen[p.path] = 1; list.push(p.path); } });
    return list.sort(function (a, b) { return a.length - b.length || a.localeCompare(b); });
  }
  function cell(td, key, on, off) {
    var v = on ? on[key] : null;
    if (v === null || v === undefined) {
      td.appendChild(el('span', 'off', '—'));
    } else {
      var st = status(key, v);
      var s = el('span', 'st ' + st);
      s.setAttribute('title', STATUS_NAME[st]);
      td.appendChild(s);
      td.appendChild(el('span', 'v', METRICS[key].fmt(v)));
    }
    var o = off ? off[key] : null;
    if (o !== null && o !== undefined) td.appendChild(el('span', 'off', 'без: ' + METRICS[key].fmt(o)));
  }

  function renderLast(points) {
    var table = document.getElementById('last');
    table.textContent = '';
    if (!points.length) { table.appendChild(el('caption', 'empty', 'Нет замеров для этого сайта и устройства.')); return; }
    var lastS = Math.max.apply(null, points.map(function (p) { return p.s; }));
    var s = DATA.series[lastS];
    document.getElementById('last-title').textContent = 'Последний замер — ' + seriesTitle(s) + ' · ' + s.label;
    var rows = points.filter(function (p) { return p.s === lastS; });
    var hasField = rows.some(function (p) { return p.on && (p.on.f_lcp !== null && p.on.f_lcp !== undefined); });
    var head = el('tr');
    head.appendChild(el('th', null, 'Страница'));
    TABLE_METRICS.forEach(function (k) { head.appendChild(el('th', null, METRICS[k].name)); });
    if (hasField) { var th = el('th', null, 'Поле, 28 дней'); th.appendChild(el('small', null, 'LCP · CLS · INP (p75)')); head.appendChild(th); }
    table.appendChild(head);
    pagesOf(rows).forEach(function (path) {
      var p = rows.filter(function (x) { return x.path === path; })[0];
      var tr = el('tr');
      tr.appendChild(el('td', 'page', path));
      TABLE_METRICS.forEach(function (k) { var td = el('td'); cell(td, k, p.on, p.off); tr.appendChild(td); });
      if (hasField) {
        var f = p.on || {};
        var txt = f.f_lcp === null || f.f_lcp === undefined ? 'нет данных'
          : sec(f.f_lcp) + ' · ' + (f.f_cls === null ? '—' : Number(f.f_cls).toFixed(2)) + ' · ' + (f.f_inp === null ? '—' : Math.round(f.f_inp) + ' мс')
            + (f.f_scope === 'origin' ? ' (весь сайт)' : '');
        tr.appendChild(el('td', null, txt));
      }
      table.appendChild(tr);
    });
  }

  function niceMax(v) {
    if (v <= 0) return 1;
    var p = Math.pow(10, Math.floor(Math.log(v) / Math.LN10)), n = v / p;
    return (n <= 1 ? 1 : n <= 2 ? 2 : n <= 2.5 ? 2.5 : n <= 5 ? 5 : 10) * p;
  }

  var tip = document.getElementById('tip');
  function showTip(evt, title, lines) {
    tip.textContent = '';
    tip.appendChild(el('div', 't', title));
    lines.forEach(function (l) {
      var row = el('div', 'row');
      var key = el('i', 'key'); key.style.background = l.color;
      row.appendChild(key);
      row.appendChild(el('b', null, l.value));
      row.appendChild(el('span', null, l.name));
      tip.appendChild(row);
    });
    tip.style.display = 'block';
    var r = evt.target.getBoundingClientRect ? evt.target.getBoundingClientRect() : null;
    var x = evt.clientX || (r ? r.left + r.width / 2 : 0), y = evt.clientY || (r ? r.top : 0);
    var w = tip.offsetWidth, h = tip.offsetHeight;
    tip.style.left = Math.min(window.innerWidth - w - 8, Math.max(8, x + 12)) + 'px';
    tip.style.top = Math.max(8, y - h - 12) + 'px';
  }
  function hideTip() { tip.style.display = 'none'; }

  function renderCharts(points) {
    var box = document.getElementById('charts');
    box.textContent = '';
    var m = METRICS[state.metric];
    document.getElementById('trend-title').textContent = 'Динамика: ' + m.name + (m.up ? ' (выше — лучше)' : ' (ниже — лучше)');
    if (!points.length) { box.appendChild(el('div', 'empty', 'Нет замеров.')); return; }
    // общая ось X: все запуски с замерами этого сайта и устройства
    var xs = [];
    points.forEach(function (p) { if (xs.indexOf(p.s) < 0) xs.push(p.s); });
    xs.sort(function (a, b) { return a - b; });
    var accent = getComputedStyle(document.documentElement).getPropertyValue('--accent').trim();
    var other = getComputedStyle(document.documentElement).getPropertyValue('--other').trim();
    pagesOf(points).forEach(function (path) {
      var mine = points.filter(function (p) { return p.path === path; });
      var vals = [];
      mine.forEach(function (p) { ['on', 'off'].forEach(function (v) { if (p[v] && p[v][state.metric] !== null) vals.push(p[v][state.metric]); }); });
      var max = m.fixedMax || niceMax(Math.max.apply(null, vals.concat([0])) * 1.05);
      var W = 300, H = 170, L = 40, R = 12, T = 10, B = 22;
      var x = function (s) { var i = xs.indexOf(s); return xs.length === 1 ? (L + W - R) / 2 : L + i * (W - L - R) / (xs.length - 1); };
      var y = function (v) { return T + (H - T - B) * (1 - v / max); };
      var card = el('div', 'card chart');
      card.appendChild(el('h3', null, path));
      var svg = svgEl('svg', { viewBox: '0 0 ' + W + ' ' + H, role: 'img', 'aria-label': path + ': ' + m.name + ' по запускам' });
      [0, 0.5, 1].forEach(function (f) {
        var v = max * f, yy = y(v);
        svg.appendChild(svgEl('line', { x1: L, x2: W - R, y1: yy, y2: yy, stroke: f === 0 ? 'var(--axis)' : 'var(--grid)', 'stroke-width': 1 }));
        var t = svgEl('text', { x: L - 6, y: yy + 4, 'text-anchor': 'end' });
        t.textContent = state.metric === 'score' ? String(Math.round(v)) : m.fmt(v);
        svg.appendChild(t);
      });
      var labelIdx = xs.length === 1 ? [0] : [0, xs.length - 1];
      // все запуски в один день — подписи временем, иначе датой
      var sameDay = shortDate(DATA.series[xs[0]]) === shortDate(DATA.series[xs[xs.length - 1]]);
      labelIdx.forEach(function (i) {
        var t = svgEl('text', { x: x(xs[i]), y: H - 4, 'text-anchor': xs.length === 1 ? 'middle' : (i === 0 ? 'start' : 'end') });
        var ser = DATA.series[xs[i]];
        t.textContent = sameDay && xs.length > 1 ? (ser.date.split(' ')[1] || '') : shortDate(ser);
        svg.appendChild(t);
      });
      // сначала «без модуля» (фон), затем «с модулем» поверх
      [['off', other], ['on', accent]].forEach(function (pair) {
        var pts = mine.filter(function (p) { return p[pair[0]] && p[pair[0]][state.metric] !== null; })
          .sort(function (a, b) { return a.s - b.s; });
        if (pts.length > 1) {
          var d = pts.map(function (p, i) { return (i ? 'L' : 'M') + x(p.s).toFixed(1) + ' ' + y(p[pair[0]][state.metric]).toFixed(1); }).join(' ');
          svg.appendChild(svgEl('path', { d: d, fill: 'none', stroke: pair[1], 'stroke-width': 2, 'stroke-linejoin': 'round', 'stroke-linecap': 'round' }));
        }
        pts.forEach(function (p) {
          svg.appendChild(svgEl('circle', { cx: x(p.s), cy: y(p[pair[0]][state.metric]), r: 4, fill: pair[1], stroke: 'var(--surface)', 'stroke-width': 2 }));
        });
      });
      // зона наведения на каждый запуск: подсказка с обоими значениями
      xs.forEach(function (s) {
        var p = mine.filter(function (q) { return q.s === s; })[0];
        if (!p) return;
        var hit = svgEl('rect', { x: x(s) - 14, y: T, width: 28, height: H - T - B, class: 'hit', tabindex: 0 });
        var ser = DATA.series[s];
        var lines = [];
        if (p.on && p.on[state.metric] !== null) lines.push({ color: accent, value: m.fmt(p.on[state.metric]), name: 'с модулем' });
        if (p.off && p.off[state.metric] !== null) lines.push({ color: other, value: m.fmt(p.off[state.metric]), name: 'без модуля' });
        var title = seriesTitle(ser) + ' · ' + ser.label;
        hit.addEventListener('pointermove', function (e) { showTip(e, title, lines); });
        hit.addEventListener('pointerleave', hideTip);
        hit.addEventListener('focus', function (e) { showTip(e, title, lines); });
        hit.addEventListener('blur', hideTip);
        svg.appendChild(hit);
      });
      card.appendChild(svg);
      box.appendChild(card);
    });
  }

  function renderHistory(points) {
    var table = document.getElementById('history');
    table.textContent = '';
    var m = METRICS[state.metric];
    document.getElementById('history-title').textContent = 'По датам: ' + m.name;
    if (!points.length) return;
    var xs = [];
    points.forEach(function (p) { if (xs.indexOf(p.s) < 0) xs.push(p.s); });
    xs.sort(function (a, b) { return a - b; });
    var head = el('tr');
    head.appendChild(el('th', null, 'Страница'));
    xs.forEach(function (s) {
      var th = el('th', null, seriesTitle(DATA.series[s]));
      th.appendChild(el('small', null, DATA.series[s].label));
      head.appendChild(th);
    });
    table.appendChild(head);
    pagesOf(points).forEach(function (path) {
      var tr = el('tr');
      tr.appendChild(el('td', 'page', path));
      xs.forEach(function (s) {
        var p = points.filter(function (q) { return q.s === s && q.path === path; })[0];
        var td = el('td');
        if (p) cell(td, state.metric, p.on, p.off); else td.appendChild(el('span', 'off', '—'));
        tr.appendChild(td);
      });
      table.appendChild(tr);
    });
  }

  // --- Сравнение двух замеров: вариант = запуск + сайт + с модулем / без.
  // В списках — только последний замер каждого вида на сайте: модуль, Ammina (метка содержит «ammina»),
  // без оптимизации (?vps=off). Все запуски — в «Истории сайта».
  var KIND_NAME = { module: 'модуль', ammina: 'Ammina', off: 'без оптимизации' };
  var all = [], seenVariant = {};
  DATA.points.forEach(function (p) {
    ['on', 'off'].forEach(function (side) {
      var key = p.s + '|' + p.host + '|' + side;
      if (!p[side] || seenVariant[key]) return;
      seenVariant[key] = 1;
      var ser = DATA.series[p.s];
      var kind = side === 'off' ? 'off' : (/ammina/i.test(ser.label) ? 'ammina' : 'module');
      all.push({ key: key, s: p.s, host: p.host, side: side, kind: kind,
        text: p.host + ' · ' + KIND_NAME[kind] + ' · ' + seriesTitle(ser) + ' (' + ser.label + ')' });
    });
  });
  all.sort(function (a, b) { return b.s - a.s || (a.side === 'on' ? -1 : 1); });
  var variants = [], seenKind = {};
  all.forEach(function (v) {
    if (seenKind[v.host + '|' + v.kind]) return;
    seenKind[v.host + '|' + v.kind] = 1;
    variants.push(v);
  });
  variants.sort(function (a, b) { return a.host.localeCompare(b.host) || ['ammina', 'module', 'off'].indexOf(a.kind) - ['ammina', 'module', 'off'].indexOf(b.kind); });
  var cmpA = document.getElementById('cmp-a'), cmpB = document.getElementById('cmp-b');
  variants.forEach(function (v) {
    var o1 = el('option', null, v.text); o1.value = v.key; cmpA.appendChild(o1);
    var o2 = el('option', null, v.text); o2.value = v.key; cmpB.appendChild(o2);
  });
  function store(key, value) { try { if (value === undefined) return localStorage.getItem(key); localStorage.setItem(key, value); } catch (e) { return null; } }
  function has(key) { return variants.some(function (v) { return v.key === key; }); }
  (function defaults() {
    // «Было» — последний замер с меткой ammina (база), иначе другой сайт / тот же запуск без модуля; «Стало» — последний с модулем
    var a = store('psi-cmp-a'), b = store('psi-cmp-b');
    var newest = variants.slice().sort(function (x, y) { return y.s - x.s; });
    if (!has(b)) {
      var nb = newest.filter(function (v) { return v.kind === 'module'; })[0] || newest[0];
      b = nb ? nb.key : null;
    }
    if (!has(a)) {
      var vb = newest.filter(function (v) { return v.key === b; })[0];
      var na = newest.filter(function (v) { return v.kind === 'ammina'; })[0]
        || (vb && newest.filter(function (v) { return v.host === vb.host && v.kind === 'off'; })[0])
        || newest.filter(function (v) { return v.key !== b; })[0];
      a = na ? na.key : null;
    }
    if (a) cmpA.value = a;
    if (b) cmpB.value = b;
  })();

  function variantPoints(key) {
    var v = variants.filter(function (x) { return x.key === key; })[0];
    var map = {};
    if (!v) return map;
    DATA.points.forEach(function (p) {
      if (p.s === v.s && p.host === v.host && p.device === state.device && p[v.side]) map[p.path] = p[v.side];
    });
    return map;
  }
  function deltaEl(key, a, b) {
    var m = METRICS[key], d = b - a;
    if (Math.abs(d) < SAME[key]) return el('span', 'off', '≈ без изменений');
    var better = m.up ? d > 0 : d < 0;
    var txt = key === 'score' ? (d > 0 ? '+' : '−') + Math.abs(Math.round(d))
      : key === 'cls' ? (d > 0 ? '+' : '−') + Math.abs(d).toFixed(3)
      : (key === 'lcp' || key === 'fcp') ? (d > 0 ? '+' : '−') + (Math.abs(d) / 1000).toFixed(1) + ' с'
      : (d > 0 ? '+' : '−') + Math.abs(Math.round(d)) + ' мс';
    return el('span', 'delta ' + (better ? 'better' : 'worse'), txt + (better ? ' · лучше' : ' · хуже'));
  }
  function renderCompare() {
    var table = document.getElementById('compare');
    table.textContent = '';
    store('psi-cmp-a', cmpA.value);
    store('psi-cmp-b', cmpB.value);
    var A = variantPoints(cmpA.value), B = variantPoints(cmpB.value);
    var paths = Object.keys(B).filter(function (p) { return A[p]; })
      .sort(function (a, b) { return a.length - b.length || a.localeCompare(b); });
    if (!paths.length) {
      table.appendChild(el('caption', 'empty', 'Нет общих страниц у выбранных замеров для этого устройства.'));
      return;
    }
    var head = el('tr');
    head.appendChild(el('th', null, 'Страница'));
    COMPARE_METRICS.forEach(function (k) { head.appendChild(el('th', null, METRICS[k].name)); });
    table.appendChild(head);
    function row(title, a, b, cls) {
      var tr = el('tr', cls);
      tr.appendChild(el('td', 'page', title));
      COMPARE_METRICS.forEach(function (k) {
        var td = el('td');
        var vb = b[k], va = a[k];
        if (vb === null || vb === undefined) { td.appendChild(el('span', 'off', '—')); tr.appendChild(td); return; }
        var st = status(k, vb);
        var dot = el('span', 'st ' + st); dot.setAttribute('title', STATUS_NAME[st]);
        td.appendChild(dot);
        td.appendChild(el('span', 'v', METRICS[k].fmt(vb)));
        if (va !== null && va !== undefined) {
          td.appendChild(el('span', 'off', 'было: ' + METRICS[k].fmt(va)));
          var dl = el('span', 'dline'); dl.appendChild(deltaEl(k, va, vb)); td.appendChild(dl);
        }
        tr.appendChild(td);
      });
      table.appendChild(tr);
    }
    paths.forEach(function (p) { row(p, A[p], B[p]); });
    if (paths.length > 1) {
      var mean = function (src) {
        var r = {};
        COMPARE_METRICS.forEach(function (k) {
          var vals = paths.map(function (p) { return src[p][k]; }).filter(function (v) { return v !== null && v !== undefined; });
          r[k] = vals.length ? vals.reduce(function (s, v) { return s + v; }, 0) / vals.length : null;
        });
        return r;
      };
      row('В среднем по ' + paths.length + ' стр.', mean(A), mean(B), 'total');
    }
  }
  cmpA.addEventListener('change', renderCompare);
  cmpB.addEventListener('change', renderCompare);

  function render() {
    var points = current();
    renderCompare();
    renderLast(points);
    renderCharts(points);
    renderHistory(points);
  }
  hostSel.addEventListener('change', function () { state.host = hostSel.value; render(); });
  metricSel.addEventListener('change', function () { state.metric = metricSel.value; render(); });
  Array.prototype.forEach.call(document.querySelectorAll('.seg button'), function (b) {
    b.addEventListener('click', function () {
      state.device = b.getAttribute('data-device');
      Array.prototype.forEach.call(document.querySelectorAll('.seg button'), function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
      render();
    });
  });
  if (window.matchMedia) {
    var mq = window.matchMedia('(prefers-color-scheme: dark)');
    if (mq.addEventListener) mq.addEventListener('change', render);
  }
  render();
})();
</script>
</body>
</html>
HTML;
