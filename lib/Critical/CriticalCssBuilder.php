<?php

namespace Vspace\PageSpeed\Critical;

use Vspace\PageSpeed\Css\CriticalExtractor;
use Vspace\PageSpeed\Css\StylesheetIndexer;
use Vspace\PageSpeed\Html\AtomSet;
use Vspace\PageSpeed\Storage\FileStorage;

/**
 * Возвращает critical CSS для набора таблиц стилей и атомов первого экрана, кешируя оба этапа:
 *
 *  1. Индекс файла (index/xx/<ключ файла>.php + .atoms.php) — ключ из пути, mtime и размера, т.е. правка CSS
 *     сама даёт новый индекс. Файлы @import проверяются по deps.
 *  2. Critical (critical/xx/<сигнатура>.css) — сигнатура из ключей файлов и тех атомов первого экрана, которые
 *     вообще упоминаются в этих CSS. Уникальные классы страниц (item-id-342 и т.п.) в сигнатуру не попадают,
 *     поэтому страницы одной структуры делят один critical, а разные структуры получают каждая свой.
 *
 * Обычный запрос (всё в кеше) — это stat() файлов, чтение маленьких .atoms.php из OPcache и одного .css.
 * Пока другой запрос собирает индекс или critical, текущий отдаёт страницу без изменений (статус busy).
 */
final class CriticalCssBuilder
{
    /** Меняется при изменении формата индекса или логики извлечения — старый кеш перестаёт использоваться. */
    public const VERSION = '2';

    public function __construct(
        private readonly FileStorage $storage,
        private readonly StylesheetIndexer $indexer,
        private readonly CriticalExtractor $extractor,
        private readonly string $documentRoot,
        private readonly string $optionsHash,
        private readonly int $maxSize = 0,
    ) {
    }

    /**
     * Отмечает в $refs подключения, вошедшие в critical (usable).
     * @param list<StylesheetRef> $refs
     * @return array{css: ?string, status: string, signature: string}
     *         status: hit | built | busy | empty | too_large | error
     */
    public function build(array $refs, AtomSet $pageAtoms): array
    {
        $sheets = [];
        $known = ['c' => [], 'i' => [], 't' => []];
        foreach ($refs as $ref) {
            $absPath = $this->documentRoot . $ref->urlPath;
            $mtime = @filemtime($absPath);
            if ($mtime === false) {
                continue;
            }
            $key = md5(self::VERSION . '|' . $ref->urlPath . '|' . $mtime . '|' . @filesize($absPath));
            $atoms = $this->storage->readPhp(self::indexPath($key, 'atoms'));
            if ($atoms === null || !$this->depsValid($atoms['deps'] ?? [])) {
                $atoms = $this->buildIndex($ref->urlPath, $key);
                if ($atoms === 'busy') {
                    return self::result(null, 'busy', '');
                }
                if ($atoms === null) {
                    continue;
                }
            }
            $ref->usable = true;
            $sheets[] = [$key, $ref->media];
            $known['c'] += $atoms['c'];
            $known['i'] += $atoms['i'];
            $known['t'] += $atoms['t'];
        }
        if (!$sheets) {
            return self::result(null, 'empty', '');
        }

        $relevant = $pageAtoms->intersect($known['c'], $known['i'], $known['t']);
        $signature = md5(self::VERSION . '|' . $this->optionsHash . '|' . serialize($sheets) . '|' . $relevant->signature());
        $path = 'critical/' . substr($signature, 0, 2) . '/' . $signature . '.css';

        $css = $this->storage->readText($path);
        if ($css !== null) {
            $this->storage->touch($path);
            return $this->checked($css, 'hit', $signature);
        }

        $lock = $this->storage->lock($path);
        if ($lock === null) {
            return self::result(null, 'busy', $signature);
        }
        try {
            $list = [];
            foreach ($sheets as [$key, $media]) {
                $index = $this->storage->readPhp(self::indexPath($key, 'nodes'));
                if ($index === null) {
                    return self::result(null, 'error', $signature);
                }
                $list[] = ['nodes' => $index['nodes'], 'media' => $media];
            }
            $css = $this->extractor->extract($list, $relevant);
            $this->storage->write($path, $css);
        } finally {
            $this->storage->unlock($lock, $path);
        }
        return $this->checked($css, 'built', $signature);
    }

    /** @return array|string|null атомы файла; 'busy' — индекс собирает другой запрос; null — файл не прочитан */
    private function buildIndex(string $urlPath, string $key): array|string|null
    {
        $nodesPath = self::indexPath($key, 'nodes');
        $lock = $this->storage->lock($nodesPath);
        if ($lock === null) {
            return 'busy';
        }
        try {
            $index = $this->indexer->indexFile($urlPath);
            if ($index === null) {
                return null;
            }
            $atoms = $index['atoms'] + ['deps' => $index['deps']];
            if (!$this->storage->writePhp($nodesPath, ['nodes' => $index['nodes']])
                || !$this->storage->writePhp(self::indexPath($key, 'atoms'), $atoms)
            ) {
                return null;
            }
            return $atoms;
        } finally {
            $this->storage->unlock($lock, $nodesPath);
        }
    }

    private function depsValid(array $deps): bool
    {
        foreach ($deps as $path => [$mtime, $size]) {
            $absPath = $this->documentRoot . $path;
            if (@filemtime($absPath) !== $mtime || @filesize($absPath) !== $size) {
                return false;
            }
        }
        return true;
    }

    private function checked(string $css, string $status, string $signature): array
    {
        if ($css === '') {
            return self::result(null, 'empty', $signature);
        }
        if ($this->maxSize > 0 && strlen($css) > $this->maxSize) {
            return self::result(null, 'too_large', $signature);
        }
        return self::result($css, $status, $signature);
    }

    private static function result(?string $css, string $status, string $signature): array
    {
        return ['css' => $css, 'status' => $status, 'signature' => $signature];
    }

    private static function indexPath(string $key, string $kind): string
    {
        return 'index/' . substr($key, 0, 2) . '/' . $key . ($kind === 'atoms' ? '.atoms.php' : '.php');
    }
}
