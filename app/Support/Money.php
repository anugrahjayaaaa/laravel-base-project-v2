<?php

namespace App\Support;

use App\Models\Currency;
use App\Models\SystemSetting;

/**
 * Money display for the currency the operator configured.
 *
 * One place, because the rules are three separate facts: which currency
 * (`currency_code`), what symbol it prints, and which separators it writes.
 * Reading them here means a price column and an earning-rule line cannot
 * disagree about the same amount.
 */
class Money
{
    /**
     * Format an amount for a reader — "Rp 35.000,00", never a bare number.
     *
     * `shortcut:` the amount is shown in the configured currency with no
     * conversion. If a tier ever stores a different currency than the app
     * displays, this is where a rate lookup has to appear.
     */
    public static function format(float|string $amount): string
    {
        $code = SystemSetting::getString('currency_code', 'IDR');
        $currency = Currency::forCode($code);

        // A code with no seeded row (a settings value written before the
        // currency table existed) still prints: the code becomes the symbol and
        // the local convention is assumed rather than dropped. Written as an
        // instanceof branch, not `$currency?->symbol ?? $code`, because the
        // lookup's cached map is typed non-null and a static analyser reads the
        // null-safe access as dead code.
        $symbol = $currency instanceof Currency ? $currency->symbol : $code;
        $decimal = $currency instanceof Currency ? $currency->decimal_separator : ',';
        $thousands = $currency instanceof Currency ? $currency->thousands_separator : '.';

        return $symbol.' '.number_format((float) $amount, 2, $decimal, $thousands);
    }

    /**
     * The symbol alone, for a field label that names the unit ("Price (Rp)").
     */
    public static function symbol(): string
    {
        $code = SystemSetting::getString('currency_code', 'IDR');
        $currency = Currency::forCode($code);

        return $currency instanceof Currency ? $currency->symbol : $code;
    }
}
