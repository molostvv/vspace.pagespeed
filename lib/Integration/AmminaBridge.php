<?php

namespace Vspace\PageSpeed\Integration;

/**
 * Мост к модулю ammina.optimizer на время перехода: выключает в текущем запросе блоки Ammina, которые делает
 * этот модуль, — CSS-оптимизацию (объединение, её critical) и lazyload (заглушки до window.onload, «antilazy»).
 * WebP, отложенный JS и прочее у Ammina продолжают работать.
 *
 * Настройки Ammina лежат в защищённом свойстве синглтона \Ammina\Optimizer\Core2\Application::$arOptions,
 * которое она читает в своём OnEndBufferContent; меняем его через привязку замыкания к классу. Это
 * единственное место модуля, завязанное на внутренности Ammina: если её класса или свойства нет
 * (модуль удалён или обновился), мост молча ничего не делает. После отказа от Ammina класс удаляется.
 */
final class AmminaBridge
{
    private const APPLICATION = '\Ammina\Optimizer\Core2\Application';

    /**
     * @param list<string> $categories блоки настроек Ammina: css, lazy
     * @return list<string> выключенные блоки
     */
    public static function disable(array $categories): array
    {
        // false — не подгружать класс: если Ammina в этом запросе не инициализировалась, выключать нечего
        if (!$categories || !class_exists(self::APPLICATION, false)) {
            return [];
        }
        $class = ltrim(self::APPLICATION, '\\');
        $app = $class::getInstance();
        $patch = \Closure::bind(function () use ($categories): array {
            $disabled = [];
            foreach ($categories as $category) {
                if (isset($this->arOptions['category'][$category]['options']) && is_array($this->arOptions['category'][$category]['options'])) {
                    $this->arOptions['category'][$category]['options']['ACTIVE'] = 'N';
                    $disabled[] = $category;
                }
            }
            return $disabled;
        }, $app, $class);
        return $patch();
    }
}
