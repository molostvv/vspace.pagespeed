<?php

namespace Vspace\PageSpeed\Images;

/**
 * JPEG/PNG -> WebP через GD (есть в PHP на BitrixVM; внешние программы и облака не нужны).
 *
 * Качество — настройка WEBP_QUALITY, по умолчанию 80: на картинках сайта файл в 10–20 раз меньше исходного
 * (bg-1920.jpg: 619 КБ -> 37 КБ), разница на глаз не видна; 90 (как было у Ammina) — почти вдвое тяжелее. PNG сохраняется тоже с потерями, но с
 * прозрачностью; с WEBP_PNG_LOSSLESS PNG кодируется дважды и остаётся копия без потерь, если она не больше чем в
 * 1.5 раза тяжелее копии с потерями: на иконках и простых логотипах сжатие с потерями размывает тонкие линии и
 * текст, а без потерь они точь-в-точь, часто того же веса (на 101 PNG сайта: 20 копий без потерь, вес +0.3%).
 * На диске остаётся одна копия — вторая во временном файле и удаляется. Поворот из EXIF применяется к пикселям: браузер поворачивает JPEG по EXIF, а в WebP от GD
 * метаданных нет.
 *
 * Если WebP не получился меньше исходника хотя бы на 5% (мелкие иконки, уже сжатые PNG), или картинку нельзя
 * конвертировать без риска (CMYK, огромный размер, нехватка памяти), результат — «оставить исходник».
 */
final class WebpConverter
{
    public const QUALITY = 80;
    /** Предел WebP по стороне. */
    private const MAX_SIDE = 16383;
    /** ~40 Мп: 160 МБ памяти на пиксели — больше на сайте не бывает, а риск фатала по памяти растёт. */
    private const MAX_PIXELS = 40_000_000;
    private const MIN_SAVING = 0.95;
    /** Копия PNG без потерь берётся, если не тяжелее копии с потерями больше чем во столько раз. */
    private const LOSSLESS_MAX_RATIO = 1.5;

    public function __construct(private readonly int $quality = self::QUALITY, private readonly bool $pngLossless = false)
    {
    }

    /**
     * @return bool true — записан WebP, false — WebP не нужен (оставить исходник)
     * @throws \RuntimeException исходник не читается или GD не смог записать файл
     */
    public function convert(string $source, string $target): bool
    {
        $info = @getimagesize($source);
        if ($info === false) {
            throw new \RuntimeException('not an image: ' . $source);
        }
        [$width, $height, $type] = $info;
        if (!in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)
            || ($type === IMAGETYPE_JPEG && ($info['channels'] ?? 3) === 4) // CMYK: GD выдаёт искажённые цвета
            || $width > self::MAX_SIDE || $height > self::MAX_SIDE || $width * $height > self::MAX_PIXELS
            || !$this->memoryFor($width, $height)
        ) {
            return false;
        }

        $image = $type === IMAGETYPE_JPEG ? @imagecreatefromjpeg($source) : @imagecreatefrompng($source);
        if ($image === false) {
            throw new \RuntimeException('GD cannot read ' . $source);
        }
        try {
            if ($type === IMAGETYPE_JPEG) {
                $image = self::orient($image, $source);
            } else {
                if (!imageistruecolor($image)) {
                    imagepalettetotruecolor($image); // палитровый PNG GD в WebP не пишет
                }
                imagealphablending($image, false);
                imagesavealpha($image, true);
            }
            $tmp = $target . '.' . getmypid() . '.tmp';
            if (!@imagewebp($image, $tmp, $this->quality) || !is_file($tmp)) {
                @unlink($tmp);
                throw new \RuntimeException('GD cannot write WebP for ' . $source);
            }
            if ($type === IMAGETYPE_PNG && $this->pngLossless) {
                $tmp = $this->preferLossless($image, $tmp, $target, $source);
            }
        } finally {
            imagedestroy($image);
        }

        clearstatcache(true, $tmp);
        if (filesize($tmp) >= filesize($source) * self::MIN_SAVING) {
            @unlink($tmp);
            return false;
        }
        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            throw new \RuntimeException('cannot write ' . $target);
        }
        return true;
    }

    /** Копия без потерь вместо $lossy, если она не намного тяжелее и легче исходника; возвращает путь выбранной. */
    private function preferLossless(\GdImage $image, string $lossy, string $target, string $source): string
    {
        $lossless = $target . '.' . getmypid() . '.lossless.tmp';
        if (!@imagewebp($image, $lossless, IMG_WEBP_LOSSLESS) || !is_file($lossless)) {
            @unlink($lossless);
            return $lossy;
        }
        clearstatcache(true, $lossy);
        clearstatcache(true, $lossless);
        $size = filesize($lossless);
        if ($size <= filesize($lossy) * self::LOSSLESS_MAX_RATIO && $size < filesize($source) * self::MIN_SAVING) {
            @unlink($lossy);
            return $lossless;
        }
        @unlink($lossless);
        return $lossy;
    }

    /** Хватит ли памяти: исходник + копия при повороте, 4 байта на пиксель с запасом. */
    private function memoryFor(int $width, int $height): bool
    {
        $limit = self::bytes((string)ini_get('memory_limit'));
        return $limit <= 0 || memory_get_usage() + $width * $height * 10 + 8 * 1024 * 1024 < $limit;
    }

    private static function bytes(string $value): int
    {
        $value = trim($value);
        $number = (int)$value;
        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /** Поворот/отражение по EXIF Orientation (2–8), чтобы WebP выглядел как JPEG в браузере. */
    private static function orient(\GdImage $image, string $source): \GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $image;
        }
        $exif = @exif_read_data($source);
        $orientation = is_array($exif) ? (int)($exif['Orientation'] ?? 1) : 1;
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, $orientation === 4 ? IMG_FLIP_VERTICAL : IMG_FLIP_HORIZONTAL);
        }
        // imagerotate крутит против часовой стрелки
        $angle = [3 => 180, 5 => 90, 6 => -90, 7 => -90, 8 => 90][$orientation] ?? 0;
        if ($angle !== 0) {
            $rotated = imagerotate($image, $angle, 0);
            if ($rotated !== false) {
                imagedestroy($image);
                $image = $rotated;
            }
        }
        return $image;
    }
}
