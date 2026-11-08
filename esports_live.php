<?php
/**
 * Umbral Analytics — marcadores en vivo de esports (cada 10 minutos).
 * Solo toca las partidas de esports que están en juego dentro de pronosticos.json:
 * actualiza el marcador de mapas y recalcula los mercados desde ese marcador.
 */
$root = __DIR__;
$cfg = require "$root/config.php";
require "$root/lib/api.php";
require "$root/lib/models.php";
require "$root/lib/esports.php";
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
date_default_timezone_set($cfg['timezone']);
$dataDir = getenv('UMBRAL_DATA_DIR') ?: "$root/data";
if ($dataDir[0] !== '/') $dataDir = "$root/$dataDir";
define('UMBRAL_DATA', $dataDir);
define('UMBRAL_CACHE', getenv('UMBRAL_CACHE_DIR') ?: "$dataDir/cache");
$path = "$dataDir/pronosticos.json";
if (!getenv('PANDASCORE_KEY') || !is_file($path)) exit("Nada que hacer\n");
$data = json_decode(file_get_contents($path), true);
$now = time();

$games = [];
foreach ($cfg['sports'] as $k => $sp) if (($sp['source'] ?? '') === 'pandascore' && !empty($sp['enabled'])) $games[$k] = $sp['game'];
// ¿Hay alguna partida de esports empezada o por empezar en los próximos 15 min?
$need = [];
foreach ($data['matches'] as $m) if (isset($games[$m['sport']]) && $m['ts'] <= $now + 900 && $m['ts'] > $now - 8 * 3600) $need[$m['sport']] = 1;
if (!$need) exit("Sin partidas de esports en juego\n");

$changed = 0;
foreach (array_keys($need) as $sk) {
    $run = ps_get('/' . $games[$sk] . '/matches/running', ['per_page' => 100]);
    if ($run === null) continue;
    $byId = [];
    foreach ($run as $r) {
        $op = $r['opponents'] ?? []; if (count($op) !== 2) continue;
        $a = (string)$op[0]['opponent']['id']; $b = (string)$op[1]['opponent']['id']; $sa = 0; $sb = 0;
        foreach ($r['results'] ?? [] as $x) { if ((string)($x['team_id'] ?? $x['player_id'] ?? '') === $a) $sa = (int)$x['score']; if ((string)($x['team_id'] ?? $x['player_id'] ?? '') === $b) $sb = (int)$x['score']; }
        $byId["$sk-ps-" . $r['id']] = [$sa, $sb];
    }
    foreach ($data['matches'] as &$m) {
        if (!isset($byId[$m['id']]) || !isset($m['mp'])) continue;
        [$sa, $sb] = $byId[$m['id']];
        if (($m['lv']['hs'] ?? -1) === $sa && ($m['lv']['as'] ?? -1) === $sb) continue;
        $m['markets'] = array_values(array_filter(ps_markets(ps_cond($m['mp'], $m['bo'], $m['bt'], $sa, $sb), $m['bo'], $m['bt'], $m['home'], $m['away']), fn($x) => $x['p'] > 0.005 && $x['p'] < 0.995));
        $m['lv'] = ['hs' => $sa, 'as' => $sb, 'txt' => 'Mapa ' . min($m['bo'], $sa + $sb + 1), 'state' => 'in'];
        $changed++;
    }
    unset($m);
}
if ($changed) {
    $data['live_updated'] = $now;
    file_put_contents("$path.tmp", json_encode($data, JSON_UNESCAPED_UNICODE));
    rename("$path.tmp", $path);
}
echo "Partidas en vivo actualizadas: $changed\n";
