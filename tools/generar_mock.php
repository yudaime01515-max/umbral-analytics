<?php
/**
 * Genera datos de prueba con el mismo formato que API-Sports, para probar la web sin gastar consultas.
 * Equipos ficticios. Uso:  php tools/generar_mock.php
 */
$root = dirname(__DIR__);
$cfg = require "$root/config.php";
date_default_timezone_set($cfg['timezone']);
mt_srand(20261106);
@mkdir("$root/mock", 0775, true);

$NAMES = [
    'futbol' => ['Deportivo Rímac','Atlético Andino','Unión Costera','Real Sierra','Sporting Pacífico','Club Cóndor','Juventud Inca','Racing Amazonas','Defensor Norte','Alianza Valle','Estudiantes del Sur','Huracán Misti','CD Titicaca','Mineros de Junín','Leones del Norte','Marítimo Callao','Atlético Chachani','Unión Selva'],
    'basquet' => ['Halcones','Toros de Sierra','Tiburones del Puerto','Pumas Urbanos','Volcanes','Cóndores','Jaguares','Truenos','Lobos Grises','Delfines','Titanes del Valle','Arqueros'],
    'beisbol' => ['Piratas','Águilas','Tigres del Caribe','Navegantes','Caimanes','Gigantes','Cardenales','Marlins del Sur','Leones','Toros'],
    'nfl' => ['Titanes','Centinelas','Vaqueros del Desierto','Osos Polares','Halcones Negros','Gladiadores','Mustangs','Corsarios','Bisontes','Relámpagos'],
    'hockey' => ['Glaciares','Lobos del Norte','Ventisca','Alces','Linces','Aurora','Bisontes','Tormenta','Castores','Halcones Blancos'],
    'voley' => ['Atenas Vóley','Delta Vóley','Brisas Vóley','Kallpa Vóley','Sierra Vóley','Faro Vóley','Costa Vóley','Inti Vóley'],
    'balonmano' => ['Andes BM','Puerto BM','Sol BM','Valle BM','Norte BM','Sur BM','Lago BM','Bosque BM'],
    'rugby' => ['Pumas RC','Cóndores RC','Lobos RC','Toros RC','Halcones RC','Osos RC','Leones RC','Zorros RC'],
];
$BASE = ['futbol' => [1.45, 1.15], 'basquet' => [114, 111], 'beisbol' => [4.5, 4.3], 'nfl' => [23, 21], 'hockey' => [3.1, 2.9], 'voley' => [0, 0], 'balonmano' => [29, 27], 'rugby' => [26, 22]];

function poi($l) { $L = exp(-$l); $k = 0; $p = 1; do { $k++; $p *= mt_rand() / mt_getrandmax(); } while ($p > $L); return $k - 1; }
function gauss() { $u = max(1e-9, mt_rand() / mt_getrandmax()); $v = mt_rand() / mt_getrandmax(); return sqrt(-2 * log($u)) * cos(2 * M_PI * $v); }

