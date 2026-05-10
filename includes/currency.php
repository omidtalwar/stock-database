<?php
// Currency symbols
const CURRENCIES = [
    'AFN' => ['symbol' => '؋',  'name' => 'Afghani',          'decimals' => 0],
    'USD' => ['symbol' => '$',  'name' => 'US Dollar',         'decimals' => 2],
    'PKR' => ['symbol' => '₨', 'name' => 'Pakistani Rupee',   'decimals' => 0],
];

function getSettings(PDO $pdo): array {
    return $pdo->query("SELECT `key`, `value` FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
}

function getExchangeRate(PDO $pdo): float {
    $v = $pdo->query("SELECT `value` FROM settings WHERE `key`='exchange_rate'")->fetchColumn();
    return $v ? (float)$v : 90.0;
}

function getSecondaryCurrency(PDO $pdo): string {
    $v = $pdo->query("SELECT `value` FROM settings WHERE `key`='secondary_currency'")->fetchColumn();
    return $v ?: 'USD';
}

// Returns AFN-per-1-unit for every currency: ['AFN'=>1, 'USD'=>65, 'PKR'=>0.25]
function getAllRates(PDO $pdo): array {
    $pairs = $pdo->query(
        "SELECT `key`, `value` FROM settings WHERE `key` IN ('rate_usd','rate_pkr','exchange_rate')"
    )->fetchAll(PDO::FETCH_KEY_PAIR);
    $usd = (float)($pairs['rate_usd'] ?? $pairs['exchange_rate'] ?? 90.0);
    $pkr = (float)($pairs['rate_pkr'] ?? 0.25);
    return ['AFN' => 1.0, 'USD' => $usd, 'PKR' => $pkr];
}

function currencySymbol(string $currency): string {
    return CURRENCIES[$currency]['symbol'] ?? $currency;
}

function formatMoney(float $amount, string $currency = 'AFN'): string {
    $sym = currencySymbol($currency);
    $dec = CURRENCIES[$currency]['decimals'] ?? 0;
    return $sym . ' ' . number_format($amount, $dec);
}

function formatAFN(float $amount): string {
    return '؋ ' . number_format($amount, 0);
}

function formatUSD(float $amount): string {
    return '$ ' . number_format($amount, 2);
}

// Convert any currency amount to AFN
function toAFN(float $amount, string $currency, float $rate): float {
    return $currency === 'AFN' ? $amount : round($amount * $rate, 2);
}

// Convert AFN amount to secondary currency
function fromAFN(float $amountAFN, float $rate): float {
    return $rate > 0 ? round($amountAFN / $rate, 2) : 0.0;
}

// Render a small dual-currency badge: primary + secondary equivalent
function dualBadge(float $amountAFN, float $rate, string $secCurrency = 'USD'): string {
    $afn = formatAFN($amountAFN);
    $sec = formatMoney(fromAFN($amountAFN, $rate), $secCurrency);
    return $afn . ' <small class="text-muted fw-normal">≈ ' . $sec . '</small>';
}
