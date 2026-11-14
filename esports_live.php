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
// ¿Hay alguna partida de esports empezada o por empezar en los próximos 40 min?
$need = [];
foreach ($data['matches'] as $m) if (isset($games[$m['sport']]) && $m['ts'] <= $now + 2400 && $m['ts'] > $now - 8 * 3600) $need[$m['sport']] = 1;
if (!$need) exit("Sin partidas de esports en juego\n");

$changed = 0; $enJuego = 0;
$score = function (array $r): ?array {
    $op = $r['opponents'] ?? []; if (count($op) !== 2) return null;
    $a = (string)$op[0]['opponent']['id']; $b = (string)$op[1]['opponent']['id']; $sa = 0; $sb = 0;
    foreach ($r['results'] ?? [] as $x) { $t = (string)($x['team_id'] ?? $x['player_id'] ?? ''); if ($t === $a) $sa = (int)$x['score']; if ($t === $b) $sb = (int)$x['score']; }
    return [$sa, $sb];
};
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
        [$sa, $sb] = $byId[$m['id']]; $enJuego++;
        if (($m['lv']['hs'] ?? -1) === $sa && ($m['lv']['as'] ?? -1) === $sb) continue;
        $m['markets'] = array_values(array_filter(ps_markets(ps_cond($m['mp'], $m['bo'], $m['bt'], $sa, $sb), $m['bo'], $m['bt'], $m['home'], $m['away']), fn($x) => $x['p'] > 0.005 && $x['p'] < 0.995));
        $m['lv'] = ['hs' => $sa, 'as' => $sb, 'txt' => 'Mapa ' . min($m['bo'], $sa + $sb + 1), 'state' => 'in'];
        $changed++;
    }
    unset($m);

    // Partidas que ya empezaron y ya no figuran "en juego": se pregunta si terminaron, para cerrarlas en la página
    $pend = [];
    foreach ($data['matches'] as $i => $m) {
        if ($m['sport'] !== $sk || isset($byId[$m['id']]) || ($m['lv']['state'] ?? '') === 'post') continue;
        if ($m['ts'] > $now - 600 || $m['ts'] < $now - 8 * 3600) continue;
        $pend[(string)preg_replace('/^.*-ps-/', '', $m['id'])] = $i;
    }
    if ($pend) {
        $res = ps_get('/' . $games[$sk] . '/matches', ['filter[id]' => implode(',', array_keys($pend)), 'per_page' => 100]);
        foreach ($res ?? [] as $r) {
            $i = $pend[(string)($r['id'] ?? '')] ?? null;
            if ($i === null || !in_array($r['status'] ?? '', ['finished', 'canceled'], true)) continue;
            $sc = $score($r) ?? [0, 0];
            $data['matches'][$i]['lv'] = ['hs' => $sc[0], 'as' => $sc[1], 'txt' => ($r['status'] === 'finished' ? 'Final' : 'Cancelado'), 'state' => 'post'];
            $changed++;
        }
    }
}
// Se guarda si algo cambió, o cada ~9 min mientras haya partidas en juego (así la página sabe que el marcador está revisado)
if ($changed || ($enJuego && $now - (int)($data['live_checked'] ?? 0) >= 540)) {
    $data['live_checked'] = $now;
    if ($changed) $data['live_updated'] = $now;
    file_put_contents("$path.tmp", json_encode($data, JSON_UNESCAPED_UNICODE));
    rename("$path.tmp", $path);
}
echo "Partidas en vivo actualizadas: $changed (en juego: $enJuego)\n";
