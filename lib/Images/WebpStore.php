<?php

namespace Vspace\PageSpeed\Images;

/**
 * WebP-копии картинок и очередь на конвертацию — файлы в /upload/vspace.pagespeed/ (не в /bitrix/cache:
 * очистка кеша сайта не должна заставлять конвертировать всё заново).
 *
 *  - webp/<путь исходника>.webp — копия (/upload/iblock/a/b.jpg -> webp/upload/iblock/a/b.jpg.webp, расширение
 *    исходника остаётся в имени — b.jpg и b.png не столкнутся). Время файла = времени исходника: исходник
 *    заменили — копия устарела. Пустой файл — «WebP не нужен, отдавать исходник» (не меньше исходника,
 *    CMYK, ошибка): такие картинки не конвертируются повторно, пока исходник не изменится;
 *  - queue/<md5 пути> — картинка ждёт конвертации (содержимое — путь). Ставит запрос страницы, разбирает
 *    агент (processQueue) — только из cron; на время конвертации файл переименовывается в .work: если
 *    процесс упал на этой картинке (память, битый файл), следующий запуск отметит её «отдавать исходник»;
 *  - cron.txt — последний запуск агента из cron (время файла; по нему настройки предупреждают, что cron не
 *    работает) и что умеет PHP агента: строки «webp=Y|N», «php=…», «gd=…». PHP агента (CLI) и PHP сайта
 *    бывают разными: подмена адресов и очередь GD не нужен, поэтому сайт смотрит на агента (canConvert).
 *
 * Пути — от корня сайта, раскодированные (как файл лежит на диске).
 */
final class WebpStore
{
    public const DIR = '/upload/vspace.pagespeed/webp';
    private const QUEUE_DIR = '/upload/vspace.pagespeed/queue';
    private const CRON_FILE = '/upload/vspace.pagespeed/cron.txt';
    /** .work старше — процесс на этой картинке упал. */
    private const WORK_TTL = 600;
    /** Агент запускается из cron раз в минуту; тишина дольше — cron не работает. */
    private const CRON_SILENCE = 600;

    public const NO_SOURCE = 0;
    public const READY = 1;
    public const ORIGINAL = 2;
    public const MISSING = 3;

    public function __construct(private readonly string $documentRoot)
    {
    }

    /** Путь от корня сайта, который можно конвертировать: JPEG/PNG, без «..», не сама копия. */
    public static function validPath(string $path): bool
    {
        return $path !== '' && $path[0] === '/' && !str_contains($path, "\0") && !str_contains($path, '\\')
            && !preg_match('#/\.\.?(?:/|$)#', $path) && preg_match('/\.(?:jpe?g|png)$/i', $path)
            && !str_starts_with($path, '/upload/vspace.pagespeed/');
    }

    /** Адрес копии; $path — как в разметке (кодирование сохраняется). */
    public function url(string $path): string
    {
        return self::DIR . $path . '.webp';
    }

    public function targetPath(string $path): string
    {
        return $this->documentRoot . self::DIR . $path . '.webp';
    }

    /** Состояние копии: два stat на картинку (исходник и копия). */
    public function status(string $path): int
    {
        $source = $this->documentRoot . $path;
        if (!is_file($source)) {
            return self::NO_SOURCE;
        }
        $mtime = filemtime($source);
        $target = $this->targetPath($path);
        if (!is_file($target) || filemtime($target) !== $mtime) {
            return self::MISSING;
        }
        return filesize($target) > 0 ? self::READY : self::ORIGINAL;
    }

    /** @return bool false — картинка уже в очереди */
    public function enqueue(string $path): bool
    {
        $file = $this->queueFile($path);
        if (is_file($file) || is_file($file . '.work')) {
            return false;
        }
        return self::writeAtomic($file, $path);
    }

