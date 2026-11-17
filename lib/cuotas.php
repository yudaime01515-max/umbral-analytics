<?php
/**
 * Cuotas reales (The Odds API, https://the-odds-api.com). Solo se activa si existe ODDS_API_KEY.
 * Sirve para dos cosas: saber si una casa de verdad ofrece el mercado, y mostrar su cuota.
 *
 * El plan gratis da 500 créditos al mes. Cada consulta (h2h + totals, 1 región) gasta 2 créditos,
 * así que cada liga se consulta como máximo una vez cada 'odds_ttl_hours' horas (por defecto 24),
 * solo si juega en las próximas 48 h y sin pasar de 'odds_creditos_dia' créditos diarios.
 * Las cuotas se guardan en public/data/cuotas/ para que el límite se respete entre una actualización y otra
 * (antes se guardaban en una carpeta temporal que se borraba cada hora: el cupo del mes se iba en un día).
 */

const ODDS_KEYS = [
    'PL' => 'soccer_epl', 'PD' => 'soccer_spain_la_liga', 'SA' => 'soccer_italy_serie_a', 'BL1' => 'soccer_germany_bundesliga',
    'FL1' => 'soccer_france_ligue_one', 'CL' => 'soccer_uefa_champs_league', 'PPL' => 'soccer_portugal_primeira_liga',
    'DED' => 'soccer_netherlands_eredivisie', 'ELC' => 'soccer_efl_champ', 'ARG' => 'soccer_argentina_primera_division',
    'BRA' => 'soccer_brazil_campeonato', 'MEX' => 'soccer_mexico_ligamx', 'MLS' => 'soccer_usa_mls',
    'UEL' => 'soccer_uefa_europa_league', 'UECL' => 'soccer_uefa_europa_conference_league',
    'LIB' => 'soccer_conmebol_copa_libertadores', 'SUD' => 'soccer_conmebol_copa_sudamericana',
    'NBA' => 'basketball_nba', 'NFL' => 'americanfootball_nfl', 'NHL' => 'icehockey_nhl', 'UFC' => 'mma_mixed_martial_arts',
];
const ODDS_SOLO_GANADOR = ['UFC'];   // ligas donde solo se pide el ganador (1 crédito en vez de 2)

function odds_activo(): bool { return (string)(getenv('ODDS_API_KEY') ?: '') !== ''; }

/** Carpeta donde se guardan las cuotas. Va dentro de los datos para que se conserve entre una actualización y otra. */
function odds_dir(): string
{
    $dir = (defined('UMBRAL_DATA') ? UMBRAL_DATA : sys_get_temp_dir()) . '/cuotas';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    return $dir;
}

/** Contador de créditos del día (UTC) y pausa si la cuenta se quedó sin cupo. */
function odds_estado(?array $set = null): array
{
    static $st = null;
    $file = odds_dir() . '/_estado.json';
    if ($st === null) $st = is_file($file) ? (json_decode((string)file_get_contents($file), true) ?: []) : [];
    $hoy = gmdate('Y-m-d');
    if (($st['dia'] ?? '') !== $hoy) { $st['dia'] = $hoy; $st['usados'] = 0; }
    if ($set !== null) { $st = array_merge($st, $set); file_put_contents($file, json_encode($st)); }
    return $st;
}

/** Guarda solo lo que se usa de cada evento (equipos, hora y precios): el archivo queda pequeño. */
function odds_compacto(array $data): array
{
    $out = [];
    foreach ($data as $e) {
        $bs = [];
        foreach ($e['bookmakers'] ?? [] as $b) {
            $ms = [];
            foreach ($b['markets'] ?? [] as $m) {
                $os = [];
                foreach ($m['outcomes'] ?? [] as $o) $os[] = array_filter(['name' => $o['name'] ?? '', 'price' => $o['price'] ?? null, 'point' => $o['point'] ?? null], fn($v) => $v !== null);
                $ms[] = ['key' => $m['key'] ?? '', 'outcomes' => $os];
            }
            if ($ms) $bs[] = ['markets' => $ms];
        }
        $out[] = ['commence_time' => $e['commence_time'] ?? '', 'home_team' => $e['home_team'] ?? '', 'away_team' => $e['away_team'] ?? '', 'bookmakers' => $bs];
    }
    return $out;
}

/**
 * Eventos con cuotas de una liga, o null si no hay clave, no hay cobertura o no hay cuotas vigentes.
 * $soon = la liga juega en las próximas 48 h. Solo entonces se gasta una consulta, y solo si la copia
 * guardada ya venció y queda presupuesto del día. Si no, se usa la copia guardada (hasta 48 h de antigüedad).
 */
