<?php
/**
 * Cliente de API-Sports con caché en disco y modo prueba.
 * Convierte la respuesta de cada deporte a un formato común:
 * [id, ts, status, league, home, away, home_id, away_id, logo_home, logo_away, hs, as]
 */

const FINISHED = ['FT', 'AET', 'PEN', 'AOT', 'AP', 'AW'];
const NOT_STARTED = ['NS', 'TBD'];
const VOID_STATUS = ['PST', 'CANC', 'ABD', 'AWD', 'WO', 'POST', 'INTR', 'SUSP'];

function api_get(array $cfg, string $sportKey, array $sport, array $league): array
{
    $root = dirname(__DIR__);
    $fd = ($sport['source'] ?? 'apisports') === 'footballdata';
    $listKey = $fd ? 'matches' : 'response';
    $file = $fd
        ? sprintf('%s-fd-%s.json', $sportKey, preg_replace('/[^0-9A-Za-z]/', '', $league['code']))
        : sprintf('%s-%s-%s.json', $sportKey, $league['id'], preg_replace('/[^0-9A-Za-z-]/', '', (string)$league['season']));

    if (!empty($cfg['mock'])) {
        $path = "$root/mock/$file";
        if (!is_file($path)) return [];
        $resp = json_decode(file_get_contents($path), true)[$listKey] ?? [];
        if ($fd) return $resp;
        return mock_mask($sportKey, $resp, (int)($GLOBALS['UMBRAL_NOW'] ?? time()));
    }

    $cacheDir = defined('UMBRAL_CACHE') ? UMBRAL_CACHE : "$root/data/cache";
    if (!is_dir($cacheDir)) mkdir($cacheDir, 0775, true);
    $cachePath = "$cacheDir/$file";
    if (is_file($cachePath) && filemtime($cachePath) > time() - $cfg['cache_minutes'] * 60) {
        return json_decode(file_get_contents($cachePath), true)[$listKey] ?? [];
    }

    if ($fd) {
        $base = getenv('FD_BASE') ?: 'https://api.football-data.org/v4';
        $url = $base . '/competitions/' . urlencode($league['code']) . '/matches';
        $headers = ['X-Auth-Token: ' . $cfg['football_data_key']];
    } else {
    $url = sprintf('%s/%s?league=%s&season=%s&timezone=%s',
        $sport['host'], $sport['endpoint'], urlencode((string)$league['id']), urlencode((string)$league['season']), urlencode($cfg['timezone']));
        $headers = ['x-apisports-key: ' . $cfg['api_key']];
    }
    $respHeaders = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$respHeaders) {
            $p = explode(':', $h, 2);
            if (count($p) === 2) $respHeaders[strtolower(trim($p[0]))] = trim($p[1]);
            return strlen($h);
        },
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($fd) {
        // football-data.org: respeta el límite por minuto según sus cabeceras
        $left = $respHeaders['x-requests-available-minute'] ?? null;
        $reset = (int)($respHeaders['x-requestcounter-reset'] ?? 60);
        if ($left !== null && (int)$left <= 1) sleep(min(65, $reset + 1));
        else usleep(1500000);
    }

    if ($body === false || $code !== 200) {
        $GLOBALS['UMBRAL_ERRORS'] = ($GLOBALS['UMBRAL_ERRORS'] ?? 0) + 1;
        log_line("ERROR $sportKey/{$league['name']}: HTTP $code $err");
        // si falla, usa la última copia guardada aunque esté vieja
        return is_file($cachePath) ? (json_decode(file_get_contents($cachePath), true)[$listKey] ?? []) : [];
    }
    $json = json_decode($body, true);
    if (!$fd && !empty($json['errors'])) {
        $GLOBALS['UMBRAL_ERRORS'] = ($GLOBALS['UMBRAL_ERRORS'] ?? 0) + 1;
        log_line("ERROR $sportKey/{$league['name']}: " . json_encode($json['errors'], JSON_UNESCAPED_UNICODE));
        return is_file($cachePath) ? (json_decode(file_get_contents($cachePath), true)[$listKey] ?? []) : [];
    }
    file_put_contents($cachePath, $body);
    log_line("OK $sportKey/{$league['name']}: " . count($json[$listKey] ?? []) . ' partidos');
    return $json[$listKey] ?? [];
}

