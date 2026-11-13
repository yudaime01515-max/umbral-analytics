<?php
/**
 * Aprendizaje de Umbral: compara lo que el modelo dijo con lo que pasó de verdad
 * y corrige sus probabilidades. Se recalcula en cada actualización.
 *
 * - Usa solo pronósticos cerrados (ok / ko) y reales (no de prueba).
 * - Compara contra la probabilidad ORIGINAL del modelo ('p0'), nunca contra la ya corregida.
 * - Corrección por deporte+tipo de mercado cuando hay datos suficientes; si no, corrección general.
 * - Con pocos datos la corrección es suave (peso n / (n + K)), para no reaccionar a la suerte.
 */

const CAL_K = 20;          // "partidos de duda": a más K, más lenta y prudente la corrección
const CAL_MIN_GRUPO = 8;   // mínimo de cierres para corregir un grupo con su propio historial
const CAL_P_MAX = 0.92;    // ninguna probabilidad pasa de esto: el deporte siempre deja margen al azar

function cal_grupo(array $h): string { return ($h['sport'] ?? '?') . '|' . ($h['r'][0] ?? '?'); }

function aprender_calibracion(array $history): array
{
    $g = []; $tot = ['n' => 0, 'ok' => 0, 'sp' => 0.0];
    foreach ($history as $h) {
        if (!empty($h['mock']) || !in_array($h['status'] ?? '', ['ok', 'ko'], true)) continue;
        $p = (float)($h['p0'] ?? $h['p']);
        $k = cal_grupo($h);
        $g[$k] = $g[$k] ?? ['n' => 0, 'ok' => 0, 'sp' => 0.0];
        $g[$k]['n']++; $g[$k]['ok'] += $h['status'] === 'ok' ? 1 : 0; $g[$k]['sp'] += $p;
        $tot['n']++; $tot['ok'] += $h['status'] === 'ok' ? 1 : 0; $tot['sp'] += $p;
    }
    $fin = function (array $x): array {
        $n = $x['n'];
        // ajuste = (aciertos reales - aciertos esperados) / (n + K): positivo si el modelo se queda corto, negativo si exagera
        return ['n' => $n, 'ok' => $x['ok'], 'pred' => $n ? round($x['sp'] / $n, 4) : null,
                'real' => $n ? round($x['ok'] / $n, 4) : null,
                'adj' => round(($x['ok'] - $x['sp']) / ($n + CAL_K), 4)];
    };
    $out = ['global' => $fin($tot), 'grupos' => []];
    foreach ($g as $k => $x) $out['grupos'][$k] = $fin($x);
    return $out;
}

function cal_ajuste(string $sport, string $type, array $cal): float
{
    $gr = $cal['grupos'][$sport . '|' . $type] ?? null;
    if ($gr && $gr['n'] >= CAL_MIN_GRUPO) return (float)$gr['adj'];
    return (float)($cal['global']['adj'] ?? 0);
}

/** Tipos de mercado que se pueden publicar como pronóstico, por deporte. */
function mercados_publicables(string $sport): array
{
    $cfg = $GLOBALS['UMBRAL_CFG']['publicables'] ?? [];
    return $cfg[$sport] ?? ($cfg['default'] ?? ['res', 'dc', 'tot', 'btts', 'hcp']);
}

/**
 * Corrige las probabilidades, guarda la original en 'p0', marca cuáles se pueden publicar ('pub')
 * y pone la cuota real si existe ('q').
 */
function calibrar_markets(array $markets, string $sport, array $cal, ?array $odds = null, ?array $game = null): array
{
    $allowed = mercados_publicables($sport);
    foreach ($markets as &$m) {
        $type = $m['r'][0] ?? '';
        $m['p0'] = $m['p'];
        $m['p'] = round(max(0.01, min(CAL_P_MAX, $m['p'] + cal_ajuste($sport, $type, $cal))), 4);
        $m['pub'] = in_array($type, $allowed, true);
        if ($odds !== null) $m['cov'] = true;   // liga con cobertura de cuotas: ahí sí se exige que la casa lo ofrezca
        if ($odds && $game && function_exists('cuota_para')) {
            $q = cuota_para($odds, $game, $m['r']);
            if ($q) { $m['q'] = $q['q']; if (!empty($q['est'])) $m['qe'] = true; }
        }
    }
    unset($m);
    return $markets;
}
