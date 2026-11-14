<?php
/**
 * Cerebro de Umbral: un modelo pequeño (regresión logística) que aprende de cada pronóstico cerrado.
 *
 * - Entrada: la probabilidad original del modelo deportivo, el deporte y el tipo de mercado.
 * - Salida: la probabilidad corregida con lo que de verdad pasó en el historial.
 * - Parte de "confiar en el modelo" y solo se aparta cuando los datos lo justifican (regularización).
 * - Se examina solo: una vez al día se pone a prueba con días que no había visto (entrena con el pasado,
 *   predice el día siguiente) y elige cuánta libertad darse. Si corregir no mejora, no corrige.
 * - Se reentrena en cada actualización con todos los pronósticos cerrados hasta ese momento.
 */

const CEREBRO_LAMS = [40, 100, 300, 1000];
const CEREBRO_ESPORTS = ['cs2', 'dota2', 'lol', 'valorant', 'r6', 'ow', 'mlbb', 'sc2'];
const CEREBRO_MIN = 120;      // con menos pronósticos cerrados todavía no corrige

function cb_p0(array $h): float { return min(0.99, max(0.01, (float)($h['p0'] ?? $h['p']))); }
function cb_sig(float $z): float { return 1 / (1 + exp(-$z)); }
function cb_feats(string $sport, string $type, float $p): array
{
    $f = ['b' => 1.0, 'z' => log($p / (1 - $p)), "s:$sport" => 1.0, "t:$type" => 1.0, "g:$sport|$type" => 1.0];
    if (in_array($sport, CEREBRO_ESPORTS, true)) $f['es'] = 1.0;
    return $f;
}
function cb_cerrados(array $history): array
{
    $d = array_values(array_filter($history, fn($h) => empty($h['mock']) && in_array($h['status'] ?? '', ['ok', 'ko'], true) && isset($h['r'][0])));
    usort($d, fn($a, $b) => $a['ts'] <=> $b['ts']);
    return $d;
}
function cb_train(array $D, float $lam, int $it = 400, float $lr = 0.5): array
{
    $w = ['z' => 1.0]; $n = count($D);
    if (!$n) return $w;
    $X = []; $Y = [];
    foreach ($D as $h) { $X[] = cb_feats($h['sport'], $h['r'][0], cb_p0($h)); $Y[] = $h['status'] === 'ok' ? 1.0 : 0.0; }
    for ($i = 0; $i < $it; $i++) {
        $g = [];
        foreach ($X as $j => $x) {
            $z = 0.0; foreach ($x as $k => $v) $z += ($w[$k] ?? 0.0) * $v;
            $e = cb_sig($z) - $Y[$j];
            foreach ($x as $k => $v) $g[$k] = ($g[$k] ?? 0.0) + $e * $v;
        }
        foreach ($g as $k => $gk) {
            $prior = $k === 'z' ? 1.0 : 0.0;
            $lk = $lam * ($k === 'b' || $k === 'z' ? 0.3 : 1.0);
            $w[$k] = ($w[$k] ?? 0.0) - $lr * ($gk + $lk * (($w[$k] ?? 0.0) - $prior)) / ($n + $lam);
        }
    }
    return $w;
}
function cb_pred(array $w, string $sport, string $type, float $p): float
{
    $z = 0.0; foreach (cb_feats($sport, $type, min(0.99, max(0.01, $p))) as $k => $v) $z += ($w[$k] ?? 0.0) * $v;
    return min(0.95, max(0.05, cb_sig($z)));
}
function cb_dia(array $h): int { return intdiv((int)$h['ts'] - 5 * 3600, 86400); }

