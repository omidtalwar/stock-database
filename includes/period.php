<?php
/**
 * Shared time-period filter for list/report pages (Stock log, Accessories,
 * Cloths Warehouse). Mirrors the dashboard's Today / This Week / This Month /
 * All Time / Custom bar. Reads ?period, ?date_from, ?date_to from the query.
 *
 * Usage:
 *   $pf   = periodResolve();                                  // default = All Time
 *   $cond = periodCond("COALESCE(entry_date, DATE(created_at))", $pf);
 *   ... "WHERE $cond"  (or "AND ($cond)")
 *   echo periodBar($pf, ['q' => $q]);                         // Bootstrap bar
 *
 * $cond is always a safe boolean fragment: period is whitelisted and the two
 * custom dates are validated to Y-m-d in periodResolve(), so it can be inlined.
 */

if (!function_exists('periodResolve')) {
    /** Read + validate the period selection from the query string. */
    function periodResolve(string $default = 'all'): array {
        $period = in_array($_GET['period'] ?? '', ['today','week','month','all','custom'], true)
            ? $_GET['period'] : $default;
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_from'] ?? '') ? $_GET['date_from'] : date('Y-m-01');
        $to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_to']   ?? '') ? $_GET['date_to']   : date('Y-m-d');
        return ['period' => $period, 'from' => $from, 'to' => $to];
    }
}

if (!function_exists('periodCond')) {
    /**
     * SQL boolean restricting $dateExpr (a column or expression such as
     * COALESCE(entry_date, DATE(created_at))) to the selected period.
     * Returns "1=1" for All Time.
     */
    function periodCond(string $dateExpr, array $pf): string {
        switch ($pf['period']) {
            case 'today':  return "$dateExpr = CURDATE()";
            case 'week':   return "YEARWEEK($dateExpr,1) = YEARWEEK(CURDATE(),1)";
            case 'month':  return "DATE_FORMAT($dateExpr,'%Y-%m') = DATE_FORMAT(CURDATE(),'%Y-%m')";
            case 'custom': return "$dateExpr BETWEEN '{$pf['from']}' AND '{$pf['to']}'";
            default:       return "1=1";
        }
    }
}

if (!function_exists('periodLabelList')) {
    /** [key => [icon, label]] for the five period buttons. */
    function periodLabelList(): array {
        return [
            'today'  => ['bi-sun',            function_exists('__') ? __('period_today') : 'Today'],
            'week'   => ['bi-calendar-week',  function_exists('__') ? __('period_week')  : 'This Week'],
            'month'  => ['bi-calendar-month', function_exists('__') ? __('period_month') : 'This Month'],
            'all'    => ['bi-infinity',       function_exists('__') ? __('period_all')   : 'All Time'],
            'custom' => ['bi-calendar-range', 'Custom'],
        ];
    }
}

if (!function_exists('periodBar')) {
    /**
     * Bootstrap period-filter bar (buttons + custom range form).
     * $keep = extra GET params to preserve on every link (e.g. ['q' => $q]).
     */
    function periodBar(array $pf, array $keep = []): string {
        $keepQ = '';
        foreach ($keep as $k => $v) {
            if ($v !== '' && $v !== null) $keepQ .= '&' . urlencode($k) . '=' . urlencode((string)$v);
        }
        if ($pf['period'] === 'custom') {
            $keepQ .= '&date_from=' . urlencode($pf['from']) . '&date_to=' . urlencode($pf['to']);
        }

        $h = '<div class="d-flex align-items-center gap-1 flex-wrap mb-3">';
        foreach (periodLabelList() as $pk => [$icon, $lbl]) {
            $cls = $pf['period'] === $pk ? 'btn-primary' : 'btn-light border';
            $h  .= '<a href="?period=' . $pk . $keepQ . '" class="btn btn-sm ' . $cls . '">'
                 . '<i class="bi ' . $icon . ' me-1"></i>' . htmlspecialchars($lbl) . '</a>';
        }
        $h .= '</div>';

        if ($pf['period'] === 'custom') {
            $hidden = '';
            foreach ($keep as $k => $v) {
                if ($v !== '' && $v !== null) {
                    $hidden .= '<input type="hidden" name="' . htmlspecialchars($k) . '" value="' . htmlspecialchars((string)$v) . '">';
                }
            }
            $h .= '<form method="GET" class="d-flex align-items-center gap-2 flex-wrap mb-3">'
                . '<input type="hidden" name="period" value="custom">' . $hidden
                . '<label class="small fw-semibold text-muted mb-0">From</label>'
                . '<input type="date" name="date_from" value="' . htmlspecialchars($pf['from']) . '" class="form-control form-control-sm" style="width:150px;" required>'
                . '<label class="small fw-semibold text-muted mb-0">To</label>'
                . '<input type="date" name="date_to" value="' . htmlspecialchars($pf['to']) . '" class="form-control form-control-sm" style="width:150px;" required>'
                . '<button class="btn btn-sm btn-primary"><i class="bi bi-arrow-right me-1"></i>Apply</button>'
                . '</form>';
        }
        return $h;
    }
}
