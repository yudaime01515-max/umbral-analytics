<?php
/**
 * Esports (Counter-Strike 2 y Dota 2) desde PandaScore — plan gratis (calendario y resultados).
 * Necesita la clave en la variable PANDASCORE_KEY (secreto de GitHub).
 *
 * Modelo: Elo por mapa. Cada mapa ganado o perdido mueve el nivel de los dos equipos
 * (más si le ganas a alguien mejor). Con eso sale la probabilidad de ganar un mapa
 * y, según el formato (al mejor de 1, 2, 3 o 5), la de cada marcador final.
 */

function ps_get(string $path, array $q, ?int &$code = null): ?array
{
    $key = getenv('PANDASCORE_KEY');
    if (!$key) return null;
    $url = 'https://api.pandascore.co' . $path . '?' . http_build_query($q);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . $key],
        CURLOPT_USERAGENT => 'UmbralAnalytics/1.0',
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code !== 200) {
        log_line("PandaScore $path HTTP $code " . substr(preg_replace('/\s+/', ' ', (string)$body), 0, 160));
        if ($code === 401 || $code === 403) $GLOBALS['UMBRAL_ERRORS'][] = "PandaScore $code";
        return null;
    }
    $j = json_decode($body, true);
    return is_array($j) ? $j : null;
}

/** Guarda en la base un partido de PandaScore. Devuelve false si no tiene dos equipos. */
function ps_store(array &$db, array $m): bool
{
    $op = $m['opponents'] ?? [];
    if (count($op) !== 2 || empty($op[0]['opponent']['id']) || empty($op[1]['opponent']['id'])) return false;
    foreach ($op as $o) {
        $t = $o['opponent'];
        $db['teams'][(string)$t['id']] = [$t['acronym'] && strlen($t['name']) > 22 ? $t['acronym'] : $t['name'], $t['image_url'] ?? ''];
    }
    $a = (string)$op[0]['opponent']['id']; $b = (string)$op[1]['opponent']['id'];
    $sa = 0; $sb = 0;
    foreach ($m['results'] ?? [] as $r) {
        if ((string)($r['team_id'] ?? '') === $a) $sa = (int)$r['score'];
        if ((string)($r['team_id'] ?? '') === $b) $sb = (int)$r['score'];
    }
    $st = ['finished' => 'FT', 'canceled' => 'void', 'postponed' => 'NS', 'not_started' => 'NS', 'running' => 'LIVE'][$m['status'] ?? ''] ?? 'NS';
    if ($st === 'FT' && !empty($m['forfeit'])) $st = 'void';
    $ts = strtotime($m['begin_at'] ?? $m['scheduled_at'] ?? '') ?: 0;
    $bo = (int)($m['number_of_games'] ?? 1);
    $type = $m['match_type'] ?? 'best_of';
    $lg = trim(($m['league']['name'] ?? '') . ' · ' . ($m['serie']['full_name'] ?? $m['tournament']['name'] ?? ''), ' ·');
    $tier = $m['tournament']['tier'] ?? ($m['serie']['tier'] ?? null);
    $db['matches'][(string)$m['id']] = [$ts, $a, $b, $st, $sa, $sb, $bo, $type, $lg, $tier];
    return true;
}

/** Probabilidad de cada marcador en mapas. Formato al mejor de $bo, o $bo mapas jugados todos (al mejor de 2). */
function ps_scores(float $s, int $bo, string $type): array
{
    if ($type === 'all_games_played' || $bo === 2) {
        $out = [];
        for ($a = $bo; $a >= 0; $a--) $out[] = ['a' => $a, 'b' => $bo - $a, 'p' => comb($bo, $a) * $s ** $a * (1 - $s) ** ($bo - $a)];
        return $out;
    }
    return set_scores($s, max(1, $bo));
}

