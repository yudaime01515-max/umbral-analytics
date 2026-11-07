<?php
/**
 * Tenis (ATP y WTA, individuales) desde ESPN, API pública no oficial y sin clave.
 * - Guarda los partidos en public/data/tenis_db.json (para no volver a pedir días viejos).
 * - Calcula el nivel de cada jugador con un sistema Elo: arranca desde su ranking
 *   y se ajusta con cada partido que gana o pierde.
 * - Convierte la diferencia de nivel en probabilidad de ganar el partido y cada set.
 */

function tenis_base(string $tour): string
{
    $b = getenv('ESPN_TENNIS_BASE') ?: 'https://site.api.espn.com/apis/site/v2/sports/tennis';
    return "$b/$tour";
}

/** Busca listas de ranking dentro de la respuesta, sea cual sea su forma exacta. */
function tenis_rank_walk($node, array &$out): void
{
    if (!is_array($node)) return;
    if (isset($node['current']) && isset($node['athlete']['id'])) {
        $out[(string)$node['athlete']['id']] = (int)$node['current'];
        return;
    }
    foreach ($node as $v) if (is_array($v)) tenis_rank_walk($v, $out);
}

/** Partidos de individuales de un día. Devuelve null si ESPN no respondió. */
function tenis_day(string $tour, string $ymd, array &$players): ?array
{
    $j = espn_fetch(tenis_base($tour) . "/scoreboard?dates=$ymd");
    if ($j === null) { usleep(500000); $j = espn_fetch(tenis_base($tour) . "/scoreboard?dates=$ymd"); }
    if ($j === null) return null;
    $out = [];
    foreach ($j['events'] ?? [] as $ev) {
        $tname = $ev['name'] ?? $ev['shortName'] ?? 'Torneo';
        $groups = $ev['groupings'] ?? [];
        if (!$groups && !empty($ev['competitions'])) $groups = [['grouping' => [], 'competitions' => $ev['competitions']]];
        foreach ($groups as $gr) {
            $gname = strtolower(($gr['grouping']['displayName'] ?? '') . ' ' . ($gr['grouping']['slug'] ?? ''));
            if (trim($gname) !== '' && strpos($gname, 'single') === false) continue; // solo individuales
            foreach ($gr['competitions'] ?? [] as $c) {
                $cs = $c['competitors'] ?? [];
                if (count($cs) !== 2) continue;
                usort($cs, fn($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));
                $ids = []; $sets = [0, 0]; $ok = true;
                foreach ($cs as $k => $p) {
                    if (!empty($p['roster'])) { $ok = false; break; } // dobles
                    $a = $p['athlete'] ?? [];
                    $id = (string)($a['id'] ?? $p['id'] ?? '');
                    $name = $a['displayName'] ?? $a['fullName'] ?? '';
                    if ($id === '' || $name === '' || stripos($name, 'TBD') !== false) { $ok = false; break; }
                    $ids[$k] = $id;
                    $players[$id] = [$name, $a['flag']['href'] ?? ($players[$id][1] ?? '')];
                }
                if (!$ok) continue;
                $l0 = $cs[0]['linescores'] ?? []; $l1 = $cs[1]['linescores'] ?? [];
                $n = min(count($l0), count($l1));
                for ($i = 0; $i < $n; $i++) {
                    $a = (int)($l0[$i]['value'] ?? 0); $b = (int)($l1[$i]['value'] ?? 0);
                    // set terminado: alguien llegó a 6 con 2 de diferencia, o 7
                    if (($a >= 6 || $b >= 6) && (abs($a - $b) >= 2 || max($a, $b) >= 7)) { if ($a > $b) $sets[0]++; else $sets[1]++; }
                }
                $st = $c['status']['type'] ?? [];
                $desc = strtolower(($st['description'] ?? '') . ' ' . ($st['name'] ?? '') . ' ' . ($st['detail'] ?? ''));
                $state = $st['state'] ?? 'pre';
                if (preg_match('/cancel|postpon|suspend/', $desc)) $status = 'void';
                elseif (!empty($st['completed']) || $state === 'post') {
                    $status = preg_match('/retir|walkover|w\/o|default|abandon/', $desc) ? 'void' : 'FT';
                    if ($status === 'FT' && $sets[0] === $sets[1]) {
                        // por si el marcador vino incompleto: usa la bandera de ganador
                        if (!empty($cs[0]['winner'])) $sets[0]++; elseif (!empty($cs[1]['winner'])) $sets[1]++; else $status = 'void';
                    }
                }
                elseif ($state === 'in') $status = 'LIVE';
                else $status = 'NS';
                $out[(string)$c['id']] = [
                    (int)strtotime($c['date'] ?? $c['startDate'] ?? $ev['date'] ?? ''), $tour, $ids[0], $ids[1],
                    $status, $sets[0], $sets[1], $tname, $c['round']['displayName'] ?? '',
                ];
            }
        }
    }
    return $out;
}

