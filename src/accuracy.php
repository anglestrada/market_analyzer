<?php
/**
 * Model accuracy dashboard data (public/accuracy.php).
 *
 * Reads the scored predictions in prediction_results (one row per fight per model version per source) and joins
 * the ESPN fight (method, round, per-fighter stats) where it is known. Fighter A = the fighter the row's
 * model_p is for; outcome 1 = A won, NULL = draw / no contest (not counted in accuracy).
 */
declare(strict_types=1);

const ACCURACY_SOURCES = ['all' => 'All sources', 'live' => 'Live', 'backtest' => 'Backtest', 'history' => 'History'];
const ACCURACY_SOURCE_PRIORITY = ['live' => 0, 'backtest' => 1, 'history' => 2];   // "All" keeps the first one per fight
const ACCURACY_METHODS = ['KO/TKO', 'Submission', 'Decision', 'Other'];
const ACCURACY_KALSHI_COLOR = '#26d382';   // same green as the Kalshi line on the other pages (C.yes in app.js)

/** One colour per model version: v1 blue, v2 violet, then amber, red, … (new versions get the next one). */
function accuracy_version_colors(array $versions): array
{
    $palette = ['#8fb4d6', '#b4a7d6', '#e2b35d', '#f07a7a', '#7ccba6', '#d9b382', '#c9ced0'];
    $out = [];
    foreach (array_values($versions) as $i => $v) {
        $out[$v] = $palette[$i % count($palette)];
    }
    return $out;
}

/** "KO/TKO (Punches)" → KO/TKO, "Decision - Split" → Decision, "Submission" → Submission. */
function accuracy_method(?string $method): ?string
{
    $m = strtolower(trim((string) $method));
    if ($m === '') {
        return null;
    }
    return match (true) {
        str_contains($m, 'ko') || str_contains($m, 'doctor') || str_contains($m, 'stoppage') => 'KO/TKO',
        str_contains($m, 'sub')                                                          => 'Submission',
        str_contains($m, 'dec')                                                          => 'Decision',
        default                                                                          => 'Other',
    };
}

/**
 * Scored rows for these versions. $source 'all' = one row per fight per version (live > backtest > history).
 * @return list<array>
 */
function accuracy_rows(PDO $db, array $versions, string $source = 'all'): array
{
    if (!$versions) {
        return [];
    }
    $in = implode(',', array_map(fn($v) => $db->quote((string) $v), $versions));
    $sql = "SELECT r.id, r.event_id, r.market_id, r.model_version, r.source, r.cutoff_at, r.model_p, r.kalshi_p,
                   r.outcome, r.result_type, r.method AS r_method, r.end_round AS r_round,
                   r.model_brier, r.model_logloss, r.model_correct, r.kalshi_brier, r.kalshi_logloss, r.kalshi_correct,
                   r.fighter_id, r.opponent_id, COALESCE(r.fight_id, mf.upcoming_fight_id) AS fight_id,
                   fa.full_name AS a_name, fb.full_name AS b_name, m.yes_subtitle, m.no_subtitle, e.event_title,
                   fi.event_name, fi.method AS f_method, fi.method_detail, fi.end_round AS f_round, fi.end_clock_seconds,
                   fi.weight_class, fi.is_title, fi.league
              FROM prediction_results r
         LEFT JOIN market_fighters mf ON mf.market_id = r.market_id
         LEFT JOIN fights fi   ON fi.id = COALESCE(r.fight_id, mf.upcoming_fight_id)
         LEFT JOIN fighters fa ON fa.id = r.fighter_id
         LEFT JOIN fighters fb ON fb.id = r.opponent_id
         LEFT JOIN markets m   ON m.id = r.market_id
         LEFT JOIN events e    ON e.id = r.event_id
             WHERE r.model_version IN ($in)" . ($source !== 'all' ? ' AND r.source = :s' : '') . '
          ORDER BY r.cutoff_at, r.id';
    $stmt = $db->prepare($sql);
    $stmt->execute($source !== 'all' ? [':s' => $source] : []);

    $keep = [];
    foreach ($stmt->fetchAll() as $r) {
        $r['key'] = $r['fight_id'] !== null ? 'f' . $r['fight_id'] : 'e' . $r['event_id'];
        $id = $r['model_version'] . '|' . $r['key'];
        $old = $keep[$id] ?? null;
        if ($old === null || ACCURACY_SOURCE_PRIORITY[$r['source']] < ACCURACY_SOURCE_PRIORITY[$old['source']]) {
            $keep[$id] = $r;
        }
    }
    $rows = array_values($keep);
    foreach ($rows as &$r) {
        $r['model_p']   = (float) $r['model_p'];
        $r['kalshi_p']  = $r['kalshi_p'] !== null ? (float) $r['kalshi_p'] : null;
        $r['outcome']   = $r['outcome'] !== null ? (int) $r['outcome'] : null;
        $r['correct']   = $r['model_correct'] !== null ? (float) $r['model_correct'] : null;
        $r['k_correct'] = $r['kalshi_correct'] !== null ? (float) $r['kalshi_correct'] : null;
        $r['a']         = $r['a_name'] ?: ($r['yes_subtitle'] ?: 'Fighter A');
        $r['b']         = $r['b_name'] ?: ($r['no_subtitle'] ?: 'Fighter B');
        $r['method']    = $r['f_method'] ?: $r['r_method'];
        $r['method_cat'] = accuracy_method($r['method']);
        $r['round']     = $r['f_round'] !== null ? (int) $r['f_round'] : ($r['r_round'] !== null ? (int) $r['r_round'] : null);
    }
    unset($r);
    usort($rows, fn($x, $y) => strcmp((string) $x['cutoff_at'], (string) $y['cutoff_at']) ?: $x['id'] <=> $y['id']);
    return $rows;
}

