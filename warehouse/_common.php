<?php
/**
 * Shared bootstrap for the Cloths Warehouse (کالا ګدام) module.
 * Requires: $pdo already available.
 *
 * A "warehouse log" is one movement of cloth stock, per category, measured in
 * two units — Tan (تھان / bolt) and Gaz (ګز / yard):
 *   type = 'in'  → cloths COLLECTED into the warehouse (stock increases)
 *   type = 'out' → cloths DISTRIBUTED from the warehouse (stock decreases)
 *
 * Current stock of a category = SUM(in.tan) − SUM(out.tan)  (and same for gaz).
 */

// ── Auto-migrate: create table on first use ──
$pdo->exec("
    CREATE TABLE IF NOT EXISTS warehouse_logs (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        category     VARCHAR(160)  NOT NULL,
        type         VARCHAR(3)    NOT NULL DEFAULT 'in',
        tan          DECIMAL(14,2) NOT NULL DEFAULT 0,
        gaz          DECIMAL(14,2) NOT NULL DEFAULT 0,
        party_name   VARCHAR(255)  NULL,
        bill_number  VARCHAR(160)  NULL,
        bill_image   VARCHAR(255)  NULL,
        voice_note   VARCHAR(255)  NULL,
        notes        TEXT          NULL,
        entry_date   DATE          NULL,
        created_by   INT           NULL,
        created_at   TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_category (category),
        INDEX idx_type (type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ── The fixed cloth categories (user can also type a Custom one) ──
const WAREHOUSE_CATEGORIES = [
    'کتان ساده',
    'کتان ګلدار',
    'کټان ساده',
    'چینی ګاچ بخمل',
    'کشی ګاچ بخمل',
];

// Gregorian → Solar Hijri (Shamsi). Returns ['y'=>, 'm'=>, 'd'=>].
if (!function_exists('whToShamsi')) {
    function whToShamsi(int $gy, int $gm, int $gd): array {
        $g_d_m = [0,31,59,90,120,151,181,212,243,273,304,334];
        if ($gy > 1600) { $jy = 979; $gy -= 1600; } else { $jy = 0; $gy -= 621; }
        $gy2  = $gm > 2 ? $gy + 1 : $gy;
        $days = 365*$gy + intdiv($gy2+3,4) - intdiv($gy2+99,100) + intdiv($gy2+399,400)
                - 80 + $gd + $g_d_m[$gm - 1];
        $jy  += 33 * intdiv($days, 12053); $days %= 12053;
        $jy  +=  4 * intdiv($days,  1461); $days %= 1461;
        if ($days > 365) { $jy += intdiv($days-1, 365); $days = ($days-1) % 365; }
        $jm = $days < 186 ? 1 + intdiv($days, 31) : 7 + intdiv($days - 186, 30);
        $jd = 1 + ($days < 186 ? $days % 31 : ($days - 186) % 30);
        return ['y' => $jy, 'm' => $jm, 'd' => $jd];
    }
}

// Solar Hijri (Shamsi) → Gregorian. Returns 'Y-m-d'.
if (!function_exists('whShamsiToGregorian')) {
    function whShamsiToGregorian(int $jy, int $jm, int $jd): string {
        $breaks = [-61,9,38,199,426,686,756,818,1111,1181,1210,1635,2060,2097,2192,2262,2324,2394,2456,3178];
        $gy = $jy + 621; $leapJ = -14; $jp = $breaks[0]; $jump = 0;
        for ($i = 1; $i < 20; $i++) {
            $jm2 = $breaks[$i]; $jump = $jm2 - $jp;
            if ($jy < $jm2) break;
            $leapJ += intdiv($jump,33)*8 + intdiv($jump%33,4);
            $jp = $jm2;
        }
        $n = $jy - $jp;
        $leapJ += intdiv($n,33)*8 + intdiv($n%33+3,4);
        if (($jump % 33) === 4 && ($jump - $n) === 4) $leapJ++;
        $leapG = intdiv($gy,4) - intdiv((intdiv($gy,100)+1)*3,4) - 150;
        $march = 20 + $leapJ - $leapG;
        $dayOfYear = $jm <= 6 ? ($jm-1)*31 + $jd : 186 + ($jm-7)*30 + $jd;
        $gDay = $march + $dayOfYear - 1; $gMon = 3; $gYr = $gy;
        $dim = function (int $m, int $y): int {
            if ($m === 2) return (($y%4===0 && $y%100!==0) || $y%400===0) ? 29 : 28;
            return [0,31,28,31,30,31,30,31,31,30,31,30,31][$m];
        };
        while ($gDay > $dim($gMon,$gYr)) { $gDay -= $dim($gMon,$gYr); if (++$gMon > 12) { $gMon = 1; $gYr++; } }
        return sprintf('%04d-%02d-%02d', $gYr, $gMon, $gDay);
    }
}

// Render a compact Shamsi date string (e.g. "1404/04/22") from a date string.
if (!function_exists('whShamsiText')) {
    function whShamsiText(?string $dateStr): string {
        if (empty($dateStr)) return '—';
        $ts = strtotime($dateStr);
        if ($ts === false) return '—';
        $s = whToShamsi((int)date('Y',$ts), (int)date('n',$ts), (int)date('j',$ts));
        return $s['y'] . '/' . str_pad((string)$s['m'],2,'0',STR_PAD_LEFT)
             . '/' . str_pad((string)$s['d'],2,'0',STR_PAD_LEFT);
    }
}

// Known categories = the fixed list + any custom the user has already used.
if (!function_exists('whKnownCategories')) {
    function whKnownCategories(PDO $pdo): array {
        $used = $pdo->query(
            "SELECT DISTINCT category FROM warehouse_logs WHERE category IS NOT NULL AND category != '' ORDER BY category ASC"
        )->fetchAll(PDO::FETCH_COLUMN);
        return array_values(array_unique(array_merge(WAREHOUSE_CATEGORIES, $used)));
    }
}

// A stable accent colour per category so cards feel distinct.
if (!function_exists('whCategoryColor')) {
    function whCategoryColor(string $category): string {
        $known = [
            'کتان ساده'      => '#6366F1',
            'کتان ګلدار'     => '#EC4899',
            'کټان ساده'      => '#06B6D4',
            'چینی ګاچ بخمل'  => '#F59E0B',
            'کشی ګاچ بخمل'   => '#10B981',
        ];
        if (isset($known[$category])) return $known[$category];
        $palette = ['#6366F1','#EC4899','#06B6D4','#F59E0B','#10B981','#EF4444','#8B5CF6','#14B8A6','#F97316','#3B82F6'];
        $sum = 0; $len = strlen($category);
        for ($i = 0; $i < $len; $i++) $sum += ord($category[$i]);
        return $palette[$sum % count($palette)];
    }
}

// Current stock per category (tan & gaz), including categories with zero movement.
// $cond = optional SQL boolean (e.g. a period filter) applied to the movements.
if (!function_exists('whStockByCategory')) {
    function whStockByCategory(PDO $pdo, string $cond = '1=1'): array {
        $rows = $pdo->query("
            SELECT category,
                   COALESCE(SUM(CASE WHEN type='in'  THEN tan ELSE 0 END),0) AS in_tan,
                   COALESCE(SUM(CASE WHEN type='out' THEN tan ELSE 0 END),0) AS out_tan,
                   COALESCE(SUM(CASE WHEN type='in'  THEN gaz ELSE 0 END),0) AS in_gaz,
                   COALESCE(SUM(CASE WHEN type='out' THEN gaz ELSE 0 END),0) AS out_gaz,
                   COUNT(*) AS txn_count,
                   MAX(created_at) AS last_txn
            FROM warehouse_logs
            WHERE $cond
            GROUP BY category
        ")->fetchAll(PDO::FETCH_ASSOC);

        $byCat = [];
        foreach ($rows as $r) $byCat[$r['category']] = $r;

        // Ensure the fixed categories always appear, even at zero stock.
        $ordered = [];
        foreach (WAREHOUSE_CATEGORIES as $c) {
            $r = $byCat[$c] ?? ['category'=>$c,'in_tan'=>0,'out_tan'=>0,'in_gaz'=>0,'out_gaz'=>0,'txn_count'=>0,'last_txn'=>null];
            $ordered[$c] = $r;
        }
        // Then any custom categories the user added.
        foreach ($byCat as $c => $r) if (!isset($ordered[$c])) $ordered[$c] = $r;

        $out = [];
        foreach ($ordered as $r) {
            $r['tan'] = (float)$r['in_tan'] - (float)$r['out_tan'];
            $r['gaz'] = (float)$r['in_gaz'] - (float)$r['out_gaz'];
            $out[] = $r;
        }
        return $out;
    }
}

// Available stock for one category (used to guard distribution).
if (!function_exists('whAvailable')) {
    function whAvailable(PDO $pdo, string $category): array {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(CASE WHEN type='in' THEN tan ELSE -tan END),0) AS tan,
                   COALESCE(SUM(CASE WHEN type='in' THEN gaz ELSE -gaz END),0) AS gaz
            FROM warehouse_logs WHERE category = ?
        ");
        $stmt->execute([$category]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['tan'=>0,'gaz'=>0];
        return ['tan' => (float)$r['tan'], 'gaz' => (float)$r['gaz']];
    }
}

// Format a number without trailing ".00" clutter.
if (!function_exists('whNum')) {
    function whNum($n): string {
        $n = (float)$n;
        return rtrim(rtrim(number_format($n, 2, '.', ','), '0'), '.');
    }
}