/** Probabilidad de ganar un set a partir de la de ganar el partido (al mejor de $bo). */
function tenis_set_prob(float $pm, int $bo): float
{
    $lo = 0.0; $hi = 1.0;
    for ($i = 0; $i < 50; $i++) {
        $s = ($lo + $hi) / 2;
        $p = 0; foreach (set_scores($s, $bo) as $x) if ($x['a'] > $x['b']) $p += $x['p'];
        if ($p < $pm) $lo = $s; else $hi = $s;
    }
    return ($lo + $hi) / 2;
}

/**
 * Hace todo el tenis de una ejecución.
 * Devuelve ['matches' => [...formato común...], 'final' => [id => resultado], 'count' => n]
 */
function tenis_run(array $cfg, array $sport, int $now, int $startToday, int $endWindow, string $dataDir): array
{
    $path = "$dataDir/tenis_db.json";
    $db = is_file($path) ? (json_decode(file_get_contents($path), true) ?: []) : [];
    $db += ['days' => [], 'matches' => [], 'players' => [], 'rank' => []];
    $tours = array_map(fn($l) => $l['code'], array_filter($sport['leagues'], fn($l) => !empty($l['on'])));
    $daysDone = array_flip($db['days']);

    // 1) Ranking actual (punto de partida del nivel de cada jugador)
    foreach ($tours as $tour) {
        $j = espn_fetch(tenis_base($tour) . '/rankings');
        if ($j) { $r = []; tenis_rank_walk($j, $r); if ($r) $db['rank'][$tour] = $r; }
        usleep(250000);
    }

    // 2) Días a pedir: siempre los próximos y los recientes; luego historial hacia atrás
    $from = (int)(getenv('TENIS_FROM') ? strtotime(getenv('TENIS_FROM')) : $now - 120 * 86400);
    $fresh = []; $old = [];
    for ($d = $startToday - 3 * 86400; $d <= $endWindow; $d += 86400) $fresh[] = gmdate('Ymd', $d);
    for ($d = $startToday - 4 * 86400; $d >= $from; $d -= 86400) { $k = gmdate('Ymd', $d); if (!isset($daysDone[$k])) $old[] = $k; }
    $budget = (int)(getenv('TENIS_MAX_DAYS') ?: 70); // días de historial por ejecución y por circuito
    $fails = 0; $read = 0;
    foreach ($tours as $tour) {
        $list = array_merge($fresh, array_slice($old, 0, $budget));
        foreach ($list as $ymd) {
            $got = tenis_day($tour, $ymd, $db['players']);
            usleep((int)(getenv('ESPN_PAUSE_MS') ?: 250) * 1000);
            if ($got === null) { if (++$fails >= 6) break 2; continue; }
            $read++;
            foreach ($got as $id => $m) $db['matches'][$id] = $m;
            if (!in_array($ymd, $fresh, true)) $daysDone["$tour:$ymd"] = 1;
        }
    }
    // un día viejo queda listo cuando ambos circuitos lo leyeron
    foreach ($old as $ymd) {
        $all = true; foreach ($tours as $t) if (!isset($daysDone["$t:$ymd"])) $all = false;
        if ($all) $daysDone[$ymd] = 1;
    }
    // Conserva 1 año de partidos
    $db['matches'] = array_filter($db['matches'], fn($m) => $m[0] > $now - 365 * 86400);
    $db['days'] = array_keys($daysDone);
    $db['updated'] = $now;
    file_put_contents("$path.tmp", json_encode($db, JSON_UNESCAPED_UNICODE));
    rename("$path.tmp", $path);
    if ($fails && !$read) {
        $GLOBALS['UMBRAL_ERRORS'] = ($GLOBALS['UMBRAL_ERRORS'] ?? 0) + 1;
        log_line('ERROR tenis (ESPN): ' . ($GLOBALS['ESPN_LAST'] ?? 'sin respuesta'));
    }

    // 3) Nivel Elo de cada jugador
    $elo = []; $n = []; $form = [];
    $prior = function (string $id, string $tour) use ($db): float {
        $r = $db['rank'][$tour][$id] ?? null;
        return $r ? 1900 - 110 * log(max(1, $r)) : 1300.0;
    };
    $fin = array_filter($db['matches'], fn($m) => $m[4] === 'FT');
    uasort($fin, fn($a, $b) => $a[0] <=> $b[0]);
    foreach ($fin as $m) {
        [$ts, $tour, $a, $b, , $sa, $sb] = $m;
        $ea = $elo[$a] ?? $prior($a, $tour); $eb = $elo[$b] ?? $prior($b, $tour);
        $exp = 1 / (1 + 10 ** (($eb - $ea) / 400));
        $res = $sa > $sb ? 1 : 0;
        $ka = 250 / ((($n[$a] ?? 0) + 5) ** 0.4); $kb = 250 / ((($n[$b] ?? 0) + 5) ** 0.4);
        $elo[$a] = $ea + $ka * ($res - $exp); $elo[$b] = $eb + $kb * ((1 - $res) - (1 - $exp));
        $n[$a] = ($n[$a] ?? 0) + 1; $n[$b] = ($n[$b] ?? 0) + 1;
        $form[$a][] = $res ? 'w' : 'l'; $form[$b][] = $res ? 'l' : 'w';
    }

    // 4) Resultados para cerrar pronósticos y partidos próximos
    $final = []; $out = [];
    foreach ($db['matches'] as $id => $m) {
        [$ts, $tour, $a, $b, $st, $sa, $sb, $tname, $round] = $m;
        $mid = "tenis-espn-$id";
        if ($st === 'FT') $final[$mid] = ['hs' => $sa, 'as' => $sb, 'status' => 'final'];
        elseif ($st === 'void') $final[$mid] = ['status' => 'void'];
        if ($st !== 'NS' || $ts < $startToday || $ts >= $endWindow) continue;
        // Sin partidos jugados ni ranking no hay base para calcular
        if (!isset($elo[$a]) && !isset($db['rank'][$tour][$a])) continue;
        if (!isset($elo[$b]) && !isset($db['rank'][$tour][$b])) continue;
        $ea = $elo[$a] ?? $prior($a, $tour); $eb = $elo[$b] ?? $prior($b, $tour);
        $pm = 1 / (1 + 10 ** (($eb - $ea) / 400));
        $bo = ($tour === 'atp' && preg_match('/australian open|roland|french open|wimbledon|us open/i', $tname)) ? 5 : 3;
        $s = clampf(tenis_set_prob(clampf($pm, 0.02, 0.98), $bo), 0.03, 0.97);
        $H = $db['players'][$a][0] ?? '?'; $A = $db['players'][$b][0] ?? '?';
        $d = set_scores($s, $bo); $need = intdiv($bo + 1, 2);
        $pH = 0; $hClean = 0; $aClean = 0; $short = 0; $hQuick = 0; $aQuick = 0;
        foreach ($d as $x) {
            if ($x['a'] == $need) $pH += $x['p'];
            if ($x['a'] == $need && $x['b'] == 0) $hClean = $x['p'];
            if ($x['b'] == $need && $x['a'] == 0) $aClean = $x['p'];
            if ($x['a'] == $need && $x['b'] <= $need - 2) $hQuick += $x['p'];
            if ($x['b'] == $need && $x['a'] <= $need - 2) $aQuick += $x['p'];
            if ($x['a'] + $x['b'] == $need) $short += $x['p'];
        }
        $markets = [
            mk("Gana $H", $pH, ['res', 'home']), mk("Gana $A", 1 - $pH, ['res', 'away']),
            mk("$H gana al menos un set", 1 - $aClean, ['sets_one', 'home']), mk("$A gana al menos un set", 1 - $hClean, ['sets_one', 'away']),
        ];
        if ($bo === 3) {
            $markets[] = mk('Menos de 2.5 sets', $short, ['tot', 'under', 2.5]);
            $markets[] = mk('Más de 2.5 sets', 1 - $short, ['tot', 'over', 2.5]);
            $markets[] = mk("$H gana 2-0", $hClean, ['hcp', 'home', -1.5]);
            $markets[] = mk("$A gana 2-0", $aClean, ['hcp', 'away', -1.5]);
        } else {
            $markets[] = mk('Menos de 3.5 sets', $short, ['tot', 'under', 3.5]);
            $markets[] = mk('Más de 3.5 sets', 1 - $short, ['tot', 'over', 3.5]);
            $markets[] = mk("$H -1.5 sets", $hQuick, ['hcp', 'home', -1.5]);
            $markets[] = mk("$A -1.5 sets", $aQuick, ['hcp', 'away', -1.5]);
        }
        $rk = fn($p) => isset($db['rank'][$tour][$p]) ? ' (#' . $db['rank'][$tour][$p] . ')' : '';
        $out[] = [
            'id' => $mid, 'sport' => 'tenis', 'league' => strtoupper($tour) . ' · ' . $tname . ($round ? " · $round" : ''), 'ts' => $ts,
            'home' => $H, 'away' => $A, 'logoH' => $db['players'][$a][1] ?? '', 'logoA' => $db['players'][$b][1] ?? '',
            'hid' => $a, 'aid' => $b,
            'formH' => array_reverse(array_slice($form[$a] ?? [], -5)), 'formA' => array_reverse(array_slice($form[$b] ?? [], -5)),
            'exp' => [["Prob. de ganar un set ($H)", round($s * 100) . '%'], ['Nivel (Elo)', round($ea) . $rk($a) . ' vs ' . round($eb) . $rk($b)]],
            'dist' => ['kind' => 'bars', 'title' => "Marcador final en sets ($H primero)", 'data' => array_map(fn($x) => ['label' => $x['a'] . '-' . $x['b'], 'p' => round($x['p'], 4)], $d), 'mark' => null],
            'markets' => $markets,
        ];
    }
    log_line('OK tenis: ' . count($fin) . ' partidos jugados en la base, ' . count($out) . " por jugarse ($read días leídos)");
    return ['matches' => $out, 'final' => $final];
}