/** Normaliza un partido de cualquier deporte. */
function normalize(string $sportKey, array $g, string $leagueName, string $source = 'apisports'): ?array
{
    if ($source === 'footballdata') {
        $map = ['FINISHED' => 'FT', 'AWARDED' => 'AW', 'SCHEDULED' => 'NS', 'TIMED' => 'NS', 'POSTPONED' => 'PST',
                'CANCELLED' => 'CANC', 'SUSPENDED' => 'SUSP', 'IN_PLAY' => 'LIVE', 'PAUSED' => 'LIVE', 'LIVE' => 'LIVE'];
        // Resultado de los 90 minutos: en copas con prórroga usa regularTime
        $sc = $g['score']['regularTime'] ?? $g['score']['fullTime'] ?? [];
        $hs = $sc['home'] ?? null; $as = $sc['away'] ?? null;
        return [
            'id' => 'futbol-fd-' . ($g['id'] ?? 0),
            'ts' => (int)strtotime($g['utcDate'] ?? ''),
            'status' => $map[$g['status'] ?? ''] ?? 'NS',
            'league' => $leagueName,
            'home' => $g['homeTeam']['shortName'] ?? $g['homeTeam']['name'] ?? '?',
            'away' => $g['awayTeam']['shortName'] ?? $g['awayTeam']['name'] ?? '?',
            'home_id' => $g['homeTeam']['id'] ?? 0, 'away_id' => $g['awayTeam']['id'] ?? 0,
            'logo_home' => $g['homeTeam']['crest'] ?? '', 'logo_away' => $g['awayTeam']['crest'] ?? '',
            'hs' => $hs === null ? null : (int)$hs, 'as' => $as === null ? null : (int)$as,
        ];
    }
    if ($sportKey === 'futbol') {
        $fx = $g['fixture'] ?? [];
        $hs = $g['score']['fulltime']['home'] ?? $g['goals']['home'] ?? null;
        $as = $g['score']['fulltime']['away'] ?? $g['goals']['away'] ?? null;
        return [
            'id' => 'futbol-' . ($fx['id'] ?? 0),
            'ts' => (int)($fx['timestamp'] ?? 0),
            'status' => $fx['status']['short'] ?? 'NS',
            'league' => $leagueName,
            'home' => $g['teams']['home']['name'] ?? '?', 'away' => $g['teams']['away']['name'] ?? '?',
            'home_id' => $g['teams']['home']['id'] ?? 0, 'away_id' => $g['teams']['away']['id'] ?? 0,
            'logo_home' => $g['teams']['home']['logo'] ?? '', 'logo_away' => $g['teams']['away']['logo'] ?? '',
            'hs' => $hs === null ? null : (int)$hs, 'as' => $as === null ? null : (int)$as,
        ];
    }
    // Fútbol americano envuelve los datos del partido en "game"
    $meta = $g['game'] ?? $g;
    $ts = $meta['timestamp'] ?? ($meta['date']['timestamp'] ?? null);
    if ($ts === null && !empty($meta['date'])) $ts = strtotime(is_array($meta['date']) ? ($meta['date']['date'] ?? '') : $meta['date']);
    $score = function ($v) {
        if (is_array($v)) $v = $v['total'] ?? null;
        return $v === null ? null : (int)$v;
    };
    return [
        'id' => $sportKey . '-' . ($meta['id'] ?? 0),
        'ts' => (int)$ts,
        'status' => $meta['status']['short'] ?? 'NS',
        'league' => $leagueName,
        'home' => $g['teams']['home']['name'] ?? '?', 'away' => $g['teams']['away']['name'] ?? '?',
        'home_id' => $g['teams']['home']['id'] ?? 0, 'away_id' => $g['teams']['away']['id'] ?? 0,
        'logo_home' => $g['teams']['home']['logo'] ?? '', 'logo_away' => $g['teams']['away']['logo'] ?? '',
        'hs' => $score($g['scores']['home'] ?? null), 'as' => $score($g['scores']['away'] ?? null),
    ];
}

function log_line(string $msg): void
{
    $dir = defined('UMBRAL_CACHE') ? UMBRAL_CACHE : dirname(__DIR__) . '/data';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    file_put_contents("$dir/update.log", date('Y-m-d H:i:s') . "  $msg\n", FILE_APPEND);
}

/** Modo prueba: los partidos que aún no "ocurren" según la hora simulada salen sin marcador. */
function mock_mask(string $sportKey, array $resp, int $now): array
{
    foreach ($resp as &$g) {
        if ($sportKey === 'futbol') {
            if ($g['fixture']['timestamp'] > $now - 3 * 3600) {
                $g['fixture']['status']['short'] = 'NS';
                $g['goals'] = ['home' => null, 'away' => null];
                $g['score']['fulltime'] = ['home' => null, 'away' => null];
            }
        } else {
            $meta = isset($g['game']) ? 'game' : null;
            $ts = $meta ? $g['game']['date']['timestamp'] : $g['timestamp'];
            if ($ts > $now - 3 * 3600) {
                if ($meta) $g['game']['status']['short'] = 'NS'; else $g['status']['short'] = 'NS';
                $g['scores'] = ['home' => null, 'away' => null];
            }
        }
    }
    return $resp;
}
