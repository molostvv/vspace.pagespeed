<?php

namespace Vspace\PageSpeed;

use Vspace\PageSpeed\Critical\CriticalCssBuilder;
use Vspace\PageSpeed\Critical\CriticalCssProcessor;
use Vspace\PageSpeed\Css\CriticalExtractor;
use Vspace\PageSpeed\Css\StylesheetIndexer;
use Vspace\PageSpeed\Html\Document;
use Vspace\PageSpeed\Html\Edits;
use Vspace\PageSpeed\Images\ImagesProcessor;
use Vspace\PageSpeed\Images\WebpRewriter;
use Vspace\PageSpeed\Images\WebpStore;
use Vspace\PageSpeed\Js\DeferJsProcessor;
use Vspace\PageSpeed\Processor\ProcessorInterface;
use Vspace\PageSpeed\Storage\FileStorage;

/**
 * Конвейер оптимизации страницы: разбирает HTML один раз (Document), прогоняет процессоры и собирает
 * результат из их правок. Ошибка процессора отменяет только его правки и попадает в $ctx->errors —
 * страница при этом отдаётся всегда.
 */
final class Optimizer
{
    public const CACHE_DIR = '/bitrix/cache/vspace.pagespeed';
    /**
     * Файлы, которые отдаются браузеру (вынесенные встроенные стили). Не в CACHE_DIR: nginx BitrixVM закрывает
     * /bitrix/cache целиком, кроме /bitrix/cache/css/*.css и /bitrix/cache/js/*.js (объединённые CSS/JS ядра).
     */
    public const PUBLIC_DIR = '/bitrix/cache/css/vspace.pagespeed';

    /** @param list<ProcessorInterface> $processors */
    public function __construct(private readonly array $processors)
    {
    }

    /**
     * Конвейер по настройкам: critical CSS, картинки (lazyload и WebP), отложенный JS.
     * Для HTML-фрагмента (ответ AJAX) — только WebP: ни head, ни первого экрана у фрагмента нет.
     * WebP — только если копии могут появиться: GD с WebP есть у PHP агента из cron (WebpStore::canConvert),
     * иначе очередь копилась бы зря. Сам PHP сайта WebP уметь не обязан: он только подменяет адреса на готовые
     * копии и ставит картинки в очередь.
     */
    public static function create(Config $config, string $documentRoot, bool $fragment = false): self
    {
        $store = new WebpStore($documentRoot);
        $webp = $config->webpActive && $store->canConvert();
        if ($fragment) {
            return new self($webp ? [new ImagesProcessor($config, new WebpRewriter($store, $config->webpExcludeFiles), false)] : []);
        }
        $processors = [];
        if ($config->criticalActive) {
            $storage = self::storage($documentRoot);
            $builder = new CriticalCssBuilder(
                $storage,
                new StylesheetIndexer($documentRoot),
                new CriticalExtractor($config->dropInteraction, $config->fontFace, $config->fontDisplaySwap),
                $documentRoot,
                $config->criticalHash(),
                $config->criticalMaxSize,
            );
            $processors[] = new CriticalCssProcessor($config, $builder, self::publicStorage($documentRoot));
        }
        if ($config->imagesActive || $webp) {
            $processors[] = new ImagesProcessor($config, $webp ? new WebpRewriter($store, $config->webpExcludeFiles) : null);
        }
        if ($config->jsActive) {
            $processors[] = new DeferJsProcessor($config);
        }
        return new self($processors);
    }

    public static function storage(string $documentRoot): FileStorage
    {
        return new FileStorage($documentRoot . self::CACHE_DIR, self::CACHE_DIR);
    }

    public static function publicStorage(string $documentRoot): FileStorage
    {
        return new FileStorage($documentRoot . self::PUBLIC_DIR, self::PUBLIC_DIR);
    }

    public function optimize(string $html, Context $ctx): string
    {
        if (!$this->processors) {
            return $html;
        }
        $started = hrtime(true);
        $doc = $ctx->fragment ? Document::fromFragment($html) : Document::fromHtml($html);
        if ($doc === null) {
            $ctx->stats['skip'] = 'not-html';
            return $html;
        }
        $edits = new Edits();
        foreach ($this->processors as $processor) {
            $own = new Edits();
            try {
                $processor->process($doc, $own, $ctx);
                $edits->merge($own);
            } catch (\Throwable $e) {
                $ctx->errors[] = $processor->name() . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
                $ctx->exceptions[] = $e;
            }
        }
        $result = $edits->apply($html);
        $ctx->stats['ms'] = round((hrtime(true) - $started) / 1e6, 1);
        return $result;
    }
}
