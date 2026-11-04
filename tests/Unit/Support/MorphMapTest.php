<?php

namespace Tests\Unit\Support;

use App\Support\MorphMap;
use Illuminate\Database\Eloquent\Model;
use Tests\TestCase;

/**
 * `App\Support\MorphMap` is read by two consumers that must never disagree: the
 * provider that registers the map with Eloquent, and the backfill migration
 * that rewrites the FQCNs already in `activity_log`. A literal written twice
 * drifts, and it drifts silently in the dangerous direction — the map says
 * `user`, the backfill wrote `App\Models\User`, and every new row reads as an
 * unmapped type.
 *
 * The aliases are also the stored value, not a display hint: `subject_type`
 * holds them, and the export writes them into a file handed to an operator. So
 * a class name in this map is a leak, not a style issue.
 */
class MorphMapTest extends TestCase
{
    /**
     * @return array<string, class-string<Model>>
     */
    private function aliases(): array
    {
        $aliases = MorphMap::aliasToClass();

        $this->assertNotEmpty($aliases, 'the morph map is empty');

        return $aliases;
    }

    public function test_every_alias_is_a_stored_spelling_and_maps_to_a_model(): void
    {
        foreach ($this->aliases() as $alias => $class) {
            $this->assertMatchesRegularExpression(
                '/^[a-z][a-z0-9_]*$/',
                $alias,
                "`{$alias}` is not an alias — a stored class name here leaks the application namespace"
            );

            $this->assertTrue(
                is_subclass_of($class, Model::class),
                "`{$alias}` maps to `{$class}`, which is not an Eloquent model"
            );
        }
    }

    public function test_the_two_inverses_are_actually_inverse(): void
    {
        $aliases = $this->aliases();
        $classes = MorphMap::classToAlias();

        $this->assertCount(count($aliases), $classes, 'two aliases point at the same class, so the backfill would pick one');

        foreach ($aliases as $alias => $class) {
            $this->assertArrayHasKey($class, $classes, "the backfill cannot map `{$class}` back to an alias");
            $this->assertSame($alias, $classes[$class], "`{$class}` maps back to the wrong alias");
        }
    }

    /**
     * The observable consequence: what gets STORED is the alias. This also
     * proves the provider registered the map — an unregistered map changes
     * nothing and `getMorphClass()` answers with the FQCN.
     */
    public function test_each_mapped_model_stores_its_alias(): void
    {
        foreach ($this->aliases() as $alias => $class) {
            $this->assertSame(
                $alias,
                (new $class())->getMorphClass(),
                "`{$class}` does not store `{$alias}` — the map is declared but not registered"
            );
        }
    }
}
