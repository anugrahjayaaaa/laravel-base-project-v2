<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('code', 3)->unique();
            $table->string('name');
            $table->string('symbol', 8);
            // Written per row instead of assumed by the formatter: the display
            // convention differs between the currencies the operator can pick,
            // and `Rp 35.000,00` is wrong for a `$` amount.
            $table->char('decimal_separator', 1);
            $table->char('thousands_separator', 1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }
};