function esports_run(array $cfg, array $sport, string $sportKey, int $now, int $startToday, int $endWindow, string $dataDir): array
{
    $game = $sport['game']; // csgo | dota2
    $path = "$dataDir/esports_$game.json";
    $db = is_file($path) ? (json_decode(file_get_contents($path), true) ?: []) : [];
    $db += ['matches' => [], 'teams' => [], 'updated' => 0];
    $first = !$db['matches'];

    // 1) Resultados: la primera vez se leen muchas páginas para tener historia; luego solo lo último
    $pages = $first ? 12 : 2; $got = 0;
    for ($p = 1; $p <= $pages; $p++) {
        $j = ps_get("/$game/matches/past", ['sort' => '-begin_at', 'per_page' => 100, 'page' => $p]);
        if ($j === null) break;
        foreach ($j as $m) if (ps_store($db, $m)) $got++;
        if (count($j) < 100) break;
    }
    // 2) En juego y próximos
    foreach (['running', 'upcoming'] as $kind) {
        for ($p = 1; $p <= 3; $p++) {
            $j = ps_get("/$game/matches/$kind", ['sort' => 'begin_at', 'per_page' => 100, 'page' => $p]);
            if ($j === null) break;
            foreach ($j as $m) ps_store($db, $m);
            if (count($j) < 100) break;
        }
    }
    if (!$db['matches']) throw new Exception('PandaScore no devolvió partidos (¿falta PANDASCORE_KEY?)');
    // Conserva 1 año
    $db['matches'] = array_filter($db['matches'], fn($m) => $m[0] > $now - 365 * 86400);
    $db['updated'] = $now;
    file_put_contents("$path.tmp", json_encode($db, JSON_UNESCAPED_UNICODE));
    rename("$path.tmp", $path);

    // 3) Elo por mapa (los torneos grandes pesan un poco más)
    $elo = []; $n = []; $form = [];
    $fin = array_filter($db['matches'], fn($m) => $m[3] === 'FT' && $m[4] + $m[5] > 0);
    uasort($fin, fn($x, $y) => $x[0] <=> $y[0]);
    $tierW = ['s' => 1.15, 'a' => 1.05, 'b' => 1.0, 'c' => 0.9, 'd' => 0.85];
    foreach ($fin as $m) {
        [$ts, $a, $b, , $sa, $sb, , , , $tier] = $m;
        $w = $tierW[strtolower((string)$tier)] ?? 1.0;
        for ($k = 0; $k < $sa + $sb; $k++) {
            $res = $k < $sa ? 1 : 0; // el orden de los mapas no importa para el nivel final
            $ea = $elo[$a] ?? 1500.0; $eb = $elo[$b] ?? 1500.0;
            $exp = 1 / (1 + 10 ** (($eb - $ea) / 400));
            $ka = $w * 64 / ((($n[$a] ?? 0) + 4) ** 0.3); $kb = $w * 64 / ((($n[$b] ?? 0) + 4) ** 0.3);
            $elo[$a] = $ea + $ka * ($res - $exp); $elo[$b] = $eb + $kb * ((1 - $res) - (1 - $exp));
            $n[$a] = ($n[$a] ?? 0) + 1; $n[$b] = ($n[$b] ?? 0) + 1;
        }
        $form[$a][] = $sa > $sb ? 'w' : ($sa < $sb ? 'l' : 'd'); $form[$b][] = $sb > $sa ? 'w' : ($sb < $sa ? 'l' : 'd');
    }

    // 4) Resultados para cerrar pronósticos y partidos por jugarse
    $final = []; $out = [];
    $unit = $sport['unit'] ?? 'mapas';
    foreach ($db['matches'] as $id => $m) {
        [$ts, $a, $b, $st, $sa, $sb, $bo, $type, $lg, $tier] = $m;
        $mid = "$sportKey-ps-$id";
        if ($st === 'FT') $final[$mid] = ['hs' => $sa, 'as' => $sb, 'status' => 'final'];
        elseif ($st === 'void') $final[$mid] = ['status' => 'void'];
        if ($st !== 'NS' || $ts < $startToday || $ts >= $endWindow || $ts < $now - 1800) continue;
        // Sin historia suficiente de alguno de los dos, no hay base para calcular
        if (($n[$a] ?? 0) < 6 || ($n[$b] ?? 0) < 6) continue;
        $ea = $elo[$a]; $eb = $elo[$b];
        $s = clampf(1 / (1 + 10 ** (($eb - $ea) / 400)), 0.04, 0.96);
        $H = $db['teams'][$a][0] ?? '?'; $A = $db['teams'][$b][0] ?? '?';
        $bo = max(1, $bo); $d = ps_scores($s, $bo, $type);
        $markets = [];
        if ($type === 'all_games_played' || $bo === 2) {
            $pH = 0; $pA = 0; $pD = 0;
            foreach ($d as $x) { if ($x['a'] > $x['b']) $pH += $x['p']; elseif ($x['a'] < $x['b']) $pA += $x['p']; else $pD += $x['p']; }
            $markets = [mk("$H gana $bo-0", $pH, ['res', 'home']), mk('Empate ' . ($bo / 2) . '-' . ($bo / 2), $pD, ['res', 'draw']), mk("$A gana $bo-0", $pA, ['res', 'away']),
                        mk("$H gana al menos un mapa", 1 - $pA, ['sets_one', 'home']), mk("$A gana al menos un mapa", 1 - $pH, ['sets_one', 'away'])];
        } else {
            $need = intdiv($bo + 1, 2); $pH = 0; $hClean = 0; $aClean = 0; $short = 0; $hQ = 0; $aQ = 0;
            foreach ($d as $x) {
                if ($x['a'] == $need) $pH += $x['p'];
                if ($x['a'] == $need && $x['b'] == 0) $hClean = $x['p'];
                if ($x['b'] == $need && $x['a'] == 0) $aClean = $x['p'];
                if ($x['a'] == $need && $x['b'] <= $need - 2) $hQ += $x['p'];
                if ($x['b'] == $need && $x['a'] <= $need - 2) $aQ += $x['p'];
                if ($x['a'] + $x['b'] == $need) $short += $x['p'];
            }
            $markets = [mk("Gana $H", $pH, ['res', 'home']), mk("Gana $A", 1 - $pH, ['res', 'away'])];
            if ($bo >= 3) {
                $markets[] = mk("$H gana al menos un mapa", 1 - $aClean, ['sets_one', 'home']);
                $markets[] = mk("$A gana al menos un mapa", 1 - $hClean, ['sets_one', 'away']);
                $ln = $need + 0.5;
                $markets[] = mk("Menos de $ln mapas", $short, ['tot', 'under', $ln]);
                $markets[] = mk("Más de $ln mapas", 1 - $short, ['tot', 'over', $ln]);
                $markets[] = mk("$H -1.5 mapas", $hQ, ['hcp', 'home', -1.5]);
                $markets[] = mk("$A -1.5 mapas", $aQ, ['hcp', 'away', -1.5]);
            }
        }
        $fmt = $type === 'all_games_played' || $bo === 2 ? "$bo mapas" : "al mejor de $bo";
        $out[] = [
            'id' => $mid, 'sport' => $sportKey, 'league' => $lg . ' · ' . $fmt, 'ts' => $ts,
            'home' => $H, 'away' => $A, 'logoH' => $db['teams'][$a][1] ?? '', 'logoA' => $db['teams'][$b][1] ?? '',
            'hid' => $a, 'aid' => $b,
            'formH' => array_reverse(array_slice($form[$a] ?? [], -5)), 'formA' => array_reverse(array_slice($form[$b] ?? [], -5)),
            'exp' => [["Prob. de ganar un mapa ($H)", round($s * 100) . '%'], ['Nivel (Elo)', round($ea) . ' vs ' . round($eb)],
                      ['Mapas jugados en la base', ($n[$a] ?? 0) . ' vs ' . ($n[$b] ?? 0)]],
            'dist' => ['kind' => 'bars', 'title' => "Marcador final en mapas ($H primero)", 'data' => array_map(fn($x) => ['label' => $x['a'] . '-' . $x['b'], 'p' => round($x['p'], 4)], $d), 'mark' => null],
            'markets' => $markets,
        ];
    }
    log_line("OK $sportKey: " . count($fin) . " partidos jugados en la base, $got leídos ahora, " . count($out) . ' por jugarse');
    return ['matches' => $out, 'final' => $final];
}
