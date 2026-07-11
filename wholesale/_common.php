<?php
/**
 * Shared bootstrap for the Wholesale (goods in/out by location) module.
 * Requires: $pdo already available.
 *
 * A "wholesale log" is one movement of clothing goods either INTO or OUT of
 * our holdings, tagged with the location (mall/market) it came from or went to
 * — e.g. Kabul, China, Peshawar, or a custom place.
 */

// ── Auto-migrate: create table on first use, add columns if missing ──
$pdo->exec("
    CREATE TABLE IF NOT EXISTS wholesale_logs (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        location     VARCHAR(120)  NOT NULL,
        item_name    VARCHAR(255)  NULL,
        category     VARCHAR(120)  NULL,
        type         VARCHAR(3)    NOT NULL DEFAULT 'in',
        quantity     DECIMAL(12,2) NOT NULL DEFAULT 0,
        bundle_count INT           NULL DEFAULT 0,
        unit_price   DECIMAL(14,2) NULL DEFAULT 0,
        total_value  DECIMAL(16,2) NULL DEFAULT 0,
        currency     VARCHAR(10)   NULL DEFAULT 'USD',
        notes        TEXT          NULL,
        entry_date   DATE          NULL,
        created_by   INT           NULL,
        created_at   TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_location (location),
        INDEX idx_type (type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Outgoing entries carry no location (deducted from the overall total),
// so allow NULL on existing installs where the column was NOT NULL.
try { $pdo->exec("ALTER TABLE wholesale_logs MODIFY location VARCHAR(120) NULL"); } catch (\PDOException $e) {}

// Default suggested locations (user can type any custom place too)
const WHOLESALE_DEFAULT_LOCATIONS = ['Kabul', 'China', 'Lahore', 'Gami'];

// Gregorian → Solar Hijri (Shamsi). Returns ['y'=>, 'm'=>, 'd'=>].
if (!function_exists('wsToShamsi')) {
    function wsToShamsi(int $gy, int $gm, int $gd): array {
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

// Render a Shamsi date cell (Shamsi line + small Gregorian line) from a date string
if (!function_exists('wsShamsiCell')) {
    function wsShamsiCell(?string $dateStr): string {
        if (empty($dateStr)) return '<span class="text-muted">—</span>';
        $ts = strtotime($dateStr);
        if ($ts === false) return '<span class="text-muted">—</span>';
        $s = wsToShamsi((int)date('Y', $ts), (int)date('n', $ts), (int)date('j', $ts));
        return '<div>' . $s['y'] . '/' . str_pad((string)$s['m'],2,'0',STR_PAD_LEFT)
             . '/' . str_pad((string)$s['d'],2,'0',STR_PAD_LEFT) . '</div>'
             . '<div style="font-size:0.68rem;color:#bbb;">' . date('d M Y', $ts) . '</div>';
    }
}

// Solar Hijri (Shamsi) → Gregorian. Returns 'Y-m-d'.
if (!function_exists('wsShamsiToGregorian')) {
    function wsShamsiToGregorian(int $jy, int $jm, int $jd): string {
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

// Resolve a period key (+ optional custom Shamsi dates from $_GET) into a
// Gregorian [start, end] inclusive range. Returns [start|null, end|null, label].
if (!function_exists('wsResolvePeriod')) {
    function wsResolvePeriod(string $period, array $q): array {
        $today = date('Y-m-d');
        $t     = wsToShamsi((int)date('Y'), (int)date('n'), (int)date('j'));
        switch ($period) {
            case 'today':
                return [$today, $today, 'Today'];
            case 'week':
                // Afghan week starts Saturday
                $sinceSat  = ((int)date('N') + 1) % 7;
                $weekStart = date('Y-m-d', strtotime("-$sinceSat days"));
                return [$weekStart, $today, 'This Week'];
            case 'month':
                $start = wsShamsiToGregorian($t['y'], $t['m'], 1);
                $nm = $t['m'] + 1; $ny = $t['y']; if ($nm > 12) { $nm = 1; $ny++; }
                $end = date('Y-m-d', strtotime(wsShamsiToGregorian($ny, $nm, 1) . ' -1 day'));
                return [$start, $end, 'This Month'];
            case 'year':
                $start = wsShamsiToGregorian($t['y'], 1, 1);
                $end   = date('Y-m-d', strtotime(wsShamsiToGregorian($t['y'] + 1, 1, 1) . ' -1 day'));
                return [$start, $end, 'Year ' . $t['y']];
            case 'custom':
                $fy = (int)($q['cf_y'] ?? 0); $fm = (int)($q['cf_m'] ?? 0); $fd = (int)($q['cf_d'] ?? 0);
                $ty = (int)($q['ct_y'] ?? 0); $tm = (int)($q['ct_m'] ?? 0); $td = (int)($q['ct_d'] ?? 0);
                if ($fy >= 1300 && $fm >= 1 && $fm <= 12 && $fd >= 1 && $fd <= 31
                 && $ty >= 1300 && $tm >= 1 && $tm <= 12 && $td >= 1 && $td <= 31) {
                    $start = wsShamsiToGregorian($fy, $fm, $fd);
                    $end   = wsShamsiToGregorian($ty, $tm, $td);
                    if ($start > $end) [$start, $end] = [$end, $start];
                    return [$start, $end, 'Custom'];
                }
                return [null, null, 'All Time'];
            default:
                return [null, null, 'All Time'];
        }
    }
}

// Known locations = defaults + any the user has already used
if (!function_exists('wsKnownLocations')) {
    function wsKnownLocations(PDO $pdo): array {
        $used = $pdo->query(
            "SELECT DISTINCT location FROM wholesale_logs WHERE location IS NOT NULL AND location != '' ORDER BY location ASC"
        )->fetchAll(PDO::FETCH_COLUMN);
        return array_values(array_unique(array_merge(WHOLESALE_DEFAULT_LOCATIONS, $used)));
    }
}

// A stable accent colour per location so cards feel distinct
if (!function_exists('wsLocationColor')) {
    function wsLocationColor(string $location): string {
        $palette = ['#14B8A6','#0067C0','#C084FC','#FB923C','#F87171','#34D399','#FBBF24','#60A5FA','#F472B6','#A78BFA'];
        $known = ['Kabul' => '#14B8A6', 'China' => '#F87171', 'Lahore' => '#34D399', 'Gami' => '#FB923C'];
        if (isset($known[$location])) return $known[$location];
        $sum = 0; $len = strlen($location);
        for ($i = 0; $i < $len; $i++) $sum += ord($location[$i]);
        return $palette[$sum % count($palette)];
    }
}