/** Totals for a set of rows (one version). Kalshi numbers are on the same fights that have a Kalshi price. */
function accuracy_summary(array $rows): array
{
    $s = ['fights' => 0, 'correct' => 0, 'incorrect' => 0, 'ties' => 0, 'draws' => 0, 'acc' => null,
          'brier' => null, 'logloss' => null, 'k_fights' => 0, 'k_acc' => null, 'k_brier' => null,
          'm_acc_k' => null, 'skill' => null, 'last10' => null, 'first' => null, 'last' => null];
    $sum = $brier = $ll = 0.0;
    $mk = $kk = $mb = $kb = 0.0;
    $recent = [];
    foreach ($rows as $r) {
        if ($r['outcome'] === null || $r['correct'] === null) {
            $s['draws']++;
            continue;
        }
        $s['fights']++;
        $sum += $r['correct'];
        $brier += (float) $r['model_brier'];
        $ll += (float) $r['model_logloss'];
        match (true) {
            $r['correct'] >= 1.0 => $s['correct']++,
            $r['correct'] <= 0.0 => $s['incorrect']++,
            default              => $s['ties']++,
        };
        $recent[] = $r['correct'];
        if ($r['k_correct'] !== null) {
            $s['k_fights']++;
            $mk += $r['correct'];
            $kk += $r['k_correct'];
            $mb += (float) $r['model_brier'];
            $kb += (float) $r['kalshi_brier'];
        }
        $s['first'] ??= $r['cutoff_at'];
        $s['last'] = $r['cutoff_at'];
    }
    if ($s['fights'] > 0) {
        $s['acc']     = $sum / $s['fights'];
        $s['brier']   = $brier / $s['fights'];
        $s['logloss'] = $ll / $s['fights'];
        $tail = array_slice($recent, -10);
        $s['last10']  = array_sum($tail) / count($tail);
        $s['last10_n'] = count($tail);
    }
    if ($s['k_fights'] > 0) {
        $s['k_acc']   = $kk / $s['k_fights'];
        $s['m_acc_k'] = $mk / $s['k_fights'];
        $s['k_brier'] = $kb / $s['k_fights'];
        $s['m_brier_k'] = $mb / $s['k_fights'];
        $s['skill']   = $kb > 0 ? 1 - $mb / $kb : null;
    }
    return $s;
}

/** Accuracy per finish type: ['KO/TKO' => ['n' => , 'acc' => ], …] (only methods that occur). */
function accuracy_by_method(array $rows): array
{
    $out = [];
    foreach ($rows as $r) {
        if ($r['correct'] === null) {
            continue;
        }
        $cat = $r['method_cat'] ?? 'Unknown';
        $out[$cat] ??= ['n' => 0, 'sum' => 0.0, 'k_n' => 0, 'k_sum' => 0.0];
        $out[$cat]['n']++;
        $out[$cat]['sum'] += $r['correct'];
        if ($r['k_correct'] !== null) {
            $out[$cat]['k_n']++;
            $out[$cat]['k_sum'] += $r['k_correct'];
        }
    }
    $order = array_flip([...ACCURACY_METHODS, 'Unknown']);
    uksort($out, fn($a, $b) => ($order[$a] ?? 99) <=> ($order[$b] ?? 99));
    foreach ($out as &$m) {
        $m['acc']   = $m['sum'] / $m['n'];
        $m['k_acc'] = $m['k_n'] ? $m['k_sum'] / $m['k_n'] : null;
    }
    return $out;
}

/** How sure the model was (its pick's chance) vs how often that pick won. */
function accuracy_calibration(array $rows): array
{
    $buckets = ['50–60%' => [0.5, 0.6], '60–70%' => [0.6, 0.7], '70–80%' => [0.7, 0.8], '80%+' => [0.8, 1.01]];
    $out = [];
    foreach ($buckets as $label => [$lo, $hi]) {
        $out[$label] = ['n' => 0, 'p' => 0.0, 'won' => 0.0];
    }
    foreach ($rows as $r) {
        if ($r['correct'] === null) {
            continue;
        }
        $conf = max($r['model_p'], 1 - $r['model_p']);
        foreach ($buckets as $label => [$lo, $hi]) {
            if ($conf >= $lo && $conf < $hi) {
                $out[$label]['n']++;
                $out[$label]['p'] += $conf;
                $out[$label]['won'] += $r['correct'];
            }
        }
    }
    foreach ($out as &$b) {
        $b['avg_p'] = $b['n'] ? $b['p'] / $b['n'] : null;
        $b['hit']   = $b['n'] ? $b['won'] / $b['n'] : null;
    }
    return $out;
}

