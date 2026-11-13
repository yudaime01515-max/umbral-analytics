<?php
/**
 * Modelos de probabilidad de Umbral Analytics.
 * Todos parten de un índice de ataque y defensa por equipo, calculado con los
 * partidos terminados de la temporada y dando más peso a los recientes.
 */

const HALF_LIFE_DAYS = 60;   // un partido de hace 60 días pesa la mitad que uno de hoy
const PRIOR_GAMES    = 3;    // partidos "promedio" que se suman para no exagerar con pocos datos
const MIN_GAMES      = 4;    // mínimo de partidos jugados por equipo para pronosticar

/* ---------- utilidades matemáticas ---------- */
function poisson_pmf(int $k, float $l): float { $r = exp(-$l); for ($i = 1; $i <= $k; $i++) $r *= $l / $i; return $r; }
function erf_approx(float $x): float {
    $s = $x < 0 ? -1 : 1; $x = abs($x); $t = 1 / (1 + 0.3275911 * $x);
    $y = 1 - (((((1.061405429 * $t - 1.453152027) * $t) + 1.421413741) * $t - 0.284496736) * $t + 0.254829592) * $t * exp(-$x * $x);
    return $s * $y;
}
function ncdf(float $x, float $m, float $sd): float { return 0.5 * (1 + erf_approx(($x - $m) / ($sd * M_SQRT2))); }
function comb(int $n, int $k): float { $r = 1; for ($i = 1; $i <= $k; $i++) $r = $r * ($n - $k + $i) / $i; return $r; }
function clampf(float $x, float $a, float $b): float { return max($a, min($b, $x)); }

/** Probabilidad de cada marcador en sets (al mejor de $bo) con probabilidad de set $s. */
function set_scores(float $s, int $bo): array {
    $need = intdiv($bo + 1, 2); $out = [];
    for ($l = 0; $l < $need; $l++) $out[] = ['a' => $need, 'b' => $l, 'p' => comb($need - 1 + $l, $l) * $s ** $need * (1 - $s) ** $l];
    for ($w = $need - 1; $w >= 0; $w--) $out[] = ['a' => $w, 'b' => $need, 'p' => comb($need - 1 + $w, $w) * (1 - $s) ** $need * $s ** $w];
    return $out;
}

/* ---------- índices de fuerza ---------- */
function team_ratings(array $finished, int $now): array
{
    $sumW = 0; $sumH = 0; $sumA = 0;
    foreach ($finished as &$g) {
        $days = max(0, ($now - $g['ts']) / 86400);
        $g['w'] = 0.5 ** ($days / HALF_LIFE_DAYS);
        $sumW += $g['w']; $sumH += $g['w'] * $g['hs']; $sumA += $g['w'] * $g['as'];
    }
    unset($g);
    if ($sumW == 0) return ['avgH' => 1, 'avgA' => 1, 'teams' => []];
    $avgH = max(0.1, $sumH / $sumW); $avgA = max(0.1, $sumA / $sumW); $avg = ($avgH + $avgA) / 2;

    $teams = [];
    foreach ($finished as $g) foreach (['home_id', 'away_id'] as $k) $teams[$g[$k]] = ['att' => 1.0, 'def' => 1.0, 'n' => 0];
    foreach ($finished as $g) { $teams[$g['home_id']]['n']++; $teams[$g['away_id']]['n']++; }

    // Varias pasadas para ajustar por la fuerza del rival
    for ($it = 0; $it < 6; $it++) {
        $acc = [];
        foreach ($teams as $id => $t) $acc[$id] = ['sf' => PRIOR_GAMES * $avg, 'ef' => PRIOR_GAMES * $avg, 'sa' => PRIOR_GAMES * $avg, 'ea' => PRIOR_GAMES * $avg];
        foreach ($finished as $g) {
            $h = $g['home_id']; $a = $g['away_id']; $w = $g['w'];
            $acc[$h]['sf'] += $w * $g['hs']; $acc[$h]['ef'] += $w * $avgH * $teams[$a]['def'];
            $acc[$h]['sa'] += $w * $g['as']; $acc[$h]['ea'] += $w * $avgA * $teams[$a]['att'];
            $acc[$a]['sf'] += $w * $g['as']; $acc[$a]['ef'] += $w * $avgA * $teams[$h]['def'];
            $acc[$a]['sa'] += $w * $g['hs']; $acc[$a]['ea'] += $w * $avgH * $teams[$h]['att'];
        }
        foreach ($acc as $id => $x) {
            $teams[$id]['att'] = $x['sf'] / $x['ef'];
            $teams[$id]['def'] = $x['sa'] / $x['ea'];
        }
    }
    return ['avgH' => $avgH, 'avgA' => $avgA, 'teams' => $teams];
}

