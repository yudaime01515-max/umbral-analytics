<?php
/**
 * Umbral Analytics — actualización
 * Descarga partidos, calcula probabilidades, publica pronósticos y cierra resultados.
 * Ejecútalo con un cron cada 3 horas:  php /ruta/a/umbral/update.php
 */
$root = __DIR__;
$cfg = require "$root/config.php";
require "$root/lib/api.php";
require "$root/lib/models.php";

// Si se abre desde el navegador, exige la clave
if (PHP_SAPI !== 'cli' && ($_GET['token'] ?? '') !== $cfg['cron_token']) {
    http_response_code(403);
    exit('Acceso denegado');
}
date_default_timezone_set($cfg['timezone']);
@set_time_limit(300);

$now = (int)(getenv('UMBRAL_NOW') ?: time());
$GLOBALS['UMBRAL_NOW'] = $now;
$dataDir = getenv('UMBRAL_DATA_DIR') ?: "$root/data";
if ($dataDir[0] !== '/') $dataDir = "$root/$dataDir";
define('UMBRAL_DATA', $dataDir);
define('UMBRAL_CACHE', getenv('UMBRAL_CACHE_DIR') ?: "$dataDir/cache");
if (!is_dir($dataDir)) mkdir($dataDir, 0775, true);

$startToday = strtotime('today', $now);
$endWindow = $startToday + ((int)$cfg['days_ahead'] + 1) * 86400;
$th = (float)$cfg['threshold'];

$histPath = "$dataDir/historial.json";
$history = is_file($histPath) ? (json_decode(file_get_contents($histPath), true) ?: []) : [];
// Al pasar a datos reales se descarta el historial de prueba
$isMock = !empty($cfg['mock']);
$history = array_values(array_filter($history, fn($h) => !empty($h['mock']) === $isMock));
$histIdx = [];
foreach ($history as $i => $h) $histIdx[$h['id']] = $i;

$matches = [];
$sportsOut = [];
$finalScores = [];

foreach ($cfg['sports'] as $sportKey => $sport) {
    if (isset($sport['enabled']) && !$sport['enabled']) continue;
    $any = false;
    foreach ($sport['leagues'] as $league) {
        if (empty($league['on']) || (empty($league['id']) && empty($league['code']))) continue;
        $any = true;
        $raw = api_get($cfg, $sportKey, $sport, $league);
        $games = array_values(array_filter(array_map(fn($g) => normalize($sportKey, $g, $league['name'], $sport['source'] ?? 'apisports'), $raw)));

        $finished = array_values(array_filter($games, fn($g) => in_array($g['status'], FINISHED, true) && $g['hs'] !== null && $g['ts'] < $now));
        foreach ($games as $g) {
            if (in_array($g['status'], FINISHED, true) && $g['hs'] !== null) $finalScores[$g['id']] = ['hs' => $g['hs'], 'as' => $g['as'], 'status' => 'final'];
            elseif (in_array($g['status'], VOID_STATUS, true)) $finalScores[$g['id']] = ['status' => 'void'];
        }
        $R = team_ratings($finished, $now);

        foreach ($games as $g) {
            if (!in_array($g['status'], NOT_STARTED, true)) continue;
            if ($g['ts'] < $startToday || $g['ts'] >= $endWindow) continue;
            $model = model_match($sport, $R, $g);
            if (!$model) continue;
            $pick = best_pick($model['markets'], $th);
            $draws = $sport['model'] === 'poisson_draw';
            $matches[] = [
                'id' => $g['id'], 'sport' => $sportKey, 'league' => $g['league'], 'ts' => $g['ts'],
                'home' => $g['home'], 'away' => $g['away'], 'logoH' => $g['logo_home'], 'logoA' => $g['logo_away'],
                'formH' => recent_form($finished, $g['home_id'], $draws), 'formA' => recent_form($finished, $g['away_id'], $draws),
                'exp' => $model['exp'], 'dist' => $model['dist'],
                'markets' => array_map(fn($m) => ['n' => $m['n'], 'p' => $m['p']], $model['markets']),
            ];
            // Guarda en el historial el pronóstico publicado, solo antes de que empiece el partido
            if ($pick && $g['ts'] > $now) {
                $entry = [
                    'id' => $g['id'], 'sport' => $sportKey, 'league' => $g['league'], 'ts' => $g['ts'],
                    'home' => $g['home'], 'away' => $g['away'], 'n' => $pick['n'], 'p' => $pick['p'], 'r' => $pick['r'],
                    'status' => 'pending', 'score' => null, 'mock' => $isMock,
                ];
                if (!isset($histIdx[$g['id']])) { $history[] = $entry; $histIdx[$g['id']] = count($history) - 1; }
                elseif ($history[$histIdx[$g['id']]]['status'] === 'pending') { $history[$histIdx[$g['id']]] = $entry; }
            }
        }
    }
    if ($any) $sportsOut[] = ['id' => $sportKey, 'name' => $sport['name']];
}

// Cierra pronósticos pendientes con resultado final
foreach ($history as &$h) {
    if ($h['status'] !== 'pending' || !isset($finalScores[$h['id']])) continue;
    $f = $finalScores[$h['id']];
    if ($f['status'] === 'void') { $h['status'] = 'void'; continue; }
    $h['status'] = settle($h['r'], $f['hs'], $f['as']) ? 'ok' : 'ko';
    $h['score'] = $f['hs'] . '-' . $f['as'];
}
unset($h);
// Conserva 180 días
$history = array_values(array_filter($history, fn($h) => $h['ts'] > $now - 180 * 86400));
usort($history, fn($a, $b) => $b['ts'] <=> $a['ts']);

usort($matches, fn($a, $b) => $a['ts'] <=> $b['ts']);
$out = [
    'brand' => $cfg['brand'], 'brand_short' => $cfg['brand_short'], 'contact' => $cfg['contact'],
    'updated' => $now, 'timezone' => $cfg['timezone'], 'threshold' => $th,
    'margin' => $cfg['bookmaker_margin'], 'mock' => !empty($cfg['mock']),
    'sports' => $sportsOut, 'matches' => $matches,
];
// Si la API falló y no llegó ningún partido, conserva los pronósticos anteriores
if (!$matches && !empty($GLOBALS['UMBRAL_ERRORS']) && is_file("$dataDir/pronosticos.json")) {
    log_line('Sin datos nuevos por errores de la API: se conservan los pronósticos anteriores');
} else {
file_put_contents("$dataDir/pronosticos.json.tmp", json_encode($out, JSON_UNESCAPED_UNICODE));
rename("$dataDir/pronosticos.json.tmp", "$dataDir/pronosticos.json");
}
file_put_contents("$dataDir/historial.json.tmp", json_encode($history, JSON_UNESCAPED_UNICODE));
rename("$dataDir/historial.json.tmp", "$dataDir/historial.json");

$msg = sprintf('Listo: %d partidos, %d en historial (%d pendientes)', count($matches), count($history), count(array_filter($history, fn($h) => $h['status'] === 'pending')));
log_line($msg);
echo $msg . PHP_EOL;
