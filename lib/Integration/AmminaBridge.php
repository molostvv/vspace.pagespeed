<?php

namespace Vspace\PageSpeed\Integration;

/**
 * Мост к модулю ammina.optimizer на время перехода: выключает в текущем запросе только её CSS-оптимизацию
 * (объединение, critical, перенос стилей), а WebP, отложенный JS и прочее у Ammina продолжают работать.
 *
 * Настройки Ammina лежат в защищённом свойстве синглтона \Ammina\Optimizer\Core2\Application::$arOptions,
 * которое она читает в своём OnEndBufferContent; меняем его через привязку замыкания к классу. Это
 * единственное место модуля, завязанное на внутренности Ammina: если её класса или свойства нет
 * (модуль удалён или обновился), мост молча ничего не делает. После отказа от Ammina класс удаляется.
 */
final class AmminaBridge
{
    private const APPLICATION = '\Ammina\Optimizer\Core2\Application';

    public static function disableCss(): bool
    {
        // false — не подгружать класс: если Ammina в этом запросе не инициализировалась, выключать нечего
        if (!class_exists(self::APPLICATION, false)) {
            return false;
        }
        $class = ltrim(self::APPLICATION, '\\');
        $app = $class::getInstance();
        $patch = \Closure::bind(function (): bool {
            if (!isset($this->arOptions['category']['css']['options']) || !is_array($this->arOptions['category']['css']['options'])) {
                return false;
            }
            $this->arOptions['category']['css']['options']['ACTIVE'] = 'N';
            return true;
        }, $app, $class);
        return $patch();
    }
}
