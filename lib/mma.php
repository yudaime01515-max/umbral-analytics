<?php
/**
 * UFC / MMA desde ESPN (API pública no oficial, sin clave).
 * - Guarda las peleas en public/data/mma_db.json (el historial viejo se pide una sola vez).
 * - Nivel de cada peleador con un sistema Elo que se ajusta con cada pelea ganada o perdida.
 * - Dos mercados: quién gana, y si la pelea llega a la decisión de los jueces.
 *
 * Probado con 2 788 peleas de UFC (2022 a nov. 2026), prediciendo cada pelea solo con lo anterior:
 *   - el favorito del modelo ganó 59 %; cuando marcó 65-70 % ganó 67 %, y 70-75 % ganó 69 %.
 *   - en MMA casi nunca hay favoritos de 75 % o más: es un deporte con mucha sorpresa.
 * El récord de cada peleador se muestra, pero NO entra al cálculo: ESPN da el récord actual también
 * en peleas pasadas, así que no se puede comprobar con el historial sin hacer trampa.
 */

const MMA_K = 160;        // cuánto mueve el nivel una pelea (peleador nuevo); baja con la experiencia
const MMA_FROM = '2022-01-01';
const MMA_CLASES = [
    'Strawweight' => 'Peso paja', 'Flyweight' => 'Peso mosca', 'Bantamweight' => 'Peso gallo', 'Featherweight' => 'Peso pluma',
    'Lightweight' => 'Peso ligero', 'Welterweight' => 'Peso wélter', 'Middleweight' => 'Peso medio',
    'Light Heavyweight' => 'Peso semipesado', 'Heavyweight' => 'Peso pesado', 'Catch Weight' => 'Peso pactado', 'Catchweight' => 'Peso pactado',
];

function mma_clase(string $c): string
{
    $w = (bool)preg_match('/^W(omen\'?s)?\s+/i', $c);
    $base = trim(preg_replace('/^W(omen\'?s)?\s+/i', '', $c));
    $es = MMA_CLASES[$base] ?? $base;
    return $es === '' ? '' : $es . ($w ? ' femenino' : '');
}

/** Peleas de un rango de fechas (AAAAMMDD-AAAAMMDD). Devuelve null si ESPN no respondió. */
function mma_range(string $league, string $range, array &$players): ?array
{
    $b = (getenv('ESPN_SPORTS_BASE') ?: 'https://site.api.espn.com/apis/site/v2/sports') . "/mma/$league/scoreboard?dates=$range&limit=500";
    $j = espn_fetch($b);
    if ($j === null) { usleep(600000); $j = espn_fetch($b); }
    if ($j === null || !isset($j['events'])) return null;
    $out = [];
    foreach ($j['events'] as $ev) {
        $evName = (string)($ev['name'] ?? $ev['shortName'] ?? strtoupper($league));
        foreach ($ev['competitions'] ?? [] as $c) {
            $cs = $c['competitors'] ?? [];
            if (count($cs) !== 2 || empty($c['id'])) continue;
            usort($cs, fn($x, $y) => ($x['order'] ?? 0) <=> ($y['order'] ?? 0));
            $ids = []; $ok = true;
            foreach ($cs as $k => $p) {
                $a = $p['athlete'] ?? [];
                $id = (string)($p['id'] ?? $a['id'] ?? '');
                $name = (string)($a['displayName'] ?? $a['fullName'] ?? '');
                if ($id === '' || $name === '' || stripos($name, 'TBD') !== false || stripos($name, 'TBA') !== false) { $ok = false; break; }
                $ids[$k] = $id;
                $players[$id] = [$name, $a['flag']['href'] ?? ($players[$id][1] ?? ''), (string)($p['records'][0]['summary'] ?? ($players[$id][2] ?? ''))];
            }
            if (!$ok) continue;
            $st = $c['status'] ?? []; $t = $st['type'] ?? [];
            $name = strtoupper((string)($t['name'] ?? '')); $state = (string)($t['state'] ?? 'pre');
            $rounds = (int)($c['format']['regulation']['periods'] ?? 3) ?: 3;
            $w = null; $dist = null;
            if (strpos($name, 'CANCEL') !== false || strpos($name, 'POSTPON') !== false) $status = 'void';
            elseif (!empty($t['completed']) || $state === 'post') {
                $wa = ($cs[0]['winner'] ?? null) === true; $wb = ($cs[1]['winner'] ?? null) === true;
                if ($wa === $wb) $status = 'void';                      // empate o sin resultado: se anula
                else {
                    $status = 'FT'; $w = $wa ? 0 : 1;
                    // Llegó a la decisión: se peleó hasta el último segundo del último asalto pactado
                    $dist = ((int)($st['period'] ?? 0) >= $rounds && (float)($st['clock'] ?? 0) >= 299) ? 1 : 0;
                }
            }
            elseif ($state === 'in') $status = 'LIVE';
            else $status = 'NS';
            $out[(string)$c['id']] = [(int)strtotime($c['date'] ?? $ev['date'] ?? ''), $ids[0], $ids[1], $status, $w, $dist, $rounds, (string)($c['type']['abbreviation'] ?? $c['type']['text'] ?? ''), $evName];
        }
    }
    return $out;
}

