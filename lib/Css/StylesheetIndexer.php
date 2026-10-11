<?php

namespace Vspace\PageSpeed\Css;

/**
 * Готовит CSS к быстрому извлечению critical: разбирает, переписывает url() от корня сайта, подставляет
 * локальные @import, минифицирует тела правил и заранее раскладывает каждый селектор на требования к
 * разметке. Результат кешируется (CriticalCssBuilder), поэтому тяжёлая работа выполняется один раз на версию файла.
 *
 * Формат индекса:
 *   nodes — дерево как у Parser, но вместо [RULE, селектор, тело] хранится [RULE, тело, альтернативы],
 *           где альтернатива — результат SelectorAnalyzer::analyze();
 *   atoms — все классы/id/теги, упомянутые в селекторах (c/i/t => [имя => true]): по ним из атомов страницы
 *           отбрасываются те, что на CSS не влияют, и ключ кеша critical не дробится по страницам;
 *   deps  — файлы, подставленные через @import: [путь => [mtime, size]] (для проверки актуальности).
 */
final class StylesheetIndexer
{
    private const MAX_IMPORT_DEPTH = 5;

    private Parser $parser;
    private SelectorAnalyzer $analyzer;
    private array $atoms = [];
    private array $deps = [];

    public function __construct(private readonly string $documentRoot)
    {
        $this->parser = new Parser();
        $this->analyzer = new SelectorAnalyzer();
    }

    /** @param string $urlPath путь файла от корня сайта: /local/templates/x/css/main.css */
    public function indexFile(string $urlPath): ?array
    {
        $absPath = $this->documentRoot . $urlPath;
        $css = @file_get_contents($absPath);
        if ($css === false) {
            return null;
        }
        return $this->indexCss($css, self::dirOf($urlPath), [$urlPath => true]);
    }

    /**
     * @param string $baseDir каталог, от которого считать относительные url (со слешем в конце)
     * @param array<string, true> $visited уже подставленные файлы (защита от циклических @import)
     */
    public function indexCss(string $css, string $baseDir, array $visited = []): array
    {
        $this->atoms = ['c' => [], 'i' => [], 't' => []];
        $this->deps = [];
        $nodes = $this->convert($this->parser->parse($css), $baseDir, $visited, 0);
        return ['nodes' => $nodes, 'atoms' => $this->atoms, 'deps' => $this->deps];
    }

    private function convert(array $nodes, string $baseDir, array $visited, int $depth): array
    {
        $result = [];
        foreach ($nodes as $node) {
            switch ($node[0]) {
                case Parser::RULE:
                    $alternatives = $this->analyzer->analyzeList($node[1]);
                    $body = Minifier::body(UrlRebaser::rebase($node[2], $baseDir));
                    if (!$alternatives || $body === '') {
                        break;
                    }
                    foreach ($alternatives as $alt) {
                        foreach ($alt[1] as $class) {
                            $this->atoms['c'][$class] = true;
                        }
                        foreach ($alt[2] as $id) {
                            $this->atoms['i'][$id] = true;
                        }
                        foreach ($alt[3] as $tag) {
                            $this->atoms['t'][$tag] = true;
                        }
                    }
                    $result[] = [Parser::RULE, $body, $alternatives];
                    break;

                case Parser::GROUP:
                    $children = $this->convert($node[3], $baseDir, $visited, $depth);
                    if ($children) {
                        $result[] = [Parser::GROUP, $node[1], $node[2], $children];
                    }
                    break;

                case Parser::AT:
                    $result[] = [Parser::AT, $node[1], $node[2], Minifier::body(UrlRebaser::rebase($node[3], $baseDir))];
                    break;

                case Parser::STATEMENT:
                    if ($node[1] === 'import') {
                        array_push($result, ...$this->import($node[2], $baseDir, $visited, $depth));
                    } elseif ($node[1] !== 'charset') {
                        $result[] = $node;
                    }
                    break;
            }
        }
        return $result;
    }

    /**
     * Локальный @import подставляется деревом импортируемого файла (с условием media — внутри @media).
     * Внешние @import в critical не попадают: их подгрузит полный CSS.
     */
    private function import(string $prelude, string $baseDir, array $visited, int $depth): array
    {
        if ($depth >= self::MAX_IMPORT_DEPTH
            || !preg_match('#^(?:url\(\s*(["\']?)(.*?)\1\s*\)|(["\'])(.*?)\3)\s*(.*)$#is', $prelude, $m)
        ) {
            return [];
        }
        $url = $m[2] !== '' ? $m[2] : $m[4];
        $condition = trim($m[5]);
        $path = UrlRebaser::resolve($url, $baseDir);
        if (!str_starts_with($path, '/') || str_starts_with($path, '//') || isset($visited[$path])) {
            return [];
        }
        $path = strtok($path, '?#');
        $absPath = $this->documentRoot . $path;
        $css = is_file($absPath) ? @file_get_contents($absPath) : false;
        if ($css === false) {
            return [];
        }
        $this->deps[$path] = [(int)filemtime($absPath), (int)filesize($absPath)];
        $visited[$path] = true;
        $nodes = $this->convert($this->parser->parse($css), self::dirOf($path), $visited, $depth + 1);
        if ($condition !== '' && !preg_match('#^(?:layer|supports)\b#i', $condition) && $nodes) {
            return [[Parser::GROUP, 'media', $condition, $nodes]];
        }
        return $nodes;
    }

    public static function dirOf(string $urlPath): string
    {
        $dir = substr($urlPath, 0, strrpos($urlPath, '/') + 1);
        return $dir === '' ? '/' : $dir;
    }
}
