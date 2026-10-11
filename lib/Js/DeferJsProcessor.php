<?php

namespace Vspace\PageSpeed\Js;

use Vspace\PageSpeed\Config;
use Vspace\PageSpeed\Context;
use Vspace\PageSpeed\Html\Document;
use Vspace\PageSpeed\Html\Edits;
use Vspace\PageSpeed\Html\Tag;
use Vspace\PageSpeed\PathMatcher;
use Vspace\PageSpeed\Processor\ProcessorInterface;

/**
 * Отложенный JS: скрипты страницы выполняются после window.onload — как было у Ammina (её `type="deferjs"`).
 *
 * Зачем: полный CSS подключается асинхронно (critical CSS), а скрипты, которые при запуске измеряют вёрстку
 * (слайдеры, липкая шапка, waypoints), должны видеть уже полный CSS. После onload он применён наверняка (его
 * загрузчик срабатывает раньше, по тому же событию). Заодно ~2 МБ JS не занимают процессор и сеть, пока
 * грузится первый экран.
 *
 * Как: у скриптов тип меняется на text/vps-defer — браузер их не скачивает и не выполняет; загрузчик перед
 * </body> после onload и первой отрисовки (FCP) выполняет их по порядку на прежних местах. FCP ждём явно:
 * страница с critical CSS грузится так быстро, что onload наступает раньше первой отрисовки, и скрипты, начатые
 * сразу, отодвигали FCP/LCP на время своего выполнения (PSI: LCP 5–7 с вместо 2 с в части прогонов). Ожидания
 * кадра (requestAnimationFrame) мало: в начале загрузки браузер может не рисовать кадры дольше полсекунды.
 * Поэтому — событие first-contentful-paint (Paint Timing); без него — кадр; в скрытой вкладке и пока critical
 * CSS держит страницу скрытой (window.vpsHold: открыта не сверху) — сразу; страховка — 3 с. По окончании —
 * window.vpsJsDone и сигнал CSS-загрузчику показать страницу. Подряд идущие внешние скрипты вставляются
 * разом с async=false — качаются параллельно, выполняются по порядку (Ammina качала по одному); встроенный
 * скрипт ждёт предыдущие внешние. Внешние с async — в конце, без порядка.
 *
 * Скрипты, запущенные после onload, сами событий DOMContentLoaded и load уже не получат, а шаблон redesign
 * (и waypoints в main.min.js) вешают инициализацию на них. Поэтому на время запуска загрузчик запоминает
 * обработчики этих событий и window.onload, которые регистрируют отложенные скрипты, и вызывает их после
 * всех скриптов — в том же порядке, что браузер. Обработчики, повешенные раньше (неотложенными скриптами),
 * второй раз не вызываются. document.write на это время вставляет разметку после своего скрипта, а не
 * стирает страницу.
 *
 * Не трогаются: data-skip-moving (соглашение Битрикса «не переносить»; на сайте это объявления данных и
 * загрузчики модуля), data-vps-skip, файлы из JS_EXCLUDE_FILES, не-JS (JSON-LD, шаблоны, type="module",
 * nomodule) и уже отложенные Ammina (type="deferjs").
 */
final class DeferJsProcessor implements ProcessorInterface
{
    public const TYPE = 'text/vps-defer';
    private const JS_TYPES = ['', 'text/javascript', 'application/javascript', 'application/x-javascript', 'text/ecmascript', 'application/ecmascript', 'text/jscript'];