/** Examen con días no vistos. Devuelve por cada opción el error (log-loss) y la mejor. 'crudo' = no corregir. */
function cb_examen(array $D, int $maxDias = 10): array
{
    $dias = array_values(array_unique(array_map('cb_dia', $D))); sort($dias);
    $folds = array_slice(array_slice($dias, 2), -$maxDias);
    $S = ['crudo' => 0.0]; foreach (CEREBRO_LAMS as $l) $S["$l"] = 0.0;
    $n = 0; $buck = ['n' => 0, 'ok' => 0, 'sp' => 0.0]; $rows = [];
    foreach ($folds as $d) {
        $tr = array_values(array_filter($D, fn($h) => cb_dia($h) < $d));
        $te = array_values(array_filter($D, fn($h) => cb_dia($h) === $d));
        if (!$tr || !$te) continue;
        $W = []; foreach (CEREBRO_LAMS as $l) $W["$l"] = cb_train($tr, $l, 250);
        foreach ($te as $h) {
            $y = $h['status'] === 'ok' ? 1.0 : 0.0; $n++;
            $ll = fn(float $p) => -($y * log($p) + (1 - $y) * log(1 - $p));
            $S['crudo'] += $ll(min(0.95, max(0.05, cb_p0($h))));
            $ps = [];
            foreach ($W as $l => $w) { $ps[$l] = cb_pred($w, $h['sport'], $h['r'][0], cb_p0($h)); $S[$l] += $ll($ps[$l]); }
            $rows[] = [$ps, $y];
        }
    }
    if (!$n) return ['mejor' => 'crudo', 'n' => 0];
    foreach ($S as $k => $v) $S[$k] = round($v / $n, 4);
    $mejor = 'crudo'; foreach ($S as $k => $v) if ($v < $S[$mejor] - 0.0005) $mejor = $k;
    // Aciertos reales de lo que el cerebro habría marcado 75% o más, en días que no había visto
    if ($mejor !== 'crudo') foreach ($rows as [$ps, $y]) if ($ps[$mejor] >= 0.75) { $buck['n']++; $buck['ok'] += (int)$y; $buck['sp'] += $ps[$mejor]; }
    return ['mejor' => $mejor, 'n' => $n, 'error' => $S, 'alto' => $buck['n'] ? ['n' => $buck['n'], 'ok' => $buck['ok'], 'pred' => round($buck['sp'] / $buck['n'], 4)] : null];
}

/** Entrena el cerebro con todo el historial. El examen se repite una vez al día (se guarda en cerebro.json). */
function cerebro_aprender(array $history, string $file, int $now): array
{
    static $mem = [];
    $D = cb_cerrados($history); $n = count($D);
    if (isset($mem[$n])) return $mem[$n];
    $prev = is_file($file) ? (json_decode((string)file_get_contents($file), true) ?: []) : [];
    $out = ['updated' => $now, 'n' => $n, 'activo' => false, 'w' => null, 'examen' => $prev['examen'] ?? null, 'examen_dia' => $prev['examen_dia'] ?? null, 'lecciones' => []];
    if ($n < CEREBRO_MIN) return $mem[$n] = $out;
    $hoy = date('Y-m-d', $now);
    if (($out['examen_dia'] ?? '') !== $hoy || empty($out['examen'])) { $out['examen'] = cb_examen($D); $out['examen_dia'] = $hoy; }
    $mejor = (string)($out['examen']['mejor'] ?? 'crudo');
    if ($mejor === 'crudo') return $mem[$n] = $out;       // corregir no mejoró en el examen: se deja el modelo tal cual
    $w = cb_train($D, (float)$mejor);
    $out['activo'] = true; $out['w'] = array_map(fn($v) => round($v, 5), $w);
    // Lecciones: cuánto mueve un pronóstico de 78% en cada deporte+mercado con historial suficiente
    $g = [];
    foreach ($D as $h) { $k = $h['sport'] . '|' . $h['r'][0]; $g[$k] = $g[$k] ?? ['n' => 0, 'ok' => 0]; $g[$k]['n']++; $g[$k]['ok'] += $h['status'] === 'ok' ? 1 : 0; }
    foreach ($g as $k => $x) {
        if ($x['n'] < 15) continue;
        [$s, $t] = explode('|', $k);
        $out['lecciones'][] = ['g' => $k, 'n' => $x['n'], 'ok' => $x['ok'], 'd' => round((cb_pred($w, $s, $t, 0.78) - 0.78) * 100, 1)];
    }
    usort($out['lecciones'], fn($a, $b) => abs($b['d']) <=> abs($a['d']));
    return $mem[$n] = $out;
}