$now = time(); $teamId = 1000; $gameId = 500000;
foreach ($cfg['sports'] as $key => $sport) {
    $lg = ['id' => 900 + crc32($key) % 90, 'season' => 2026, 'name' => $sport['leagues'][0]['name'] ?? $sport['name'], 'on' => true];
    // usa el primer ID configurado para que coincida con config.php
    foreach ($sport['leagues'] as $l) { if (!empty($l['id'])) { $lg = $l; break; } }
    if (empty($lg['id'])) $lg['id'] = 900 + crc32($key) % 90;
    $teams = [];
    $spread = ['futbol' => 0.22, 'beisbol' => 0.10, 'hockey' => 0.12, 'basquet' => 0.035, 'nfl' => 0.12, 'balonmano' => 0.05, 'rugby' => 0.15, 'voley' => 0][$key];
    foreach ($NAMES[$key] as $n) $teams[] = ['id' => ++$teamId, 'name' => $n, 'logo' => '', 'att' => exp(gauss() * $spread), 'def' => exp(gauss() * $spread), 'set' => 0.5 + gauss() * 0.12];
    $games = [];
    $perDay = $key === 'futbol' ? 1.6 : 1.2;
    for ($d = -120; $d <= 4; $d++) {
        $n = (int)floor($perDay + mt_rand() / mt_getrandmax());
        $pool = $teams; shuffle($pool);
        for ($k = 0; $k < $n && 2 * $k + 1 < count($pool); $k++) {
            $h = $pool[2 * $k]; $a = $pool[2 * $k + 1];
            $ts = strtotime('today', $now) + $d * 86400 + (13 + mt_rand(0, 8)) * 3600 + [0, 1800][mt_rand(0, 1)];
            [$bh, $ba] = $BASE[$key];
            if ($key === 'voley') {
                $s = max(0.05, min(0.95, $h['set'] * (1 - $a['set']) / ($h['set'] * (1 - $a['set']) + $a['set'] * (1 - $h['set'])) + 0.02));
                $hs = $as = 0; while ($hs < 3 && $as < 3) { if (mt_rand() / mt_getrandmax() < $s) $hs++; else $as++; }
            } elseif (in_array($key, ['futbol', 'beisbol', 'hockey'])) {
                $hs = poi($bh * $h['att'] * $a['def']); $as = poi($ba * $a['att'] * $h['def']);
                if ($key !== 'futbol' && $hs == $as) { if (mt_rand(0, 1)) $hs++; else $as++; }
            } else {
                $hs = max(0, (int)round($bh * $h['att'] * $a['def'] + gauss() * ['basquet' => 9, 'nfl' => 7, 'balonmano' => 3, 'rugby' => 8][$key]));
                $as = max(0, (int)round($ba * $a['att'] * $h['def'] + gauss() * ['basquet' => 9, 'nfl' => 7, 'balonmano' => 3, 'rugby' => 8][$key]));
                if ($key !== 'balonmano' && $hs == $as) $hs++;
            }
            $gid = ++$gameId;
            $teamsBlock = ['home' => ['id' => $h['id'], 'name' => $h['name'], 'logo' => ''], 'away' => ['id' => $a['id'], 'name' => $a['name'], 'logo' => '']];
            if ($key === 'futbol') {
                $games[] = ['fixture' => ['id' => $gid, 'timestamp' => $ts, 'status' => ['short' => 'FT']], 'league' => ['id' => $lg['id'], 'name' => $lg['name']],
                    'teams' => $teamsBlock, 'goals' => ['home' => $hs, 'away' => $as], 'score' => ['fulltime' => ['home' => $hs, 'away' => $as]]];
            } elseif ($key === 'nfl') {
                $games[] = ['game' => ['id' => $gid, 'date' => ['timestamp' => $ts], 'status' => ['short' => 'FT']], 'league' => ['id' => $lg['id']],
                    'teams' => $teamsBlock, 'scores' => ['home' => ['total' => $hs], 'away' => ['total' => $as]]];
            } elseif (in_array($key, ['basquet', 'beisbol'])) {
                $games[] = ['id' => $gid, 'timestamp' => $ts, 'status' => ['short' => 'FT'], 'league' => ['id' => $lg['id']],
                    'teams' => $teamsBlock, 'scores' => ['home' => ['total' => $hs], 'away' => ['total' => $as]]];
            } else {
                $games[] = ['id' => $gid, 'timestamp' => $ts, 'status' => ['short' => 'FT'], 'league' => ['id' => $lg['id']],
                    'teams' => $teamsBlock, 'scores' => ['home' => $hs, 'away' => $as]];
            }
        }
    }
    $file = sprintf('%s-%s-%s.json', $key, $lg['id'], preg_replace('/[^0-9A-Za-z-]/', '', (string)$lg['season']));
    file_put_contents("$root/mock/$file", json_encode(['response' => $games], JSON_UNESCAPED_UNICODE));
    echo "$file: " . count($games) . " partidos\n";
}
