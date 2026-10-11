<?php

namespace Vspace\PageSpeed;

use Vspace\PageSpeed\Css\StylesheetIndexer;
use Vspace\PageSpeed\Html\AtomCollector;
use Vspace\PageSpeed\Html\AtomSet;
use Vspace\PageSpeed\Html\Document;
use Vspace\PageSpeed\Html\FoldDetector;

/**
 * Данные одного запроса для процессоров: настройки, адрес страницы, режим предпросмотра и общий для всех
 * процессоров результат анализа первого экрана (его же позже использует lazyload). Сюда же процессоры
 * пишут статистику для отладочного вывода.
 */
final class Context
{
    /** @var array<string, scalar> */
    public array $stats = [];
    /** @var list<string> ошибки процессоров — для отладочного вывода */
    public array $errors = [];
    /** @var list<\Throwable> они же — для записи в лог */
    public array $exceptions = [];

    private ?array $fold = null;
    private ?AtomSet $foldAtoms = null;

    /**
     * @param string $pagePath путь текущей страницы (для относительных адресов): /services/seo/
     * @param list<string> $localHosts домены, ссылки на которые считаются локальными файлами (в нижнем регистре)
     * @param bool $previewCritical режим проверки: страница только с critical CSS, без полного CSS
     * @param bool $webp браузер умеет WebP (Images\WebpSupport::browser) — картинки подменяются на WebP-копии
     * @param bool $fragment HTML-фрагмент из ответа AJAX: относительные адреса в нём считаются от страницы, куда
     *                       его вставят, а не от $pagePath, — их не трогаем
     */
    public function __construct(
        public readonly Config $config,
        public readonly string $documentRoot,
        public readonly string $pagePath = '/',
        public readonly array $localHosts = [],
        public readonly bool $previewCritical = false,
        public readonly bool $webp = false,
        public readonly bool $fragment = false,
    ) {
    }

    public function pageDir(): string
    {
        return StylesheetIndexer::dirOf($this->pagePath);
    }

    /** @return array{start:int, boundary:int, reason:string} */
    public function fold(Document $doc): array
    {
        if ($this->fold === null) {
            $detector = new FoldDetector(
                $this->config->foldStartClasses,
                $this->config->foldBlockClasses,
                $this->config->foldBlocks,
                $this->config->foldMaxBytes,
            );
            $this->fold = $detector->detect($doc);
            $this->stats['fold'] = $this->fold['reason'] . '@' . ($this->fold['boundary'] - $doc->bodyStart);
        }
        return $this->fold;
    }

    /**
     * Атомы первого экрана + обязательные из настроек: элементы с классами ADD_CLASSES учитываются вместе с
     * содержимым, где бы ни стояли (cookie-баннер в футере), а классы, которых нет в разметке (их добавляет
     * скрипт), — сами по себе.
     */
    public function foldAtoms(Document $doc): AtomSet
    {
        if ($this->foldAtoms === null) {
            $fold = $this->fold($doc);
            $collector = new AtomCollector($this->config->foldSkipClasses);
            $atoms = $collector->collect($doc, $fold['start'], $fold['boundary']);
            if ($this->config->addClasses) {
                $collector->collectElements($doc, $this->config->addClasses, $atoms);
            }
            foreach ($this->config->addClasses as $class) {
                $atoms->addClass($class);
            }
            foreach ($this->config->addIds as $id) {
                $atoms->addId($id);
            }
            $this->foldAtoms = $atoms;
        }
        return $this->foldAtoms;
    }
}