    /**
     * Конвертирует картинки из очереди, пока не выйдет время. Параллельный запуск (два cron-процесса)
     * сразу выходит — его держит flock.
     * @param callable(\Throwable, string):void|null $onError
     * @return array{converted:int, original:int, failed:int, left:int}|null null — уже работает другой процесс
     */
    public function processQueue(WebpConverter $converter, float $seconds, ?callable $onError = null): ?array
    {
        $dir = $this->documentRoot . self::QUEUE_DIR;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }
        $lock = @fopen($dir . '/.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            return null;
        }
        $deadline = microtime(true) + $seconds;
        $stats = ['converted' => 0, 'original' => 0, 'failed' => 0, 'left' => 0];
        try {
            foreach (scandir($dir) ?: [] as $name) {
                if ($name[0] === '.') {
                    continue;
                }
                $file = $dir . '/' . $name;
                if (str_ends_with($name, '.tmp')) {
                    if (@filemtime($file) < time() - self::WORK_TTL) {
                        @unlink($file);
                    }
                    continue;
                }
                if (str_ends_with($name, '.work')) {
                    if (@filemtime($file) < time() - self::WORK_TTL) {
                        $this->markOriginal((string)@file_get_contents($file));
                        @unlink($file);
                        $stats['failed']++;
                    }
                    continue;
                }
                if (microtime(true) >= $deadline) {
                    $stats['left']++;
                    continue;
                }
                $path = (string)@file_get_contents($file);
                if (!self::validPath($path) || $this->status($path) !== self::MISSING || !@rename($file, $file . '.work')) {
                    @unlink($file);
                    continue;
                }
                try {
                    $target = $this->targetPath($path);
                    if (!is_dir(dirname($target))) {
                        @mkdir(dirname($target), 0775, true);
                    }
                    if ($converter->convert($this->documentRoot . $path, $target)) {
                        @touch($target, filemtime($this->documentRoot . $path));
                        $stats['converted']++;
                    } else {
                        $this->markOriginal($path);
                        $stats['original']++;
                    }
                } catch (\Throwable $e) {
                    $this->markOriginal($path);
                    $stats['failed']++;
                    if ($onError !== null) {
                        $onError($e, $path);
                    }
                }
                @unlink($file . '.work');
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return $stats;
    }

    /** Отметка запуска агента из cron и что умеет его PHP: $webp — WebpSupport::server() в процессе агента. */
    public function markCron(bool $webp, string $php = PHP_VERSION, string $gd = ''): void
    {
        $line = static fn(string $value): string => preg_replace('/[\r\n]+/', ' ', $value);
        self::writeAtomic($this->documentRoot . self::CRON_FILE, time() . "\nwebp=" . ($webp ? 'Y' : 'N')
            . "\nphp=" . $line($php) . "\ngd=" . $line($gd) . "\n");
    }

    /**
     * Что сообщил агент о своём PHP при последнем запуске; null — не запускался или cron.txt старого формата
     * (только время).
     * @return array{webp:bool, php:string, gd:string}|null
     */
    public function cronInfo(): ?array
    {
        $text = @file_get_contents($this->documentRoot . self::CRON_FILE);
        if ($text === false || !preg_match('/^webp=([YN])$/m', $text, $m)) {
            return null;
        }
        $value = static fn(string $key): string => preg_match('/^' . $key . '=(.*)$/m', $text, $v) ? trim($v[1]) : '';
        return ['webp' => $m[1] === 'Y', 'php' => $value('php'), 'gd' => $value('gd')];
    }

    /**
     * Появятся ли копии: умеет ли WebP PHP агента (по его отчёту в cron.txt). Отчёта ещё нет — по PHP сайта,
     * обычно это тот же PHP.
     */
    public function canConvert(): bool
    {
        return $this->cronInfo()['webp'] ?? WebpSupport::server();
    }

    /** Агент недавно запускался из cron — копии появляются. */
    public function cronWorks(): bool
    {
        $age = $this->cronAge();
        return $age !== null && $age <= self::CRON_SILENCE;
    }

    /** Секунд с последнего запуска агента из cron; null — ни разу. */
    public function cronAge(): ?int
    {
        $mtime = @filemtime($this->documentRoot . self::CRON_FILE);
        return $mtime === false ? null : max(0, time() - $mtime);
    }

    /** @return array{files:int, bytes:int, originals:int, queue:int} */
    public function stats(): array
    {
        $stats = ['files' => 0, 'bytes' => 0, 'originals' => 0, 'queue' => 0];
        foreach ($this->webpFiles() as $file) {
            $size = $file->getSize();
            if ($size > 0) {
                $stats['files']++;
                $stats['bytes'] += $size;
            } else {
                $stats['originals']++;
            }
        }
        foreach (@scandir($this->documentRoot . self::QUEUE_DIR) ?: [] as $name) {
            if ($name[0] !== '.' && !str_ends_with($name, '.tmp')) {
                $stats['queue']++;
            }
        }
        return $stats;
    }

    /** Удаляет копии, исходников которых больше нет, и обрывки записи. @return int сколько удалено */
    public function cleanup(): int
    {
        $deleted = 0;
        $prefix = strlen($this->documentRoot . self::DIR);
        foreach ($this->webpFiles(true) as $file) {
            $name = str_replace('\\', '/', $file->getPathname());
            $orphan = str_ends_with($name, '.tmp')
                ? $file->getMTime() < time() - self::WORK_TTL
                : !is_file($this->documentRoot . substr($name, $prefix, -5));
            if ($orphan && @unlink($name)) {
                $deleted++;
            }
        }
        return $deleted;
    }

    /** Удаляет все копии и очередь. @return int сколько файлов удалено */
    public function clear(): int
    {
        $deleted = 0;
        foreach ([self::DIR, self::QUEUE_DIR] as $dir) {
            $dir = $this->documentRoot . $dir;
            if (!is_dir($dir)) {
                continue;
            }
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($items as $item) {
                if ($item->isDir()) {
                    @rmdir($item->getPathname());
                } elseif (@unlink($item->getPathname())) {
                    $deleted++;
                }
            }
        }
        return $deleted;
    }

    private function markOriginal(string $path): void
    {
        if (!self::validPath($path) || !is_file($this->documentRoot . $path)) {
            return;
        }
        $target = $this->targetPath($path);
        if (self::writeAtomic($target, '')) {
            @touch($target, filemtime($this->documentRoot . $path));
        }
    }

    private function queueFile(string $path): string
    {
        return $this->documentRoot . self::QUEUE_DIR . '/' . md5($path);
    }

    /** @return iterable<\SplFileInfo> копии (.webp), с $withTemp — и недописанные .tmp */
    private function webpFiles(bool $withTemp = false): iterable
    {
        $dir = $this->documentRoot . self::DIR;
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($items as $item) {
            $name = $item->getFilename();
            if (str_ends_with($name, '.webp') || ($withTemp && str_ends_with($name, '.tmp'))) {
                yield $item;
            }
        }
    }

    private static function writeAtomic(string $file, string $data): bool
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
        $tmp = $file . '.' . getmypid() . '.' . bin2hex(random_bytes(3)) . '.tmp';
        if (@file_put_contents($tmp, $data) !== strlen($data) || !@rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }
}
