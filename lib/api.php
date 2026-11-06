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
    $file = sprintf('%s-%s-%s.json', $sportKey, $league['id'], preg_replace('/[^0-9A-Za-z-]/', '', (string)$league['season']));

    if (!empty($cfg['mock'])) {
        $path = "$root/mock/$file";
        if (!is_file($path)) return [];
        $resp = json_decode(file_get_contents($path), true)['response'] ?? [];
        return mock_mask($sportKey, $resp, (int)($GLOBALS['UMBRAL_NOW'] ?? time()));
    }

    $cacheDir = defined('UMBRAL_CACHE') ? UMBRAL_CACHE : "$root/data/cache";
    if (!is_dir($cacheDir)) mkdir($cacheDir, 0775, true);
    $cachePath = "$cacheDir/$file";
    if (is_file($cachePath) && filemtime($cachePath) > time() - $cfg['cache_minutes'] * 60) {
        return json_decode(file_get_contents($cachePath), true)['response'] ?? [];
    }

    $url = sprintf('%s/%s?league=%s&season=%s&timezone=%s',
        $sport['host'], $sport['endpoint'], urlencode((string)$league['id']), urlencode((string)$league['season']), urlencode($cfg['timezone']));
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['x-apisports-key: ' . $cfg['api_key']],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false || $code !== 200) {
        $GLOBALS['UMBRAL_ERRORS'] = ($GLOBALS['UMBRAL_ERRORS'] ?? 0) + 1;
        log_line("ERROR $sportKey/{$league['name']}: HTTP $code $err");
        // si falla, usa la última copia guardada aunque esté vieja
        return is_file($cachePath) ? (json_decode(file_get_contents($cachePath), true)['response'] ?? []) : [];
    }
    $json = json_decode($body, true);
    if (!empty($json['errors'])) {
        $GLOBALS['UMBRAL_ERRORS'] = ($GLOBALS['UMBRAL_ERRORS'] ?? 0) + 1;
        log_line("ERROR $sportKey/{$league['name']}: " . json_encode($json['errors'], JSON_UNESCAPED_UNICODE));
        return is_file($cachePath) ? (json_decode(file_get_contents($cachePath), true)['response'] ?? []) : [];
    }
    file_put_contents($cachePath, $body);
    log_line("OK $sportKey/{$league['name']}: " . count($json['response'] ?? []) . ' partidos');
    return $json['response'] ?? [];
}

/** Normaliza un partido de cualquier deporte. */
function normalize(string $sportKey, array $g, string $leagueName): ?array
{
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
