<?php

namespace Database\Seeders;

use App\Models\Currency;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * The currencies an operator may select on /settings.
 *
 * A static list, deliberately: `TimezoneSeeder` fills its table from
 * aisenseapi.com because the IANA set is long and changes with every tzdata
 * release. This one is a dozen rows that never move, and a price column that
 * depends on that endpoint would be blank every time it is down.
 */
class CurrencySeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * code => [name, symbol, decimal separator, thousands separator].
     *
     * @var array<string, array<int, string>>
     */
    private const CURRENCIES = [
        'IDR' => ['Indonesian Rupiah', 'Rp', ',', '.'],
        'USD' => ['US Dollar', '$', '.', ','],
        'EUR' => ['Euro', '€', '.', ','],
        'GBP' => ['Pound Sterling', '£', '.', ','],
        'AUD' => ['Australian Dollar', 'A$', '.', ','],
        'SGD' => ['Singapore Dollar', 'S$', '.', ','],
        'MYR' => ['Malaysian Ringgit', 'RM', '.', ','],
        'THB' => ['Thai Baht', '฿', '.', ','],
        'PHP' => ['Philippine Peso', '₱', '.', ','],
        'VND' => ['Vietnamese Dong', '₫', '.', ','],
        'INR' => ['Indian Rupee', '₹', '.', ','],
        'CNY' => ['Chinese Yuan', '¥', '.', ','],
        'JPY' => ['Japanese Yen', '¥', '.', ','],
        'KRW' => ['Korean Won', '₩', '.', ','],
        'HKD' => ['Hong Kong Dollar', 'HK$', '.', ','],
        'BRL' => ['Brazilian Real', 'R$', ',', '.'],
    ];

    public function run(): void
    {
        $now = now();

        foreach (self::CURRENCIES as $code => [$name, $symbol, $decimal, $thousands]) {
            Currency::updateOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'symbol' => $symbol,
                    'decimal_separator' => $decimal,
                    'thousands_separator' => $thousands,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        // The rows just changed; a reader in this same process must not keep
        // answering from the set as it was before the write.
        Currency::bustCache();
    }
}
