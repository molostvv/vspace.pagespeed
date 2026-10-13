<?php

namespace Vspace\PageSpeed\Tests\Images;

use PHPUnit\Framework\TestCase;
use Vspace\PageSpeed\Images\WebpConverter;
use Vspace\PageSpeed\Images\WebpSupport;

final class WebpConverterTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        if (!WebpSupport::server()) {
            $this->markTestSkipped('GD без WebP');
        }
        $this->dir = str_replace('\\', '/', sys_get_temp_dir()) . '/vps-webp-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->dir);
    }

    /** Картинка «как фото»: шум не сжимается в PNG/JPEG без потерь, WebP заметно меньше. */
    public static function noise(int $width, int $height, bool $alpha = false): \GdImage
    {
        mt_srand(42);
        $image = imagecreatetruecolor($width, $height);
        if ($alpha) {
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $color = $alpha
                    ? imagecolorallocatealpha($image, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255), $x < $width / 2 ? 0 : 127)
                    : imagecolorallocate($image, ($x * 3 + mt_rand(0, 40)) % 256, ($y * 2 + mt_rand(0, 40)) % 256, mt_rand(0, 255));
                imagesetpixel($image, $x, $y, $color);
            }
        }
        return $image;
    }

    public function testJpegBecomesSmallerWebp(): void
    {
        imagejpeg(self::noise(300, 200), $this->dir . '/a.jpg', 100);
        $this->assertTrue((new WebpConverter())->convert($this->dir . '/a.jpg', $this->dir . '/a.webp'));
        $info = getimagesize($this->dir . '/a.webp');
        $this->assertSame([300, 200, IMAGETYPE_WEBP], [$info[0], $info[1], $info[2]]);
        $this->assertLessThan(filesize($this->dir . '/a.jpg') * 0.95, filesize($this->dir . '/a.webp'));
        $this->assertSame([], glob($this->dir . '/*.tmp'));
    }

    public function testQualityAffectsSize(): void
    {
        imagejpeg(self::noise(300, 200), $this->dir . '/q.jpg', 100);
        $this->assertTrue((new WebpConverter(40))->convert($this->dir . '/q.jpg', $this->dir . '/q40.webp'));
        $this->assertTrue((new WebpConverter(90))->convert($this->dir . '/q.jpg', $this->dir . '/q90.webp'));
        $this->assertLessThan(filesize($this->dir . '/q90.webp'), filesize($this->dir . '/q40.webp'));
    }

    /** Иконка: заливка и тонкие линии — без потерь не тяжелее, берётся она; фото-шум — остаётся с потерями. */
    public function testPngLosslessWhenNotMuchHeavier(): void
    {
        $icon = imagecreatetruecolor(120, 120);
        imagealphablending($icon, false);
        imagesavealpha($icon, true);
        imagefill($icon, 0, 0, imagecolorallocatealpha($icon, 0, 0, 0, 127));
        imagefilledellipse($icon, 60, 60, 110, 110, imagecolorallocatealpha($icon, 187, 25, 52, 0));
        for ($i = 0; $i < 6; $i++) {
            imageline($icon, 30, 35 + $i * 10, 90, 35 + $i * 10, imagecolorallocatealpha($icon, 255, 255, 255, 0));
        }
        imagepng($icon, $this->dir . '/icon.png');
        imagepng(self::noise(200, 100, true), $this->dir . '/photo.png');
        $isLossless = fn(string $file): bool => str_contains((string)file_get_contents($file, false, null, 0, 64), 'VP8L');

        $this->assertTrue((new WebpConverter(80, true))->convert($this->dir . '/icon.png', $this->dir . '/icon.webp'));
        $this->assertTrue($isLossless($this->dir . '/icon.webp'));
        $this->assertTrue((new WebpConverter(80, true))->convert($this->dir . '/photo.png', $this->dir . '/photo.webp'));
        $this->assertFalse($isLossless($this->dir . '/photo.webp'));
        // опция выключена: с потерями иконка тяжелее PNG (1.2 КБ против 0.8) — остаётся исходник
        $this->assertFalse((new WebpConverter(80))->convert($this->dir . '/icon.png', $this->dir . '/icon2.webp'));
        $this->assertSame([], glob($this->dir . '/*.tmp'));
    }

    public function testPngKeepsTransparency(): void
    {
        imagepng(self::noise(200, 100, true), $this->dir . '/a.png');
        $this->assertTrue((new WebpConverter())->convert($this->dir . '/a.png', $this->dir . '/a.webp'));
        $webp = imagecreatefromwebp($this->dir . '/a.webp');
        $this->assertSame(0, (imagecolorat($webp, 10, 50) >> 24) & 0x7F); // левая половина непрозрачная
        $this->assertSame(127, (imagecolorat($webp, 190, 50) >> 24) & 0x7F); // правая — прозрачная
    }

    public function testExifOrientationIsApplied(): void
    {
        if (!function_exists('exif_read_data')) {
            $this->markTestSkipped('нет exif');
        }
        imagejpeg(self::noise(300, 100), $this->dir . '/r.jpg', 100);
        // APP1 Exif c Orientation = 6 (повернуть на 90° по часовой) сразу после SOI
        $tiff = 'II*' . "\0" . pack('V', 8) . pack('v', 1) . pack('vvVvv', 0x0112, 3, 1, 6, 0) . pack('V', 0);
        $app1 = "\xFF\xE1" . pack('n', strlen($tiff) + 8) . "Exif\0\0" . $tiff;
        $jpeg = file_get_contents($this->dir . '/r.jpg');
        file_put_contents($this->dir . '/r.jpg', substr($jpeg, 0, 2) . $app1 . substr($jpeg, 2));
        $this->assertSame(6, exif_read_data($this->dir . '/r.jpg')['Orientation'] ?? null);

        $this->assertTrue((new WebpConverter())->convert($this->dir . '/r.jpg', $this->dir . '/r.webp'));
        $info = getimagesize($this->dir . '/r.webp');
        $this->assertSame([100, 300], [$info[0], $info[1]]);
    }

    public function testNotWorthItKeepsOriginal(): void
    {
        // палитровая шахматка 1px: PNG сжимает повторяющиеся строки в сотню байт, WebP с потерями — нет
        $chess = imagecreate(400, 400);
        $colors = [imagecolorallocate($chess, 0, 0, 0), imagecolorallocate($chess, 255, 255, 255)];
        for ($y = 0; $y < 400; $y++) {
            for ($x = 0; $x < 400; $x++) {
                imagesetpixel($chess, $x, $y, $colors[($x + $y) % 2]);
            }
        }
        imagepng($chess, $this->dir . '/chess.png', 9);
        $this->assertFalse((new WebpConverter())->convert($this->dir . '/chess.png', $this->dir . '/chess.webp'));
        $this->assertFileDoesNotExist($this->dir . '/chess.webp');
        $this->assertSame([], glob($this->dir . '/*.tmp'));

        imagegif($chess, $this->dir . '/a.gif'); // GIF не конвертируется (анимация)
        $this->assertFalse((new WebpConverter())->convert($this->dir . '/a.gif', $this->dir . '/a.webp'));
    }

    public function testBrokenFileThrows(): void
    {
        file_put_contents($this->dir . '/broken.jpg', 'not an image');
        $this->expectException(\RuntimeException::class);
        (new WebpConverter())->convert($this->dir . '/broken.jpg', $this->dir . '/broken.webp');
    }
}