/* ---------- forma reciente ---------- */
function recent_form(array $finished, $teamId, bool $draws): array
{
    $games = array_values(array_filter($finished, fn($g) => $g['home_id'] == $teamId || $g['away_id'] == $teamId));
    usort($games, fn($x, $y) => $y['ts'] <=> $x['ts']);
    $out = [];
    foreach (array_slice($games, 0, 5) as $g) {
        $mine = $g['home_id'] == $teamId ? $g['hs'] : $g['as'];
        $theirs = $g['home_id'] == $teamId ? $g['as'] : $g['hs'];
        $out[] = $mine > $theirs ? 'w' : ($mine == $theirs && $draws ? 'd' : 'l');
    }
    return $out;
}

function mk(string $name, float $p, array $rule): array { return ['n' => $name, 'p' => round(clampf($p, 0.001, 0.999), 4), 'r' => $rule]; }

/* ---------- modelo por partido ---------- */
function model_match(array $sport, array $R, array $m): ?array
{
    $th = $R['teams'][$m['home_id']] ?? null; $ta = $R['teams'][$m['away_id']] ?? null;
    if (!$th || !$ta || $th['n'] < MIN_GAMES || $ta['n'] < MIN_GAMES) return null;
    $lh = $R['avgH'] * $th['att'] * $ta['def'];
    $la = $R['avgA'] * $ta['att'] * $th['def'];
    $H = $m['home']; $A = $m['away'];

    switch ($sport['model']) {
        case 'poisson_draw': return model_football($lh, $la, $H, $A);
        case 'poisson_ot':   return model_poisson_ot($lh, $la, $H, $A, $sport);
        case 'normal':       return model_normal($lh, $la, $H, $A, $sport);
        case 'sets5':        return model_sets($th, $ta, $R, $H, $A);
    }
    return null;
}

function model_football(float $lh, float $la, string $H, string $A): array
{
    $lh = clampf($lh, 0.2, 4.0); $la = clampf($la, 0.2, 4.0);
    $N = 10; $mat = []; $pH = $pD = $pA = $o15 = $o25 = $u35 = $btts = $hs = $as = 0;
    for ($i = 0; $i < $N; $i++) for ($j = 0; $j < $N; $j++) {
        $p = poisson_pmf($i, $lh) * poisson_pmf($j, $la);
        if ($i < 6 && $j < 6) $mat[$i][$j] = round($p, 4);
        if ($i > $j) $pH += $p; elseif ($i == $j) $pD += $p; else $pA += $p;
        if ($i + $j >= 2) $o15 += $p; if ($i + $j >= 3) $o25 += $p; if ($i + $j <= 3) $u35 += $p;
        if ($i > 0 && $j > 0) $btts += $p; if ($i > 0) $hs += $p; if ($j > 0) $as += $p;
    }
    return [
        'exp' => [["Goles esperados $H", number_format($lh, 2)], ["Goles esperados $A", number_format($la, 2)]],
        'dist' => ['kind' => 'matrix', 'mat' => $mat],
        'markets' => [
            mk("Gana $H", $pH, ['res', 'home']), mk('Empate', $pD, ['res', 'draw']), mk("Gana $A", $pA, ['res', 'away']),
            mk("Doble oportunidad: $H o empate", $pH + $pD, ['dc', '1X']), mk("Doble oportunidad: $A o empate", $pA + $pD, ['dc', 'X2']),
            mk('Doble oportunidad: no hay empate', $pH + $pA, ['dc', '12']),
            mk('Más de 1.5 goles', $o15, ['tot', 'over', 1.5]), mk('Más de 2.5 goles', $o25, ['tot', 'over', 2.5]),
            mk('Menos de 2.5 goles', 1 - $o25, ['tot', 'under', 2.5]), mk('Menos de 3.5 goles', $u35, ['tot', 'under', 3.5]),
            mk('Ambos marcan: sí', $btts, ['btts', true]), mk('Ambos marcan: no', 1 - $btts, ['btts', false]),
            mk("$H marca al menos un gol", $hs, ['tscore', 'home']), mk("$A marca al menos un gol", $as, ['tscore', 'away']),
        ],
    ];
}

