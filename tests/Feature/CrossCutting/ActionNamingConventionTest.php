<?php

namespace Tests\Feature\CrossCutting;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Enforces the Domain-First Action naming standard.
 *
 * The rule is documented in docs/base/architecture/naming-conventions.md:
 * every class in app/Actions/V1/ is `{Domain}{Operation}Action`, where the
 * domain prefix repeats the folder name.
 *
 * This exists because a naming rule nobody checks is a naming rule that decays.
 * The failure it prevents is concrete — `AssignRolesAction` and
 * `UserAssignRolesAction` sort into different greps, so half the callers look
 * missing and someone adds a duplicate instead of finding the real one.
 */
class ActionNamingConventionTest extends TestCase
{
    /**
     * Every Action, Handler, Processor and Request in a domain folder must be
     * prefixed with that folder's name.
     *
     * This reads the DECLARED class name, not the file name. The two are
     * normally identical, but a rename that updates the class body and forgets
     * the file (or the reverse) is exactly the drift this must catch, and only
     * the declaration is authoritative.
     */
    public function test_every_action_is_named_domain_first(): void
    {
        $violations = [];

        foreach (glob(base_path('app/Actions/V1/*/*.php')) ?: [] as $path) {
            $domain = basename(dirname($path));
            $declared = self::declaredClassName((string) file_get_contents($path));

            if ($declared === null) {
                continue;
            }

            if (! str_starts_with($declared, $domain)) {
                $violations[] = $domain.'/'.$declared.' ('.basename($path).')';
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Action classes must be named {Domain}{Operation}Action, matching their "
            ."folder. Offenders: ".implode(', ', $violations)
            .'. See docs/base/architecture/naming-conventions.md.'
        );
    }

    /**
     * PSR-4: the class name must equal the file name, or the autoloader
     * cannot find it and the failure surfaces as "class not found" at runtime
     * rather than as a naming problem here.
     */
    public function test_action_class_name_matches_its_file_name(): void
    {
        $violations = [];

        foreach (self::phpFilesIn(base_path('app/Actions')) as $path) {
            $declared = self::declaredClassName((string) file_get_contents($path));

            if ($declared === null) {
                continue;
            }

            if ($declared !== basename($path, '.php')) {
                $violations[] = basename($path).' declares '.$declared;
            }
        }

        $this->assertSame([], $violations, 'PSR-4 violation: '.implode('; ', $violations));
    }

    /**
     * A DI property is camelCase. A property named after its class type is
     * PascalCase and breaks the convention stated above it.
     */
    public function test_di_properties_are_camel_case(): void
    {
        $violations = [];

        foreach (self::phpFilesIn(base_path('app/Http/Controllers')) as $path) {
            $contents = (string) file_get_contents($path);

            foreach (preg_split('/\R/', $contents) ?: [] as $line) {
                if (preg_match('/\$this->([A-Z]\w*)/', $line, $m)) {
                    $violations[] = basename($path).': $this->'.$m[1];
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            'DI properties must be camelCase: '.implode(', ', $violations)
        );
    }

    /**
     * Every .php file under a directory, at any depth.
     *
     * Deliberately not a glob pattern: glob has no recursive mode, so a
     * double-star there is one literal path segment, not "any depth". That
     * pattern silently matches nothing and the calling assertion passes
     * without ever reading a file.
     *
     * @return list<string>
     */
    private static function phpFilesIn(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $found = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $found[] = $file->getPathname();
            }
        }

        sort($found);

        return $found;
    }

    /**
     * Read the class/interface/trait name a file declares, or null if it
     * declares none.
     */
    private static function declaredClassName(string $contents): ?string
    {
        return preg_match(
            '/^\s*(?:final\s+|abstract\s+)?(?:class|interface|trait|enum)\s+(\w+)/m',
            $contents,
            $m
        ) ? $m[1] : null;
    }
}
