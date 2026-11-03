<?php

namespace App\Services\Timezone;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Shift every stored wall-clock timestamp from one timezone to another.
 *
 * Changing `app_timezone` changes how Eloquent parses a stored value, not the
 * value itself. Rows written before the switch are wall clocks of the old zone,
 * so without this pass they render shifted by the offset between the two zones
 * — including in the audit trail and in API responses, which are supposed to be
 * the durable record.
 *
 * The tables and columns are discovered from the schema, not a hardcoded list:
 * a table added later is covered the day it exists. A table with no single
 * primary key is reported as skipped rather than guessed at.
 *
 * ## Assumptions, both documented in the command
 *
 * - the stored wall clocks are in `$from` in the DATABASE's session timezone;
 * - a wall clock that falls in a DST overlap converts to one of the two
 *   instants, deterministically but not always the one a human meant.
 */
class RebaseTimezones
{
    /**
     * @return array{tables: array<string, array{columns: array<int, string>, rows: int}>, skipped: array<string, string>}
     */
    public function rebase(string $from, string $to, bool $dryRun = false, int $chunk = 500): array
    {
        if (! in_array($from, timezone_identifiers_list(), true)
            || ! in_array($to, timezone_identifiers_list(), true)) {
            throw new InvalidArgumentException('Both timezones must be valid IANA identifiers.');
        }

        $report = ['tables' => [], 'skipped' => []];

        if ($from === $to) {
            return $report;
        }

        $chunk = max(1, $chunk);

        foreach (Schema::getTableListing(null, false) as $table) {
            $columns = array_values(array_filter(
                Schema::getColumns($table),
                static fn (array $column): bool => self::isTimeColumn($column),
            ));

            if ($columns === []) {
                continue;
            }

            $primary = self::primaryKey($table);

            if ($primary === null) {
                $report['skipped'][$table] = 'no single-column primary key';

                continue;
            }

            $names = array_map(static fn (array $column): string => $column['name'], $columns);
            $touched = 0;

            DB::table($table)->orderBy($primary)->chunk(
                $chunk,
                function ($rows) use ($table, $primary, $names, $from, $to, $dryRun, &$touched): void {
                    $pending = [];

                    foreach ($rows as $row) {
                        $update = [];

                        foreach ($names as $name) {
                            $value = $row->{$name};

                            if ($value === null || $value === '') {
                                continue;
                            }

                            $converted = self::convert((string) $value, $from, $to);

                            if ($converted !== null) {
                                $update[$name] = $converted;
                            }
                        }

                        if ($update === []) {
                            continue;
                        }

                        $touched++;
                        $pending[] = [$row->{$primary}, $update];
                    }

                    if ($dryRun || $pending === []) {
                        return;
                    }

                    // One commit per chunk, measured at roughly a third of the
                    // per-row autocommit cost, and a chunk that dies half way
                    // leaves no half-shifted range behind. Deliberately not one
                    // transaction for the whole table: this runs against a live
                    // database, where the lock is the bigger risk.
                    DB::transaction(function () use ($table, $primary, $pending): void {
                        foreach ($pending as [$id, $update]) {
                            DB::table($table)->where($primary, $id)->update($update);
                        }
                    });
                },
            );

            if ($touched > 0) {
                $report['tables'][$table] = ['columns' => $names, 'rows' => $touched];
            }
        }

        return $report;
    }

    /**
     * @param  array{name: string, type: string, type_name: string}  $column
     */
    private static function isTimeColumn(array $column): bool
    {
        $type = strtolower((string) ($column['type_name'] ?? $column['type'] ?? ''));

        return str_contains($type, 'timestamp') || str_contains($type, 'datetime');
    }

    private static function primaryKey(string $table): ?string
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['primary'] ?? false) && count($index['columns']) === 1) {
                return $index['columns'][0];
            }
        }

        return null;
    }

    /**
     * Convert one stored wall clock, or null when it does not parse.
     */
    private static function convert(string $value, string $from, string $to): ?string
    {
        foreach (['Y-m-d H:i:s.u', 'Y-m-d H:i:s'] as $format) {
            // Carbon runs in strict mode here, where a mismatched format throws
            // instead of answering false. `canBeCreatedFromFormat` is the same
            // question with the throw swallowed.
            if (! CarbonImmutable::canBeCreatedFromFormat($value, $format)) {
                continue;
            }

            // Same format back out: a column that stored microseconds must not
            // lose them to the conversion.
            return CarbonImmutable::createFromFormat($format, $value, $from)
                ->setTimezone($to)
                ->format($format);
        }

        return null;
    }
}