function model_poisson_ot(float $lh, float $la, string $H, string $A, array $sport): array
{
    $unit = $sport['unit'] ?? 'puntos'; $T = $lh + $la; $main = floor($T) + 0.5; $gap = $sport['alt'] ?? 1;
    $N = (int)ceil($T * 2.5) + 8; $pH = $pD = $pA = 0; $h15 = $a15 = 0; $tot = array_fill(0, 2 * $N, 0.0);
    for ($i = 0; $i < $N; $i++) for ($j = 0; $j < $N; $j++) {
        $p = poisson_pmf($i, $lh) * poisson_pmf($j, $la);
        if ($i > $j) $pH += $p; elseif ($i == $j) $pD += $p; else $pA += $p;
        if ($i - $j > -2) $h15 += $p; if ($j - $i > -2) $a15 += $p;
        $tot[$i + $j] += $p;
    }
    $share = $lh / ($lh + $la); $wH = $pH + $pD * $share; $wA = $pA + $pD * (1 - $share);
    $pOver = function ($line) use ($tot) { $s = 0; foreach ($tot as $k => $p) if ($k > $line) $s += $p; return $s; };
    $bars = [];
    for ($k = 0; $k <= (int)ceil($T * 2); $k++) $bars[] = ['label' => (string)$k, 'p' => round($tot[$k], 4)];
    return [
        'exp' => [[ucfirst($unit) . " esperados $H", number_format($lh, 1)], [ucfirst($unit) . " esperados $A", number_format($la, 1)]],
        'dist' => ['kind' => 'bars', 'title' => "Total de $unit", 'data' => $bars, 'mark' => $main],
        'markets' => [
            mk("Gana $H", $wH, ['res', 'home']), mk("Gana $A", $wA, ['res', 'away']),
            mk("$H +1.5", $h15, ['hcp', 'home', 1.5]), mk("$A +1.5", $a15, ['hcp', 'away', 1.5]),
            mk("Más de " . ($main - $gap) . " $unit", $pOver($main - $gap), ['tot', 'over', $main - $gap]),
            mk("Más de $main $unit", $pOver($main), ['tot', 'over', $main]),
            mk("Menos de $main $unit", 1 - $pOver($main), ['tot', 'under', $main]),
            mk("Menos de " . ($main + $gap) . " $unit", 1 - $pOver($main + $gap), ['tot', 'under', $main + $gap]),
        ],
    ];
}

function model_normal(float $ph, float $pa, string $H, string $A, array $sport): array
{
    $sd = $sport['sd']; $mean = clampf($ph - $pa + ($sport['home'] ?? 0) * 0.5, -2.2 * $sd, 2.2 * $sd); $tmean = $ph + $pa; $tsd = $sport['tsd'];
    $pH = 1 - ncdf(0, $mean, $sd);
    $fav = $mean >= 0 ? $H : $A; $dog = $mean >= 0 ? $A : $H; $favSide = $mean >= 0 ? 'home' : 'away'; $dogSide = $mean >= 0 ? 'away' : 'home';
    $m = abs($mean); $alt = $sport['alt']; $talt = $sport['talt'];
    $Lf = max(1.5, round($m) - $alt + 0.5); $Ld = round($m) + $alt + 0.5; $T = round($tmean);
    $To = $T - $talt - 0.5; $Tu = $T + $talt + 0.5;
    $bins = []; $step = max(2, round($sd / 3));
    for ($x = -3 * $step * 3; $x <= 3 * $step * 3; $x += $step) $bins[] = ['label' => ($x > 0 ? '+' : '') . $x, 'p' => round(ncdf($x + $step / 2, $mean, $sd) - ncdf($x - $step / 2, $mean, $sd), 4)];
    return [
        'exp' => [["Margen esperado ($H)", ($mean > 0 ? '+' : '') . number_format($mean, 1)], ['Total esperado', number_format($tmean, 0) . ' pts']],
        'dist' => ['kind' => 'bars', 'title' => "Diferencia de puntos a favor de $H", 'data' => $bins, 'mark' => null],
        'markets' => array_merge([
            mk("Gana $H", $pH, ['res', 'home']), mk("Gana $A", 1 - $pH, ['res', 'away']),
            mk("$fav -$Lf", 1 - ncdf($Lf, $m, $sd), ['hcp', $favSide, -$Lf]),
            mk("$dog +$Ld", ncdf($Ld, $m, $sd), ['hcp', $dogSide, $Ld]),
            mk("Más de $To puntos", 1 - ncdf($To, $tmean, $tsd), ['tot', 'over', $To]),
            mk("Menos de $Tu puntos", ncdf($Tu, $tmean, $tsd), ['tot', 'under', $Tu]),
            mk('Más de ' . ($T + 0.5) . ' puntos', 1 - ncdf($T + 0.5, $tmean, $tsd), ['tot', 'over', $T + 0.5]),
        ], empty($sport['periods']) ? [] : model_basket_extra($ph, $pa, $mean, $tmean, $H, $A, $sport)),
    ];
}