    private const LOADER = '(function(w,d){var T="' . self::TYPE . '";w.vpsJs=1;'
        . 'function ev(t){try{return new Event(t)}catch(x){var e=d.createEvent("Event");e.initEvent(t,false,false);return e}}'
        . 'function run(){var all=d.querySelectorAll(\'script[type="\'+T+\'"]\'),q=[],late=[],dcl=[],ld=[],onl=w.onload,n=0,i;'
        . 'for(i=0;i<all.length;i++)(all[i].hasAttribute("src")&&all[i].hasAttribute("async")?late:q).push(all[i]);'
        // обработчики DOMContentLoaded/load, которые повесят отложенные скрипты
        . 'function hook(el){var o=el.addEventListener;el.addEventListener=function(t,f){if(f&&(t==="DOMContentLoaded"||(el===w&&t==="load")))(t==="load"?ld:dcl).push([el,f]);return o.apply(el,arguments)}}'
        . 'hook(d);hook(w);'
        . 'd.write=d.writeln=function(h){var s=d.currentScript;if(s&&s.parentNode)s.insertAdjacentHTML("afterend",h);else d.body.insertAdjacentHTML("beforeend",h)};'
        . 'function put(o){var s=d.createElement("script"),a=o.attributes,j;for(j=0;j<a.length;j++)if(a[j].name!=="type")s.setAttribute(a[j].name,a[j].value);'
        . 'if(o.hasAttribute("src"))s.async=o.hasAttribute("async");else s.text=o.text;'
        . 'if(o.parentNode)o.parentNode.replaceChild(s,o);else d.body.appendChild(s);return s}'
        . 'function call(f,el,e){try{typeof f==="function"?f.call(el,e):f.handleEvent(e)}catch(x){setTimeout(function(){throw x})}}'
        . 'function fire(l,t){var e=ev(t),k;for(k=0;k<l.length;k++)call(l[k][1],l[k][0],e)}'
        // подряд идущие внешние — разом (async=false: параллельно, по порядку), встроенный ждёт их
        . 'function next(){var last=null,o;while(n<q.length){o=q[n];if(o.hasAttribute("src")){last=put(o);n++;continue}if(last)break;put(o);n++}'
        . 'if(last){last.addEventListener("load",next);last.addEventListener("error",next)}else done()}'
        . 'function done(){delete d.addEventListener;delete w.addEventListener;delete d.write;delete d.writeln;'
        . 'fire(dcl,"DOMContentLoaded");fire(ld,"load");if(w.onload!==onl&&typeof w.onload==="function")call(w.onload,w,ev("load"));'
        . 'for(i=0;i<late.length;i++)put(late[i]);w.vpsJsDone=1;if(w.vpsHoldCheck)w.vpsHoldCheck()}'
        . 'next()}'
        // после onload — дождаться первой отрисовки (FCP), потом выполнять
        . 'function go(){if(!go.s){go.s=1;run()}}'
        . 'function start(){var P=w.PerformanceObserver,p=w.performance,F="first-contentful-paint",o;'
        . 'if(d.hidden||w.vpsHold||(p&&p.getEntriesByName&&p.getEntriesByName(F).length)){setTimeout(go,1);return}'
        . 'setTimeout(go,3000);'
        . 'if(P&&(P.supportedEntryTypes||[]).indexOf("paint")>=0){o=new P(function(l){if(l.getEntriesByName(F).length){o.disconnect();setTimeout(go,1)}});o.observe({type:"paint",buffered:true})}'
        . 'else if(w.requestAnimationFrame)requestAnimationFrame(function(){setTimeout(go,1)});else setTimeout(go,1)}'
        . 'if(d.readyState==="complete")start();else w.addEventListener("load",start)})(window,document);';

    public function __construct(private readonly Config $config)
    {
    }

    public function name(): string
    {
        return 'js';
    }

    public function process(Document $doc, Edits $edits, Context $ctx): void
    {
        $deferred = 0;
        $kept = 0;
        foreach ($doc->tags() as $t) {
            if ($t['name'] !== 'script' || $t['close']) {
                continue;
            }
            $tag = Tag::parse(substr($doc->html, $t['pos'], $t['end'] - $t['pos']));
            if ($tag === null || !in_array(strtolower(trim((string)$tag->get('type'))), self::JS_TYPES, true) || $tag->has('nomodule')) {
                continue;
            }
            if ($tag->has('data-skip-moving') || $tag->has('data-vps-skip') || $this->excluded($tag->get('src'), $ctx)) {
                $kept++;
                continue;
            }
            $edits->replace($t['pos'], $t['end'], $tag->set('type', self::TYPE)->toHtml());
            $deferred++;
        }
        if ($deferred > 0) {
            $edits->insert($doc->bodyEnd, '<script data-skip-moving="true">' . self::LOADER . '</script>');
            $ctx->stats['js_deferred'] = $deferred;
        }
        if ($kept > 0) {
            $ctx->stats['js_kept'] = $kept;
        }
    }

    /** Файл из JS_EXCLUDE_FILES: локальный — по пути от корня, внешний — по адресу целиком. */
    private function excluded(?string $src, Context $ctx): bool
    {
        if ($src === null || $src === '' || !$this->config->jsExcludeFiles) {
            return false;
        }
        $url = substr($src, 0, strcspn($src, '?#'));
        if (preg_match('#^(?:https?:)?//([^/:]+)(?::\d+)?(/.*)?$#i', $url, $m) && in_array(strtolower($m[1]), $ctx->localHosts, true)) {
            $url = $m[2] ?? '/';
        } elseif ($url !== '' && $url[0] !== '/' && !preg_match('#^(?:[a-z][a-z0-9+.-]*:|//)#i', $url)) {
            $url = $ctx->pageDir() . $url;
        }
        return PathMatcher::matchPath($url, $this->config->jsExcludeFiles);
    }
}
