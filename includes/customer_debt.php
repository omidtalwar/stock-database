<?php
/** Invoice balances less unallocated payments, using each invoice's frozen rate. */
function customerDebtByCurrency(array $sales, array $payments, array $rates): array {
    $debt = [];
    foreach (['AFN', 'USD', 'PKR'] as $currency) {
        $debt[$currency] = ['orig' => 0.0, 'afn' => 0.0, 'cnt' => 0];
    }
    foreach ($sales as $sale) {
        $currency = $sale['currency'] ?? 'AFN';
        if (!isset($debt[$currency])) continue;
        $balance = max(0.0, (float)$sale['total_amount'] - (float)$sale['paid_amount']);
        if ($balance <= 0.01) continue;
        $rate = !empty($sale['exchange_rate']) ? (float)$sale['exchange_rate'] : ($rates[$currency] ?? 1.0);
        $debt[$currency]['orig'] += $currency === 'AFN' ? $balance : fromAFN($balance, $rate);
        $debt[$currency]['afn'] += $balance;
        $debt[$currency]['cnt']++;
    }
    foreach ($payments as $payment) {
        $currency = $payment['currency'] ?? 'AFN';
        if (!empty($payment['inv_id']) || !isset($debt[$currency])) continue;
        $afn = (float)$payment['amount_afn'] ?: (float)$payment['amount'];
        $debt[$currency]['orig'] = max(0.0, $debt[$currency]['orig'] - (float)$payment['amount']);
        $debt[$currency]['afn'] = max(0.0, $debt[$currency]['afn'] - $afn);
    }
    foreach ($debt as &$bucket) {
        if ($bucket['orig'] < 0.01) $bucket = ['orig' => 0.0, 'afn' => 0.0, 'cnt' => 0];
    }
    unset($bucket);
    return $debt;
}