/** NBA: más líneas del partido, puntos de cada equipo, mitades y cuartos. */
function model_basket_extra(float $ph, float $pa, float $mean, float $tmean, string $H, string $A, array $sport): array
{
    $sd = $sport['sd']; $tsd = $sport['tsd']; $out = [];
    $fav = $mean >= 0 ? $H : $A; $favSide = $mean >= 0 ? 'home' : 'away'; $dog = $mean >= 0 ? $A : $H; $dogSide = $mean >= 0 ? 'away' : 'home'; $m = abs($mean);
    // hándicaps y totales en escalera
    foreach ([-6, -3, 0, 3, 6] as $d) {
        $L = max(0.5, round($m) + $d + 0.5);
        $out[] = mk("$fav -$L", 1 - ncdf($L, $m, $sd), ['hcp', $favSide, -$L]);
        $out[] = mk("$dog +$L", ncdf($L, $m, $sd), ['hcp', $dogSide, $L]);
    }
    $T = round($tmean);
    foreach ([-15, -10, -5, 5, 10, 15] as $d) {
        $L = $T + $d + 0.5;
        $out[] = mk("Más de $L puntos", 1 - ncdf($L, $tmean, $tsd), ['tot', 'over', $L]);
        $out[] = mk("Menos de $L puntos", ncdf($L, $tmean, $tsd), ['tot', 'under', $L]);
    }
    // puntos de cada equipo
    foreach ([[$H, $ph + ($sport['home'] ?? 0) * 0.25, 'home'], [$A, $pa - ($sport['home'] ?? 0) * 0.25, 'away']] as [$tn, $mu, $side]) {
        foreach ([-8, -4, 0, 4, 8] as $d) {
            $L = round($mu) + $d + 0.5; $po = 1 - ncdf($L, $mu, $tsd * 0.62);
            $out[] = mk("$tn: más de $L puntos", $po, ['tt', $side, 'over', $L]);
            $out[] = mk("$tn: menos de $L puntos", 1 - $po, ['tt', $side, 'under', $L]);
        }
    }
    // mitades y cuartos: [nombre, parte del partido, desvío del margen, desvío del total]
    $per = [['1.ª mitad', 'h1', 0.505, 8.6, 11.5], ['2.ª mitad', 'h2', 0.495, 8.8, 12],
            ['1.er cuarto', 'q1', 0.253, 6.1, 7.6], ['2.º cuarto', 'q2', 0.251, 6.1, 7.6], ['3.er cuarto', 'q3', 0.250, 6.1, 7.6], ['4.º cuarto', 'q4', 0.246, 6.4, 8]];
    foreach ($per as [$pn, $pk, $f, $psd, $ptsd]) {
        $pm = $mean * $f; $pt = $tmean * $f;
        $pH = 1 - ncdf(0.5, $pm, $psd); $pA = ncdf(-0.5, $pm, $psd);
        $out[] = mk("$pn: gana $H", $pH, ['per', $pk, 'res', 'home']);
        $out[] = mk("$pn: gana $A", $pA, ['per', $pk, 'res', 'away']);
        $L = max(0.5, round(abs($pm)) + 0.5); $pf = $pm >= 0 ? $H : $A;
        $out[] = mk("$pn: $pf -$L", 1 - ncdf($L, abs($pm), $psd), ['per', $pk, 'hcp', $pm >= 0 ? 'home' : 'away', -$L]);
        $out[] = mk("$pn: " . ($pm >= 0 ? $A : $H) . " +$L", ncdf($L, abs($pm), $psd), ['per', $pk, 'hcp', $pm >= 0 ? 'away' : 'home', $L]);
        foreach ([-4, 0, 4] as $d) {
            $L = round($pt) + $d + 0.5;
            $out[] = mk("$pn: más de $L puntos", 1 - ncdf($L, $pt, $ptsd), ['per', $pk, 'tot', 'over', $L]);
            $out[] = mk("$pn: menos de $L puntos", ncdf($L, $pt, $ptsd), ['per', $pk, 'tot', 'under', $L]);
        }
    }
    return $out;
}