function odds_liga(array $cfg, string $code, bool $soon = true): ?array
{
    static $mem = [];
    if (!odds_activo() || !isset(ODDS_KEYS[$code])) return null;
    if (array_key_exists($code, $mem)) return $mem[$code];
    $key = ODDS_KEYS[$code];
    $file = odds_dir() . "/$key.json";
    $ttl = (float)($cfg['odds_ttl_hours'] ?? 24) * 3600;
    $age = is_file($file) ? time() - filemtime($file) : null;
    $saved = fn() => ($age !== null && $age < 48 * 3600) ? (json_decode((string)file_get_contents($file), true) ?: []) : null;
    if ($age !== null && $age < $ttl) return $mem[$code] = $saved();
    if (!$soon) return $mem[$code] = $saved();

    $markets = in_array($code, ODDS_SOLO_GANADOR, true) ? 'h2h' : 'h2h,totals';
    $cost = substr_count($markets, ',') + 1;
    $st = odds_estado();
    $cap = (int)($cfg['odds_creditos_dia'] ?? 14);
    if (!in_array($code, $cfg['odds_prioridad'] ?? [], true)) $cap -= 6;   // deja créditos para las ligas prioritarias
    if (($st['pausa'] ?? 0) > time()) return $mem[$code] = $saved();
    if (($st['usados'] ?? 0) + $cost > $cap) { log_line("cuotas $key: presupuesto del día agotado, se usa la copia guardada"); return $mem[$code] = $saved(); }

    $url = 'https://api.the-odds-api.com/v4/sports/' . $key . '/odds/?' . http_build_query([
        'apiKey' => getenv('ODDS_API_KEY'), 'regions' => $cfg['odds_regions'] ?? 'eu', 'markets' => $markets,
        'oddsFormat' => 'decimal', 'dateFormat' => 'iso',
    ]);
    $ctx = stream_context_create(['http' => ['timeout' => 20, 'ignore_errors' => true]]);
    $raw = @file_get_contents($url, false, $ctx);
    $left = null;
    foreach ($http_response_header ?? [] as $hd) if (stripos($hd, 'x-requests-remaining:') === 0) $left = (int)trim(substr($hd, 21));
    $data = $raw ? json_decode($raw, true) : null;
    if (!is_array($data) || isset($data['message'])) {
        $msg = (string)($data['message'] ?? 'sin respuesta');
        // Sin cupo en la cuenta: no se vuelve a intentar hasta dentro de 12 horas (cada intento fallido es tiempo perdido)
        if (stripos($msg, 'quota') !== false) { odds_estado(['pausa' => time() + 12 * 3600]); log_line("ERROR cuotas: la cuenta de The Odds API se quedó sin créditos este mes. Se reintenta en 12 h."); }
        else log_line("ERROR cuotas $key: $msg");
        return $mem[$code] = $saved();
    }
    $data = odds_compacto($data);
    file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE));
    odds_estado(array_filter(['usados' => ($st['usados'] ?? 0) + $cost, 'restantes' => $left, 'pausa' => 0], fn($v) => $v !== null));
    log_line("OK cuotas $key: " . count($data) . ' eventos' . ($left !== null ? " (quedan $left créditos este mes)" : ''));
    return $mem[$code] = $data;
}

function odds_norm(string $s): string
{
    $s = strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s);
    $s = preg_replace('/\b(fc|cf|ac|as|sc|sv|afc|cd|ca|club|de|the|real|calcio|1\.|fk|sk|ud|rc|us|ss)\b/', ' ', $s);
    return trim(preg_replace('/[^a-z0-9]+/', ' ', $s));
}

function odds_igual(string $a, string $b): bool
{
    $a = odds_norm($a); $b = odds_norm($b);
    if ($a === '' || $b === '') return false;
    if ($a === $b || str_contains($a, $b) || str_contains($b, $a)) return true;
    similar_text($a, $b, $pct);
    return $pct >= 78;
}

function odds_evento(array $events, array $g): ?array
{
    foreach ($events as $e) {
        $t = strtotime($e['commence_time'] ?? '');
        if (!$t || abs($t - $g['ts']) > 36 * 3600) continue;
        $h = [$g['home'], $g['home_full'] ?? ''];
        $a = [$g['away'], $g['away_full'] ?? ''];
        $hit = function (array $names, string $team): bool { foreach ($names as $x) if ($x !== '' && odds_igual($x, $team)) return true; return false; };
        if ($hit($h, $e['home_team'] ?? '') && $hit($a, $e['away_team'] ?? '')) return $e;
        // En peleas (UFC) no hay local ni visitante: la casa puede listar a los peleadores al revés
        if ($hit($h, $e['away_team'] ?? '') && $hit($a, $e['home_team'] ?? '')) { [$e['home_team'], $e['away_team']] = [$e['away_team'], $e['home_team']]; return $e; }
    }
    return null;
}

