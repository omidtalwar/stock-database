<?php
// Currency symbols
const CURRENCIES = [
    'AFN' => ['symbol' => '؋',  'name' => 'Afghani',        'decimals' => 0, 'flag' => '🇦🇫'],
    'USD' => ['symbol' => '$',  'name' => 'US Dollar',       'decimals' => 2, 'flag' => '🇺🇸'],
    'PKR' => ['symbol' => '₨', 'name' => 'Pakistani Rupee', 'decimals' => 0, 'flag' => '🇵🇰'],
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

/**
 * Freeze each sale to the exchange rate that applied when it was made, so
 * changing the live rate in Settings never re-prices past invoices.
 * Adds sales.exchange_rate once, then backfills any NULLs:
 *   - AFN sales        → 1 (no rate dependency)
 *   - foreign currency → the rate logged on/before the sale date
 *   - no log history   → the current rate (so they stop drifting)
 * Idempotent and cheap after the first run (later calls update 0 rows).
 */
function ensureSaleRates(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try { $pdo->exec("ALTER TABLE sales ADD COLUMN exchange_rate DECIMAL(12,4) NULL AFTER currency"); } catch (\Throwable $e) {}
    try {
        // AFN sales never depend on a rate.
        $pdo->exec("UPDATE sales SET exchange_rate = 1
                    WHERE exchange_rate IS NULL AND (currency IS NULL OR currency = 'AFN')");
        // Foreign-currency sales: the rate active on the sale date (from the change log).
        $pdo->exec("
            UPDATE sales s
            SET s.exchange_rate = (
                SELECT l.rate FROM exchange_rate_log l
                WHERE l.currency = s.currency
                  AND l.changed_at < (COALESCE(s.sale_date, DATE(s.created_at)) + INTERVAL 1 DAY)
                ORDER BY l.changed_at DESC LIMIT 1
            )
            WHERE s.exchange_rate IS NULL AND s.currency IS NOT NULL AND s.currency <> 'AFN'
        ");
        // Anything still unresolved (no log history) → freeze at the current rate.
        $rates = getAllRates($pdo);
        $upd = $pdo->prepare("UPDATE sales SET exchange_rate = ? WHERE exchange_rate IS NULL AND currency = ?");
        $upd->execute([$rates['USD'], 'USD']);
        $upd->execute([$rates['PKR'], 'PKR']);
    } catch (\Throwable $e) { /* non-fatal: display falls back to the live rate */ }
}

function currencySymbol(string $currency): string {
    return CURRENCIES[$currency]['symbol'] ?? $currency;
}

function currencyFlag(string $currency): string {
    return CURRENCIES[$currency]['flag'] ?? '';
}

function formatMoney(float $amount, string $currency = 'AFN'): string {
    $flag = CURRENCIES[$currency]['flag'] ?? '';
    $sym  = CURRENCIES[$currency]['symbol'] ?? $currency;
    $dec  = CURRENCIES[$currency]['decimals'] ?? 0;
    return $flag . ' ' . $sym . ' ' . number_format($amount, $dec);
}

function formatAFN(float $amount): string {
    return '🇦🇫 ؋ ' . number_format($amount, 0);
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
