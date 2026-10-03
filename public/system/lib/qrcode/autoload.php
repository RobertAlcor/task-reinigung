<?php
/** PSR-4-Autoloader fuer chillerlan/php-qrcode und php-settings-container (ohne Composer). */
declare(strict_types=1);
spl_autoload_register(static function (string $class): void {
    $map = ['chillerlan\\QRCode\\' => __DIR__ . '/QRCode/', 'chillerlan\\Settings\\' => __DIR__ . '/Settings/'];
    foreach ($map as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) { require $file; }
            return;
        }
    }
});
