<?php
/**
 * Cuotas reales (The Odds API, https://the-odds-api.com). Solo se activa si existe ODDS_API_KEY.
 * Sirve para dos cosas: saber si una casa de verdad ofrece el mercado, y mostrar su cuota.
 *
 * El plan gratis da 500 créditos al mes. Cada consulta (h2h + totals, 1 región) gasta 2 créditos,
 * así que cada liga se consulta como máximo una vez cada 'odds_ttl_hours' horas (por defecto 24).
 */

const ODDS_KEYS = [
    'PL' => 'soccer_epl', 'PD' => 'soccer_spain_la_liga', 'SA' => 'soccer_italy_serie_a', 'BL1' => 'soccer_germany_bundesliga',
    'FL1' => 'soccer_france_ligue_one', 'CL' => 'soccer_uefa_champs_league', 'PPL' => 'soccer_portugal_primeira_liga',
    'DED' => 'soccer_netherlands_eredivisie', 'ELC' => 'soccer_efl_champ', 'ARG' => 'soccer_argentina_primera_division',
    'BRA' => 'soccer_brazil_campeonato', 'MEX' => 'soccer_mexico_ligamx', 'MLS' => 'soccer_usa_mls',
    'UEL' => 'soccer_uefa_europa_league', 'UECL' => 'soccer_uefa_europa_conference_league',
    'LIB' => 'soccer_conmebol_copa_libertadores', 'SUD' => 'soccer_conmebol_copa_sudamericana',
    'NBA' => 'basketball_nba',
];

function odds_activo(): bool { return (string)(getenv('ODDS_API_KEY') ?: '') !== ''; }

/** Eventos con cuotas de una liga, o null si no hay clave, no hay cobertura o se agotó el presupuesto. */
function odds_liga(array $cfg, string $code): ?array
{
    static $mem = [];
    if (!odds_activo() || !isset(ODDS_KEYS[$code])) return null;
    if (array_key_exists($code, $mem)) return $mem[$code];
    $key = ODDS_KEYS[$code];
    $dir = defined('UMBRAL_CACHE') ? UMBRAL_CACHE : sys_get_temp_dir();
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $file = "$dir/odds_$key.json";
    $ttl = (float)($cfg['odds_ttl_hours'] ?? 24) * 3600;
    if (is_file($file) && time() - filemtime($file) < $ttl) {
        return $mem[$code] = json_decode(file_get_contents($file), true) ?: [];
    }
    $url = 'https://api.the-odds-api.com/v4/sports/' . $key . '/odds/?' . http_build_query([
        'apiKey' => getenv('ODDS_API_KEY'), 'regions' => $cfg['odds_regions'] ?? 'eu', 'markets' => 'h2h,totals',
        'oddsFormat' => 'decimal', 'dateFormat' => 'iso',
    ]);
    $ctx = stream_context_create(['http' => ['timeout' => 20, 'ignore_errors' => true]]);
    $raw = @file_get_contents($url, false, $ctx);
    $data = $raw ? json_decode($raw, true) : null;
    if (!is_array($data) || isset($data['message'])) {
        log_line("ERROR cuotas $key: " . ($data['message'] ?? 'sin respuesta'));
        // si hay una copia vieja, se usa antes que nada
        return $mem[$code] = (is_file($file) ? (json_decode(file_get_contents($file), true) ?: []) : null);
    }
    file_put_contents($file, json_encode($data));
    log_line("OK cuotas $key: " . count($data) . ' eventos');
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
        $okH = false; $okA = false;
        foreach ($h as $x) if ($x !== '' && odds_igual($x, $e['home_team'] ?? '')) $okH = true;
        foreach ($a as $x) if ($x !== '' && odds_igual($x, $e['away_team'] ?? '')) $okA = true;
        if ($okH && $okA) return $e;
    }
    return null;
}

/** Mejor cuota disponible entre todas las casas para un resultado concreto. */
function odds_mejor(array $e, string $mkt, callable $pick): ?float
{
    $best = null;
    foreach ($e['bookmakers'] ?? [] as $b) foreach ($b['markets'] ?? [] as $m) {
        if (($m['key'] ?? '') !== $mkt) continue;
        foreach ($m['outcomes'] ?? [] as $o) {
            if ($pick($o) && isset($o['price']) && ($best === null || $o['price'] > $best)) $best = (float)$o['price'];
        }
    }
    return $best;
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
            return $q ? ['q' => round($q, 2)] : null;
        case 'tot':
            $over = $rule[1] === 'over'; $L = (float)$rule[2];
            $q = odds_mejor($e, 'totals', fn($o) => abs(($o['point'] ?? -99) - $L) < 0.01 && (strtolower($o['name'] ?? '') === ($over ? 'over' : 'under')));
            return $q ? ['q' => round($q, 2)] : null;
        case 'dc':
            $h = odds_mejor($e, 'h2h', $isHome); $d = odds_mejor($e, 'h2h', $isDraw); $a = odds_mejor($e, 'h2h', $isAway);
            if (!$h || !$d || !$a) return null;
            $pr = ['1X' => 1 / $h + 1 / $d, 'X2' => 1 / $a + 1 / $d, '12' => 1 / $h + 1 / $a][$rule[1]] ?? null;
            return $pr ? ['q' => round(1 / $pr, 2), 'est' => true] : null;
    }
    return null;
}
