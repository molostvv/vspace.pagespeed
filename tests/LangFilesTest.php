<?php

namespace Vspace\PageSpeed\Tests;

use PHPUnit\Framework\TestCase;
use Vspace\PageSpeed\Config;

/**
 * Языковые файлы: одинаковые ключи на русском и английском, у каждого поля настроек есть подпись,
 * у каждого значения select — текст.
 */
final class LangFilesTest extends TestCase
{
    private static function messages(string $relative): array
    {
        $MESS = [];
        include dirname(__DIR__) . '/lang/' . $relative;
        return $MESS;
    }

    public function testSameKeysInBothLanguages(): void
    {
        foreach (['options.php', 'install/index.php', 'admin/help.php'] as $file) {
            $ru = array_keys(self::messages('ru/' . $file));
            $en = array_keys(self::messages('en/' . $file));
            sort($ru);
            sort($en);
            $this->assertSame($ru, $en, $file);
        }
    }

    public function testEveryOptionHasLabel(): void
    {
        $messages = self::messages('ru/options.php');
        foreach (array_keys(Config::DEFAULTS) as $key) {
            $this->assertArrayHasKey('VPS_OPT_' . $key, $messages, $key);
        }
        $source = file_get_contents(dirname(__DIR__) . '/options.php');
        preg_match_all("~'([A-Z_]+)' => \['([a-z]+)'(?:, '([a-z]+)')*~", $source, $selects, PREG_SET_ORDER);
        foreach ($selects as $select) {
            preg_match_all("~'([a-z]+)'~", $select[0], $values);
            foreach ($values[1] as $value) {
                $this->assertArrayHasKey('VPS_OPT_' . $select[1] . '_' . strtoupper($value), $messages);
            }
        }
    }
}