/** Running accuracy after each fight: [{x: ISO time, y: %, n: fights so far, c: correct so far}]. */
function accuracy_cumulative(array $rows, string $field = 'correct'): array
{
    $pts = [];
    $n = 0;
    $sum = 0.0;
    foreach ($rows as $r) {
        if ($r[$field] === null) {
            continue;
        }
        $n++;
        $sum += $r[$field];
        $pts[] = ['x' => gmdate('c', strtotime((string) $r['cutoff_at'])), 'y' => round($sum / $n * 100, 1), 'n' => $n];
    }
    return $pts;
}

/** Correct / incorrect per month: [{m: '2026-05', c: 3, i: 1}] */
function accuracy_monthly(array $rows): array
{
    $out = [];
    foreach ($rows as $r) {
        if ($r['correct'] === null) {
            continue;
        }
        $m = gmdate('Y-m', strtotime((string) $r['cutoff_at']));
        $out[$m] ??= ['m' => $m, 'c' => 0, 'i' => 0];
        $out[$m]['c'] += $r['correct'];        // an exact 50% pick counts half each way
        $out[$m]['i'] += 1 - $r['correct'];
    }
    ksort($out);
    return array_values($out);
}

/** Per-fighter totals from fight_stats: [fight_id][fighter_id] => [...] */
function accuracy_fight_stats(PDO $db, array $fightIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $fightIds))));
    if (!$ids) {
        return [];
    }
    $rows = $db->query(
        'SELECT fight_id, fighter_id, sig_strikes_landed, sig_strikes_attempted, total_strikes_landed, knockdowns,
                takedowns_landed, takedowns_attempted, control_seconds, submission_attempts
           FROM fight_stats WHERE fight_id IN (' . implode(',', $ids) . ')'
    )->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[(int) $r['fight_id']][(int) $r['fighter_id']] = $r;
    }
    return $out;
}

/**
 * Fights for the table, newest first: each fight once, with every version's pick on it.
 * @return list<array{key:string, row:array, picks:array<string,array>}>
 */
function accuracy_fight_list(array $rows): array
{
    $fights = [];
    foreach ($rows as $r) {
        $f = &$fights[$r['key']];
        $f ??= ['key' => $r['key'], 'row' => $r, 'picks' => []];
        // Keep fighter A consistent across versions (history rows can have A/B the other way round).
        $flip = (int) $r['fighter_id'] !== (int) $f['row']['fighter_id'] && (int) $r['fighter_id'] === (int) $f['row']['opponent_id'];
        $p = $flip ? 1 - $r['model_p'] : $r['model_p'];
        $f['picks'][$r['model_version']] = ['p' => $p, 'correct' => $r['correct'], 'source' => $r['source']];
        if ($f['row']['kalshi_p'] === null && $r['kalshi_p'] !== null) {
            $f['row']['kalshi_p'] = $flip ? 1 - $r['kalshi_p'] : $r['kalshi_p'];
            $f['row']['k_correct'] = $r['k_correct'];
        }
        unset($f);
    }
    $list = array_values($fights);
    usort($list, fn($x, $y) => strcmp((string) $y['row']['cutoff_at'], (string) $x['row']['cutoff_at']));
    return $list;
}

/** The part of the finish after its type: "KO/TKO (Punches)" → Punches, "Decision - Split" → Split. */
function accuracy_finish_detail(?string $method, ?string $detail): string
{
    $m = trim((string) $method);
    $extra = '';
    if (preg_match('/\(([^)]+)\)\s*$/', $m, $mm)) {
        $extra = $mm[1];
    } elseif (preg_match('/\s[-–]\s*(.+)$/', $m, $mm)) {
        $extra = $mm[1];
    } elseif (accuracy_method($m) === 'Other') {
        $extra = $m;
    }
    $detail = trim((string) $detail);
    if ($detail !== '' && stripos($extra, $detail) === false) {
        $extra = $extra === '' ? $detail : "$extra, $detail";
    }
    return $extra;
}

/** "R2 3:20" from round + clock seconds. */
function accuracy_round(?int $round, mixed $clockSeconds): string
{
    if ($round === null) {
        return '';
    }
    $s = 'R' . $round;
    if ($clockSeconds !== null && $clockSeconds !== '') {
        $c = (int) $clockSeconds;
        $s .= ' ' . intdiv($c, 60) . ':' . str_pad((string) ($c % 60), 2, '0', STR_PAD_LEFT);
    }
    return $s;
}