/**
 * Cuota típica de un resultado entre todas las casas (la del medio). No se usa la más alta:
 * una sola casa con la línea desactualizada inflaba la cuota y hacía ver "valor" donde no lo había.
 */
function odds_mejor(array $e, string $mkt, callable $pick): ?float
{
    $ps = [];
    foreach ($e['bookmakers'] ?? [] as $b) foreach ($b['markets'] ?? [] as $m) {
        if (($m['key'] ?? '') !== $mkt) continue;
        foreach ($m['outcomes'] ?? [] as $o) if ($pick($o) && isset($o['price']) && $o['price'] > 1) $ps[] = (float)$o['price'];
    }
    if (!$ps) return null;
    sort($ps); $n = count($ps);
    return $n % 2 ? $ps[intdiv($n, 2)] : ($ps[$n / 2 - 1] + $ps[$n / 2]) / 2;
}

/**
 * Probabilidad que le dan las casas a un resultado, ya sin su margen de ganancia:
 * en cada casa se reparten las probabilidades implícitas (1/cuota) para que sumen 100 %, y se promedian las casas.
 * $pick decide qué resultados cuentan como "nuestro" lado (puede ser más de uno, p. ej. doble oportunidad).
 */
function odds_prob(array $e, string $mkt, callable $pick, ?float $point = null): ?float
{
    $sum = 0.0; $n = 0;
    foreach ($e['bookmakers'] ?? [] as $b) foreach ($b['markets'] ?? [] as $m) {
        if (($m['key'] ?? '') !== $mkt) continue;
        $tot = 0.0; $sel = 0.0; $k = 0;
        foreach ($m['outcomes'] ?? [] as $o) {
            if ($point !== null && abs(($o['point'] ?? -99) - $point) >= 0.01) continue;
            if (empty($o['price']) || $o['price'] <= 1) continue;
            $inv = 1 / (float)$o['price']; $tot += $inv; $k++;
            if ($pick($o)) $sel += $inv;
        }
        if ($k < 2 || $sel <= 0 || $tot < 0.98 || $tot > 1.25) continue;   // mercado incompleto o raro: no se usa
        $sum += $sel / $tot; $n++;
    }
    return $n ? round($sum / $n, 4) : null;
}

/** Cuota para una regla de mercado del modelo. 'est' = estimada a partir de otras cuotas reales. */
function cuota_para(array $events, array $g, array $rule): ?array
{
    static $cache = [];
    $ck = $g['id'] ?? ($g['home'] . $g['away'] . $g['ts']);
    if (!array_key_exists($ck, $cache)) $cache[$ck] = odds_evento($events, $g);
    $e = $cache[$ck];
    if (!$e) return null;
    $isHome = fn($o) => odds_igual($o['name'] ?? '', $e['home_team']);
    $isAway = fn($o) => odds_igual($o['name'] ?? '', $e['away_team']);
    $isDraw = fn($o) => strtolower($o['name'] ?? '') === 'draw';
    switch ($rule[0] ?? '') {
        case 'res':
            $f = $rule[1] === 'home' ? $isHome : ($rule[1] === 'away' ? $isAway : $isDraw);
            $q = odds_mejor($e, 'h2h', $f);
            return $q ? ['q' => round($q, 2), 'pm' => odds_prob($e, 'h2h', $f)] : null;
        case 'tot':
            $over = $rule[1] === 'over'; $L = (float)$rule[2];
            $q = odds_mejor($e, 'totals', fn($o) => abs(($o['point'] ?? -99) - $L) < 0.01 && (strtolower($o['name'] ?? '') === ($over ? 'over' : 'under')));
            return $q ? ['q' => round($q, 2), 'pm' => odds_prob($e, 'totals', fn($o) => strtolower($o['name'] ?? '') === ($over ? 'over' : 'under'), $L)] : null;
        case 'dc':
            $h = odds_mejor($e, 'h2h', $isHome); $d = odds_mejor($e, 'h2h', $isDraw); $a = odds_mejor($e, 'h2h', $isAway);
            if (!$h || !$d || !$a) return null;
            $pr = ['1X' => 1 / $h + 1 / $d, 'X2' => 1 / $a + 1 / $d, '12' => 1 / $h + 1 / $a][$rule[1]] ?? null;
            $fd = ['1X' => fn($o) => $isHome($o) || $isDraw($o), 'X2' => fn($o) => $isAway($o) || $isDraw($o), '12' => fn($o) => $isHome($o) || $isAway($o)][$rule[1]] ?? null;
            return $pr ? ['q' => round(1 / $pr, 2), 'est' => true, 'pm' => $fd ? odds_prob($e, 'h2h', $fd) : null] : null;
    }
    return null;
}
