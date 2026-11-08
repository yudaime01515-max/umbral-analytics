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
require "$root/lib/espn.php";
require "$root/lib/espn_futbol.php";
require "$root/lib/tenis.php";
require "$root/lib/espn_ligas.php";

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
$futCodes = [];
$futTs = [];
$fullMk = [];
$sportsOut = [];
$finalScores = [];

foreach ($cfg['sports'] as $sportKey => $sport) {
    if (isset($sport['enabled']) && !$sport['enabled']) continue;
    $any = false;
    // Tenis: va por su propio camino (jugadores en vez de equipos)
    if (($sport['source'] ?? '') === 'espn_tenis') {
        try { $tn = tenis_run($cfg, $sport, $now, $startToday, $endWindow, $dataDir); }
        catch (Throwable $e) { log_line('ERROR tenis: ' . $e->getMessage()); continue; }
        foreach ($tn['final'] as $fid => $fv) $finalScores[$fid] = $fv;
        foreach ($tn['matches'] as $tm) {
            $matches[] = $tm;
            $pick = best_pick($tm['markets'], $th);
            if ($pick && $tm['ts'] > $now) {
                $entry = [
                    'id' => $tm['id'], 'sport' => $sportKey, 'league' => $tm['league'], 'ts' => $tm['ts'],
                    'home' => $tm['home'], 'away' => $tm['away'], 'n' => $pick['n'], 'p' => $pick['p'], 'r' => $pick['r'],
                    'status' => 'pending', 'score' => null, 'mock' => $isMock,
                ];
                if (!isset($histIdx[$tm['id']])) { $history[] = $entry; $histIdx[$tm['id']] = count($history) - 1; }
                elseif ($history[$histIdx[$tm['id']]]['status'] === 'pending') { $history[$histIdx[$tm['id']]] = $entry; }
            }
        }
        $sportsOut[] = ['id' => $sportKey, 'name' => $sport['name']];
        continue;
    }
    foreach ($sport['leagues'] as $league) {
        if (empty($league['on']) || (empty($league['id']) && empty($league['code']))) continue;
        $any = true;
        $raw = api_get($cfg, $sportKey, $sport, $league);
        if (($sport['source'] ?? '') === 'espn' && !empty($sport['players'])) $nbaEvents = $raw;
        $isFut = $sportKey === 'futbol' && !empty($league['code']) && (($sport['source'] ?? '') === 'footballdata' || ($league['src'] ?? '') === 'espn');
        if ($isFut && !empty($sport['players'])) $futCodes[] = $league['code'];
        $futRaw = $isFut ? $league['code'] : null;
        $src = ($league['src'] ?? '') === 'espn' ? 'espn_norm' : ($sport['source'] ?? 'apisports');
        $games = array_values(array_filter(array_map(fn($g) => normalize($sportKey, $g, $league['name'], $src), $raw)));

        $finished = array_values(array_filter($games, fn($g) => in_array($g['status'], FINISHED, true) && $g['hs'] !== null && $g['ts'] < $now));
        if ($futRaw) $futTs[$futRaw] = array_column($finished, 'ts');
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
            $fullMk[$g['id']] = $model['markets'];
            $draws = $sport['model'] === 'poisson_draw';
            $matches[] = [
                'id' => $g['id'], 'sport' => $sportKey, 'league' => $g['league'], 'ts' => $g['ts'],
                'home' => $g['home'], 'away' => $g['away'], 'logoH' => $g['logo_home'], 'logoA' => $g['logo_away'],
                'hid' => $g['home_id'], 'aid' => $g['away_id'],
                'lgc' => $league['code'] ?? null, 'hf' => $g['home_full'] ?? '', 'af' => $g['away_full'] ?? '',
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

// Estadísticas de jugadores NBA (ESPN). Si falla, la web sigue con lo anterior.
if (!empty($nbaEvents)) {
    try { nba_players_update($cfg, $nbaEvents, $dataDir); }
    catch (Throwable $e) { log_line('ERROR jugadores NBA: ' . $e->getMessage()); }
}

// Estadísticas de jugadores de fútbol (ESPN) y enlace de cada partido con sus equipos
if (!empty($futCodes)) {
    try {
        $futTeams = futbol_players_update($cfg, array_unique($futCodes), $dataDir, $futTs);
        foreach ($matches as &$m) {
            if ($m['sport'] !== 'futbol' || empty($futTeams[$m['lgc']])) continue;
            $eh = club_match($m['hf'] ?: $m['home'], $futTeams[$m['lgc']]);
            $ea = club_match($m['af'] ?: $m['away'], $futTeams[$m['lgc']]);
            if ($eh && $ea && $eh !== $ea) $m['et'] = [$eh, $ea];
            // Córners, tarjetas y remates (con las estadísticas de equipo de ESPN)
            if (!empty($m['et']) && !empty($GLOBALS['FUT_TM'][$m['lgc']])) {
                $extra = fut_stat_markets($m, $GLOBALS['FUT_TM'][$m['lgc']]);
                if ($extra) {
                    $m['markets'] = array_merge($m['markets'], array_map(fn($x) => ['n' => $x['n'], 'p' => $x['p']], $extra['markets']));
                    $lbl = ['c' => 'Córners esperados', 'y' => 'Amarillas esperadas', 'sot' => 'Remates a portería esperados', 'f' => 'Faltas esperadas'];
                    foreach ($lbl as $k => $l) if (isset($extra['exp'][$k])) $m['exp'][] = [$l, number_format($extra['exp'][$k][0], 1) . ' (' . number_format($extra['exp'][$k][1], 1) . ' – ' . number_format($extra['exp'][$k][2], 1) . ')'];
                    // el pronóstico publicado puede cambiar con los mercados nuevos
                    $pick = best_pick(array_merge($fullMk[$m['id']] ?? [], $extra['markets']), $th);
                    if ($pick && $m['ts'] > $now) {
                        $entry = ['id' => $m['id'], 'sport' => 'futbol', 'league' => $m['league'], 'ts' => $m['ts'],
                            'home' => $m['home'], 'away' => $m['away'], 'n' => $pick['n'], 'p' => $pick['p'], 'r' => $pick['r'],
                            'status' => 'pending', 'score' => null, 'mock' => $isMock, 'et' => $m['et'], 'lgc' => $m['lgc']];
                        if (!isset($histIdx[$m['id']])) { $history[] = $entry; $histIdx[$m['id']] = count($history) - 1; }
                        elseif ($history[$histIdx[$m['id']]]['status'] === 'pending') $history[$histIdx[$m['id']]] = $entry;
                    }
                }
            }
        }
        unset($m);
    } catch (Throwable $e) { log_line('ERROR jugadores fútbol: ' . $e->getMessage()); }
}

// Cierra pronósticos pendientes con resultado final
foreach ($history as &$h) {
    if ($h['status'] !== 'pending') continue;
    // Mercados de córners/tarjetas/remates: se cierran con las estadísticas de ESPN
    if (($h['r'][0] ?? '') === 'st') {
        if (isset($finalScores[$h['id']]) && $finalScores[$h['id']]['status'] === 'void') { $h['status'] = 'void'; continue; }
        $row = (!empty($h['et']) && !empty($GLOBALS['FUT_TM'][$h['lgc'] ?? ''])) ? fut_final_stats($GLOBALS['FUT_TM'][$h['lgc']], $h['et'], $h['ts']) : null;
        $res = $row ? settle_stat($h['r'], $row) : null;
        if ($res !== null) {
            $h['status'] = $res ? 'ok' : 'ko';
            $i = FUT_STAT[$h['r'][1]][0];
            $h['score'] = $row[3][$i] . '-' . $row[4][$i] . ' ' . FUT_STAT[$h['r'][1]][1];
        } elseif ($h['ts'] < $now - 6 * 86400) $h['status'] = 'void'; // sin dato tras 6 días: se anula
        continue;
    }
    if (!isset($finalScores[$h['id']])) continue;
    $f = $finalScores[$h['id']];
    if ($f['status'] === 'void') { $h['status'] = 'void'; continue; }
    $h['status'] = settle($h['r'], $f['hs'], $f['as']) ? 'ok' : 'ko';
    $h['score'] = $f['hs'] . '-' . $f['as'];
}
unset($h);
// Conserva 180 días
$history = array_values(array_filter($history, fn($h) => $h['ts'] > $now - 180 * 86400));
usort($history, fn($a, $b) => $b['ts'] <=> $a['ts']);

// Partidos en juego: la API ya no los marca como "no empezado", así que se recuperan de la versión anterior
// y se mantienen hasta que terminan (o hasta un tiempo máximo por deporte), para que la web los siga mostrando en vivo.
$prevPath = "$dataDir/pronosticos.json";
if (empty($cfg['mock']) && is_file($prevPath)) {
    $prev = json_decode(file_get_contents($prevPath), true) ?: [];
    $dur = ['futbol' => 2.6, 'basquet' => 3.4, 'tenis' => 4.5];
    $have = array_flip(array_column($matches, 'id'));
    foreach ($prev['matches'] ?? [] as $pm) {
        if (!isset($pm['id'], $pm['ts']) || isset($have[$pm['id']])) continue;
        if (isset($finalScores[$pm['id']])) continue; // ya terminó o se anuló
        $end = $pm['ts'] + (int)(($dur[$pm['sport'] ?? ''] ?? 3.5) * 3600);
        if ($pm['ts'] <= $now && $end > $now) { $matches[] = $pm; $have[$pm['id']] = 1; }
    }
}

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
