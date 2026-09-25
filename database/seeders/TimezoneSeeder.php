<?php

namespace Database\Seeders;

use App\Models\Timezone;
use DateTimeZone;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TimezoneSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed timezone reference data from the Aisense timezone service.
     */
    public function run(): void
    {
        $response = Http::acceptJson()
            ->timeout(15)
            ->retry(3, 200)
            ->get('https://aisenseapi.com/services/v1/timezones')
            ->throw();

        $timezones = $response->json('timezones');
        if (!is_array($timezones) || $timezones === []) {
            throw new RuntimeException('Aisense timezone response is empty or invalid.');
        }

        $now = now();

        foreach ($timezones as $timezone) {
            $name = $timezone['timezone'] ?? null;
            $offset = $timezone['offset'] ?? null;

            if (!is_string($name) || !is_string($offset) || !in_array($name, DateTimeZone::listIdentifiers(), true)) {
                throw new RuntimeException('Aisense returned an invalid timezone entry.');
            }

            $offsetLabel = $this->formatOffset($offset);

            Timezone::updateOrCreate(
                ['name' => $name],
                [
                    'label' => sprintf('%s (%s)', $name, $offsetLabel),
                    'utc_offset' => $offsetLabel,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }

    /**
     * Convert Aisense's +HHMM offset to a readable UTC offset.
     */
    private function formatOffset(string $offset): string
    {
        if (!preg_match('/^([+-])(\d{2})(\d{2})$/', $offset, $matches)) {
            throw new RuntimeException("Invalid timezone offset: {$offset}");
        }

        return sprintf('UTC %s%s:%s', $matches[1], $matches[2], $matches[3]);
    }
}
