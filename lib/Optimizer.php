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

    /** @param list<ProcessorInterface> $processors */
    public function __construct(private readonly array $processors)
    {
    }

    /**
     * Конвейер по настройкам: critical CSS, картинки (lazyload и WebP), отложенный JS.
     * Для HTML-фрагмента (ответ AJAX) — только WebP: ни head, ни первого экрана у фрагмента нет.
     */
    public static function create(Config $config, string $documentRoot, bool $fragment = false): self
    {
        if ($fragment) {
            return new self($config->webpActive ? [new ImagesProcessor($config, new WebpRewriter(new WebpStore($documentRoot)), false)] : []);
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
            $processors[] = new CriticalCssProcessor($config, $builder, $storage);
        }
        if ($config->imagesActive || $config->webpActive) {
            $processors[] = new ImagesProcessor($config, $config->webpActive ? new WebpRewriter(new WebpStore($documentRoot)) : null);
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
