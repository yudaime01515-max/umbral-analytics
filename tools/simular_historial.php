<?php
/**
 * Solo para el modo prueba: simula 30 días de funcionamiento para llenar el historial.
 * Uso:  php tools/simular_historial.php
 */
$root = dirname(__DIR__);
$cfg = require "$root/config.php";
if (empty($cfg['mock'])) exit("Este script solo funciona con 'mock' => true en config.php\n");
date_default_timezone_set($cfg['timezone']);
@unlink("$root/data/historial.json");
$today = strtotime('today');
for ($d = -30; $d <= 0; $d++) {
    foreach ([8, 23] as $hour) {
        $t = $today + $d * 86400 + $hour * 3600;
        if ($t > time()) $t = time();
        putenv("UMBRAL_NOW=$t");
        passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$root/update.php") . ' > /dev/null');
    }
}
putenv('UMBRAL_NOW');
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$root/update.php"));
