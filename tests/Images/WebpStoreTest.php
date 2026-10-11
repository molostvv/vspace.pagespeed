<?php

namespace Vspace\PageSpeed\Tests\Images;

use PHPUnit\Framework\TestCase;
use Vspace\PageSpeed\Images\WebpConverter;
use Vspace\PageSpeed\Images\WebpStore;
use Vspace\PageSpeed\Images\WebpSupport;

final class WebpStoreTest extends TestCase
{
    private string $root;
    private WebpStore $store;

    protected function setUp(): void
    {
        $this->root = str_replace('\\', '/', sys_get_temp_dir()) . '/vps-store-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/img', 0777, true);
        $this->store = new WebpStore($this->root);
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testValidPath(): void
    {
        $this->assertTrue(WebpStore::validPath('/upload/iblock/a/b.JPG'));
        $this->assertTrue(WebpStore::validPath('/img/30+.png'));
        $this->assertFalse(WebpStore::validPath('img/a.jpg'));
        $this->assertFalse(WebpStore::validPath('/img/../../etc/a.jpg'));
        $this->assertFalse(WebpStore::validPath('/img/a.gif'));
        $this->assertFalse(WebpStore::validPath('/upload/vspace.pagespeed/webp/img/a.jpg'));
    }

    public function testStatusFollowsSourceAndCopy(): void
    {
        $this->assertSame(WebpStore::NO_SOURCE, $this->store->status('/img/a.jpg'));
        file_put_contents($this->root . '/img/a.jpg', 'x');
        touch($this->root . '/img/a.jpg', 1_700_000_000);
        $this->assertSame(WebpStore::MISSING, $this->store->status('/img/a.jpg'));

        $target = $this->store->targetPath('/img/a.jpg');
        $this->assertSame($this->root . '/upload/vspace.pagespeed/webp/img/a.jpg.webp', $target);
        mkdir(dirname($target), 0777, true);
        file_put_contents($target, 'webp');
        touch($target, 1_700_000_000);
        clearstatcache();
        $this->assertSame(WebpStore::READY, $this->store->status('/img/a.jpg'));
        $this->assertSame('/upload/vspace.pagespeed/webp/img/a.jpg.webp', $this->store->url('/img/a.jpg'));

        touch($this->root . '/img/a.jpg', 1_700_000_100); // исходник заменили — копия устарела
        clearstatcache();
        $this->assertSame(WebpStore::MISSING, $this->store->status('/img/a.jpg'));

        file_put_contents($target, ''); // пустая копия — «отдавать исходник»
        touch($target, 1_700_000_100);
        clearstatcache();
        $this->assertSame(WebpStore::ORIGINAL, $this->store->status('/img/a.jpg'));
    }

    public function testQueueIsProcessedByAgent(): void
    {
        if (!WebpSupport::server()) {
            $this->markTestSkipped('GD без WebP');
        }
        imagejpeg(WebpConverterTest::noise(200, 100), $this->root . '/img/photo.jpg', 100);
        file_put_contents($this->root . '/img/broken.jpg', 'not an image');

        $this->assertTrue($this->store->enqueue('/img/photo.jpg'));
        $this->assertFalse($this->store->enqueue('/img/photo.jpg'));
        $this->assertTrue($this->store->enqueue('/img/broken.jpg'));
        $this->assertTrue($this->store->enqueue('/img/gone.jpg'));
        $this->assertSame(3, $this->store->stats()['queue']);

        $errors = [];
        $stats = $this->store->processQueue(new WebpConverter(), 10, function (\Throwable $e, string $path) use (&$errors): void {
            $errors[] = $path;
        });
        $this->assertSame(['converted' => 1, 'original' => 0, 'failed' => 1, 'left' => 0], $stats);
        $this->assertSame(['/img/broken.jpg'], $errors);
        clearstatcache();
        $this->assertSame(WebpStore::READY, $this->store->status('/img/photo.jpg'));
        $this->assertSame(WebpStore::ORIGINAL, $this->store->status('/img/broken.jpg')); // не пытаться снова
        $this->assertSame(['files' => 1, 'bytes' => filesize($this->store->targetPath('/img/photo.jpg')), 'originals' => 1, 'queue' => 0], $this->store->stats());
    }

    public function testCrashedConversionIsNotRetried(): void
    {
        file_put_contents($this->root . '/img/a.jpg', 'x');
        mkdir($this->root . '/upload/vspace.pagespeed/queue', 0777, true);
        $work = $this->root . '/upload/vspace.pagespeed/queue/' . md5('/img/a.jpg') . '.work';
        file_put_contents($work, '/img/a.jpg');
        touch($work, time() - 3600); // процесс упал на этой картинке час назад

        $stats = $this->store->processQueue(new WebpConverter(), 10);
        $this->assertSame(1, $stats['failed']);
        $this->assertFileDoesNotExist($work);
        clearstatcache();
        $this->assertSame(WebpStore::ORIGINAL, $this->store->status('/img/a.jpg'));
    }

    public function testCleanupAndClear(): void
    {
        foreach (['/img/kept.jpg', '/img/deleted.jpg'] as $path) {
            file_put_contents($this->root . $path, 'x');
            $target = $this->store->targetPath($path);
            @mkdir(dirname($target), 0777, true);
            file_put_contents($target, 'webp');
        }
        unlink($this->root . '/img/deleted.jpg');
        $this->assertSame(1, $this->store->cleanup());
        $this->assertFileExists($this->store->targetPath('/img/kept.jpg'));
        $this->assertFileDoesNotExist($this->store->targetPath('/img/deleted.jpg'));

        $this->store->enqueue('/img/kept.jpg');
        $this->assertSame(2, $this->store->clear());
        $this->assertSame(['files' => 0, 'bytes' => 0, 'originals' => 0, 'queue' => 0], $this->store->stats());
    }

    public function testCronMark(): void
    {
        $this->assertNull($this->store->cronAge());
        $this->assertFalse($this->store->cronWorks());
        $this->store->markCron();
        $this->assertLessThan(5, $this->store->cronAge());
        $this->assertTrue($this->store->cronWorks());
        touch($this->root . '/upload/vspace.pagespeed/cron.txt', time() - 3600);
        clearstatcache();
        $this->assertFalse($this->store->cronWorks());
    }
}
