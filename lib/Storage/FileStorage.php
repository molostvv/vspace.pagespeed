<?php

namespace Vspace\PageSpeed\Storage;

/**
 * Файловый кеш модуля: /bitrix/cache/vspace.pagespeed/ и (файлы для браузера) /bitrix/cache/css/vspace.pagespeed/ —
 * очищается вместе с кешем сайта и пересобирается сам.
 *
 *  - запись атомарна (временный файл + rename): параллельный запрос не прочитает недописанный файл;
 *  - индексы CSS хранятся как PHP (var_export) — повторное чтение идёт из OPcache;
 *  - lock() не даёт нескольким запросам одновременно собирать одно и то же (после деплоя или очистки кеша);
 *  - touch() обновляет время файла не чаще раза в сутки — по нему cleanup() удаляет неиспользуемое.
 */
final class FileStorage
{
    private const TOUCH_INTERVAL = 86400;
    private const LOCK_TTL = 60;

    /**
     * @param string $root абсолютный путь каталога кеша
     * @param string $urlRoot тот же каталог от корня сайта (для файлов, которые отдаются браузеру)
     */
    public function __construct(private readonly string $root, private readonly string $urlRoot = '')
    {
    }

    public function path(string $relative): string
    {
        return $this->root . '/' . $relative;
    }

    public function url(string $relative): string
    {
        return $this->urlRoot . '/' . $relative;
    }

    public function exists(string $relative): bool
    {
        return is_file($this->path($relative));
    }

    public function readPhp(string $relative): ?array
    {
        $path = $this->path($relative);
        if (!is_file($path)) {
            return null;
        }
        $data = @include $path;
        return is_array($data) ? $data : null;
    }

    public function writePhp(string $relative, array $data): bool
    {
        $ok = $this->write($relative, '<?php return ' . var_export($data, true) . ";\n");
        if ($ok && function_exists('opcache_invalidate')) {
            @opcache_invalidate($this->path($relative), true);
        }
        return $ok;
    }

    public function readText(string $relative): ?string
    {
        $path = $this->path($relative);
        if (!is_file($path)) {
            return null;
        }
        $data = @file_get_contents($path);
        return $data === false ? null : $data;
    }

    public function write(string $relative, string $data): bool
    {
        $path = $this->path($relative);
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
        $tmp = $path . '.' . getmypid() . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $data) !== strlen($data)) {
            @unlink($tmp);
            return false;
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    public function touch(string $relative): void
    {
        $path = $this->path($relative);
        $mtime = @filemtime($path);
        if ($mtime !== false && $mtime < time() - self::TOUCH_INTERVAL) {
            @touch($path);
        }
    }

    /**
     * Эксклюзивная блокировка на сборку. null — уже собирает другой запрос (зависшая блокировка старше
     * LOCK_TTL снимается). Освобождать через unlock().
     * @return resource|null
     */
    public function lock(string $relative)
    {
        $path = $this->path($relative) . '.lock';
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $handle = @fopen($path, 'x');
            if ($handle !== false) {
                return $handle;
            }
            $mtime = @filemtime($path);
            if ($mtime === false || $mtime > time() - self::LOCK_TTL) {
                return null;
            }
            @unlink($path);
        }
        return null;
    }

    /** @param resource $handle */
    public function unlock($handle, string $relative): void
    {
        @fclose($handle);
        @unlink($this->path($relative) . '.lock');
    }

    /** Удаляет файлы, не использовавшиеся дольше $maxAge секунд. @return int сколько удалено */
    public function cleanup(int $maxAge): int
    {
        return $this->walk(function (\SplFileInfo $file) use ($maxAge): bool {
            return $file->getMTime() < time() - $maxAge;
        });
    }

    public function clear(): int
    {
        return $this->walk(static fn(): bool => true);
    }

    /** @return array{files:int, bytes:int} */
    public function stats(string $relativeDir = ''): array
    {
        $files = 0;
        $bytes = 0;
        foreach ($this->iterate($relativeDir) as $file) {
            $files++;
            $bytes += $file->getSize();
        }
        return ['files' => $files, 'bytes' => $bytes];
    }

    private function walk(callable $shouldDelete): int
    {
        $deleted = 0;
        foreach ($this->iterate('') as $file) {
            if ($shouldDelete($file) && @unlink($file->getPathname())) {
                $deleted++;
            }
        }
        return $deleted;
    }

    /** @return iterable<\SplFileInfo> */
    private function iterate(string $relativeDir): iterable
    {
        $dir = rtrim($this->path($relativeDir), '/');
        if (!is_dir($dir)) {
            return [];
        }
        return new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
    }
}