function model_sets(array $th, array $ta, array $R, string $H, string $A): array
{
    // att = sets ganados frente a lo esperado, def = sets cedidos frente a lo esperado
    $rh = clampf($th['att'] / ($th['att'] + $th['def']), 0.05, 0.95);
    $ra = clampf($ta['att'] / ($ta['att'] + $ta['def']), 0.05, 0.95);
    $s = $rh * (1 - $ra) / ($rh * (1 - $ra) + $ra * (1 - $rh));
    $s = clampf($s + 0.02, 0.03, 0.97); // pequeña ventaja de local
    $d = set_scores($s, 5); $pH = 0; $g4 = 0; $g5 = 0; $hQuick = 0;
    foreach ($d as $x) { if ($x['a'] == 3) $pH += $x['p']; if ($x['a'] + $x['b'] >= 4) $g4 += $x['p']; if ($x['a'] + $x['b'] == 5) $g5 += $x['p']; if ($x['a'] == 3 && $x['b'] <= 1) $hQuick += $x['p']; }
    return [
        'exp' => [["Prob. de ganar un set ($H)", round($s * 100) . '%'], ["Prob. de ganar un set ($A)", round((1 - $s) * 100) . '%']],
        'dist' => ['kind' => 'bars', 'title' => "Marcador final en sets ($H primero)", 'data' => array_map(fn($x) => ['label' => $x['a'] . '-' . $x['b'], 'p' => round($x['p'], 4)], $d), 'mark' => null],
        'markets' => [
            mk("Gana $H", $pH, ['res', 'home']), mk("Gana $A", 1 - $pH, ['res', 'away']),
            mk("$H gana al menos un set", 1 - end($d)['p'], ['sets_one', 'home']), mk("$A gana al menos un set", 1 - $d[0]['p'], ['sets_one', 'away']),
            mk('Más de 3.5 sets', $g4, ['tot', 'over', 3.5]), mk('Menos de 4.5 sets', 1 - $g5, ['tot', 'under', 4.5]),
            mk("$H -1.5 sets", $hQuick, ['hcp', 'home', -1.5]),
        ],
    ];
}

/* ---------- cierre de un pronóstico con el resultado final ---------- */
function settle(array $rule, int $hs, int $as): bool
{
    switch ($rule[0]) {
        case 'res':   return $rule[1] === 'home' ? $hs > $as : ($rule[1] === 'away' ? $as > $hs : $hs == $as);
        case 'dc':    return $rule[1] === '1X' ? $hs >= $as : ($rule[1] === 'X2' ? $as >= $hs : $hs != $as);
        case 'tot':   return $rule[1] === 'over' ? ($hs + $as) > $rule[2] : ($hs + $as) < $rule[2];
        case 'btts':  return ($hs > 0 && $as > 0) === $rule[1];
        case 'tscore':return $rule[1] === 'home' ? $hs > 0 : $as > 0;
        case 'hcp':   return $rule[1] === 'home' ? ($hs - $as + $rule[2]) > 0 : ($as - $hs + $rule[2]) > 0;
        case 'sets_one': return $rule[1] === 'home' ? $hs >= 1 : $as >= 1;
        case 'tt':    $v = $rule[1] === 'home' ? $hs : $as; return $rule[2] === 'over' ? $v > $rule[3] : $v < $rule[3];
    }
    return false;
}

/**
 * Mercado publicado. Solo cuentan los mercados "publicables" (los que las casas ofrecen casi siempre).
 * Con ODDS_API_KEY además se exige cuota real; si hay cuotas se elige el de mejor valor (p × cuota),
 * y en modo 'valor' solo se publica si p × cuota >= valor_min.
 */
function best_pick(array $markets, float $th, ?string $sport = null): ?array
{
    $cfg = $GLOBALS['UMBRAL_CFG'] ?? [];
    $conCuotas = function_exists('odds_activo') && odds_activo();
    $valorMin = ($cfg['cuotas_modo'] ?? 'existe') === 'valor' ? (float)($cfg['valor_min'] ?? 1.0) : null;
    $above = array_values(array_filter($markets, function ($m) use ($th, $conCuotas, $valorMin) {
        if ($m['p'] < $th || in_array($m['r'][0] ?? '', ['per', 'pp'], true)) return false;
        if (!($m['pub'] ?? false)) return false;
        if ($conCuotas && empty($m['q'])) return false;                         // la casa no lo ofrece: no se publica
        if ($valorMin !== null && $m['p'] * ($m['q'] ?? 0) < $valorMin) return false;
        return true;
    }));
    if (!$above) return null;
    if ($conCuotas) { usort($above, fn($a, $b) => ($b['p'] * $b['q']) <=> ($a['p'] * $a['q'])); return $above[0]; }
    usort($above, fn($a, $b) => $a['p'] <=> $b['p']);
    return $above[0];
}