function mma_run(array $cfg, array $sport, int $now, int $startToday, int $endWindow, string $dataDir): array
{
    $path = "$dataDir/mma_db.json";
    $db = is_file($path) ? (json_decode(file_get_contents($path), true) ?: []) : [];
    $db += ['ranges' => [], 'f' => [], 'p' => []];
    $leagues = array_map(fn($l) => $l['code'], array_filter($sport['leagues'], fn($l) => !empty($l['on'])));
    $done = array_flip($db['ranges']);
    $read = 0; $fails = 0;
    $freshFrom = $startToday - 21 * 86400;

    foreach ($leagues as $lg) {
        // 1) Historial: por semestres, cada uno se pide una sola vez
        for ($y = (int)substr(MMA_FROM, 0, 4); $y <= (int)gmdate('Y', $now); $y++) {
            foreach ([['0101', '0630'], ['0701', '1231']] as $h) {
                $from = strtotime("$y-" . substr($h[0], 0, 2) . '-' . substr($h[0], 2) . ' UTC');
                $to = strtotime("$y-" . substr($h[1], 0, 2) . '-' . substr($h[1], 2) . ' UTC');
                if ($from > $freshFrom) continue;
                $key = "$lg:$y$h[0]";
                $closed = $to < $freshFrom;                 // semestre ya terminado: no vuelve a cambiar
                if (isset($done[$key])) continue;
                $got = mma_range($lg, $y . $h[0] . '-' . gmdate('Ymd', min($to, $freshFrom)), $db['p']);
                usleep(300000);
                if ($got === null) { $fails++; continue; }
                $read++;
                foreach ($got as $id => $f) $db['f']["$lg-$id"] = $f;
                if ($closed) $done[$key] = 1;
            }
        }
        // 2) Lo reciente y lo que viene: siempre. Lo que ya no aparece en la cartelera (pelea caída) se borra.
        $got = mma_range($lg, gmdate('Ymd', $freshFrom) . '-' . gmdate('Ymd', $now + 45 * 86400), $db['p']);
        if ($got === null) { $fails++; continue; }
        $read++;
        foreach ($db['f'] as $id => $f) if (strpos($id, "$lg-") === 0 && $f[0] >= $freshFrom && $f[3] !== 'FT') unset($db['f'][$id]);
        foreach ($got as $id => $f) $db['f']["$lg-$id"] = $f;
    }
    $db['ranges'] = array_keys($done);
    $db['updated'] = $now;
    // La base solo guarda a quienes aparecen en alguna pelea
    $used = [];
    foreach ($db['f'] as $f) { $used[$f[1]] = 1; $used[$f[2]] = 1; }
    $db['p'] = array_intersect_key($db['p'], $used);
    file_put_contents("$path.tmp", json_encode($db, JSON_UNESCAPED_UNICODE));
    rename("$path.tmp", $path);
    if ($fails && !$read) {
        $GLOBALS['UMBRAL_ERRORS'] = ($GLOBALS['UMBRAL_ERRORS'] ?? 0) + 1;
        log_line('ERROR mma (ESPN): ' . ($GLOBALS['ESPN_LAST'] ?? 'sin respuesta'));
    }

    // 3) Nivel Elo y costumbre de llegar a la decisión, pelea por pelea en orden
    $elo = []; $n = []; $form = []; $dn = []; $dd = []; $cS = []; $cN = []; $gS = 0; $gN = 0;
    $fin = array_filter($db['f'], fn($f) => $f[3] === 'FT' && $f[0] < $now);
    uasort($fin, fn($x, $y) => $x[0] <=> $y[0]);
    foreach ($fin as $f) {
        [, $a, $b, , $w, $d, , $cls] = $f;
        $ea = $elo[$a] ?? 1500.0; $eb = $elo[$b] ?? 1500.0;
        $exp = 1 / (1 + 10 ** (($eb - $ea) / 400));
        $res = $w === 0 ? 1 : 0;
        $ka = MMA_K / sqrt(($n[$a] ?? 0) + 3); $kb = MMA_K / sqrt(($n[$b] ?? 0) + 3);
        $elo[$a] = $ea + $ka * ($res - $exp); $elo[$b] = $eb - $kb * ($res - $exp);
        $n[$a] = ($n[$a] ?? 0) + 1; $n[$b] = ($n[$b] ?? 0) + 1;
        $form[$a][] = $res ? 'w' : 'l'; $form[$b][] = $res ? 'l' : 'w';
        $d = (int)$d;
        $dn[$a] = ($dn[$a] ?? 0) + 1; $dn[$b] = ($dn[$b] ?? 0) + 1; $dd[$a] = ($dd[$a] ?? 0) + $d; $dd[$b] = ($dd[$b] ?? 0) + $d;
        $cS[$cls] = ($cS[$cls] ?? 0) + $d; $cN[$cls] = ($cN[$cls] ?? 0) + 1; $gS += $d; $gN++;
    }
    $logit = fn(float $x) => log($x / (1 - $x));

    // 4) Resultados para cerrar pronósticos y peleas próximas
    $final = []; $out = [];
    foreach ($db['f'] as $id => $f) {
        [$ts, $a, $b, $st, $w, $d, $rounds, $cls, $evName] = $f;
        $mid = "mma-espn-$id";
        $H = $db['p'][$a][0] ?? '?'; $A = $db['p'][$b][0] ?? '?';
        if ($st === 'FT') $final[$mid] = ['hs' => $w === 0 ? 1 : 0, 'as' => $w === 1 ? 1 : 0, 'dist' => (bool)$d, 'status' => 'final',
            'txt' => 'Ganó ' . ($w === 0 ? $H : $A) . ($d ? ' por decisión' : ' antes del límite')];
        elseif ($st === 'void') $final[$mid] = ['status' => 'void'];
        if ($st !== 'NS' || $ts < $startToday || $ts >= $endWindow) continue;
        $ea = $elo[$a] ?? 1500.0; $eb = $elo[$b] ?? 1500.0;
        $pH = clampf(1 / (1 + 10 ** (($eb - $ea) / 400)), 0.05, 0.95);
        // Decisión: promedio de la categoría, corregido a medias por la costumbre de cada peleador
        $cr = (($cN[$cls] ?? 0) >= 30) ? $cS[$cls] / $cN[$cls] : ($gN ? $gS / $gN : 0.47);
        $base = clampf($rounds >= 5 ? $cr * 0.9 : $cr, 0.1, 0.9);
        $fr = fn($x) => (($dd[$x] ?? 0) + $base * 4) / (($dn[$x] ?? 0) + 4);
        $pD = clampf(1 / (1 + exp(-($logit($base) + 0.5 * ($logit($fr($a)) + $logit($fr($b)) - 2 * $logit($base))))), 0.12, 0.88);
        $rec = fn($x) => ($db['p'][$x][2] ?? '') !== '' ? $db['p'][$x][2] : '—';
        $clase = mma_clase($cls);
        $out[] = [
            'id' => $mid, 'sport' => 'mma', 'league' => $evName, 'ts' => $ts,
            'home' => $H, 'away' => $A, 'logoH' => $db['p'][$a][1] ?? '', 'logoA' => $db['p'][$b][1] ?? '',
            'hid' => $a, 'aid' => $b,
            'formH' => array_reverse(array_slice($form[$a] ?? [], -5)), 'formA' => array_reverse(array_slice($form[$b] ?? [], -5)),
            'exp' => array_values(array_filter([
                $clase !== '' ? ['Categoría', $clase . " · $rounds asaltos"] : null,
                ['Récord (victorias-derrotas-empates)', $rec($a) . ' vs ' . $rec($b)],
                ['Nivel (Elo)', round($ea) . ' vs ' . round($eb)],
                ['Peleas en UFC desde 2022', ($n[$a] ?? 0) . ' vs ' . ($n[$b] ?? 0)],
            ])),
            'dist' => ['kind' => 'bars', 'title' => 'Cómo termina la pelea', 'data' => [
                ['label' => 'Decisión', 'p' => round($pD, 4)], ['label' => 'Antes del límite', 'p' => round(1 - $pD, 4)]], 'mark' => null],
            'markets' => [
                mk("Gana $H", $pH, ['res', 'home']), mk("Gana $A", 1 - $pH, ['res', 'away']),
                mk('La pelea llega a la decisión', $pD, ['dist', 'yes']), mk('La pelea termina antes del límite', 1 - $pD, ['dist', 'no']),
            ],
        ];
    }
    log_line('OK mma: ' . count($fin) . ' peleas en la base, ' . count($out) . " por pelearse ($read consultas)");
    return ['matches' => $out, 'final' => $final];
}
