<?php

declare(strict_types=1);

namespace Tests\Arch;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

/**
 * Guards the versioning axis of app/Http/Requests.
 *
 * A Form Request is shared by both channels (Web and Api), so its path carries
 * the version but not the channel. Without this test the next Request quietly
 * lands in app/Http/Requests/User/ and the axis is lost again.
 */
final class RequestVersioningTest extends TestCase
{
    /**
     * Shared behaviour lives beside the versioned tree: traits and concerns are
     * not tied to an API version, so they are exempt.
     *
     * @return array<string, array{string}>
     */
    public static function requestPaths(): array
    {
        $root = realpath(__DIR__.'/../../app/Http/Requests');

        self::assertIsString($root, 'app/Http/Requests does not exist.');

        $paths = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            // The base class deliberately sits at the root; it is asserted
            // separately by it_keeps_only_the_base_request_at_the_root().
            if (! str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'V1'.DIRECTORY_SEPARATOR)) {
                continue;
            }

            $paths[] = $file->getPathname();
        }

        // Fail loudly if the walk finds nothing — a silent zero-file scan is
        // exactly the bug this test exists to prevent.
        self::assertNotEmpty($paths, 'No Form Requests found — the scan is broken, not the structure.');

        return array_combine($paths, array_map(static fn (string $p): array => [$p], $paths));
    }

    /** Path of a Request relative to the Requests root, with forward slashes. */
    private static function relativeToRoot(string $path): string
    {
        $root = realpath(__DIR__.'/../../app/Http/Requests');
        self::assertIsString($root);

        return ltrim(str_replace('\\', '/', substr($path, strlen($root))), '/');
    }

    /**
     * Every Form Request must live under a V<n> directory and declare a
     * namespace matching its path. app/Http/Requests holds Form Requests and
     * nothing else — shared behaviour moved to app/Concerns, so there is no
     * exempt subtree left here to carve out.
     */
        #[DataProvider('requestPaths')]
    public function test_it_places_versioned_requests_under_a_version_directory (string $path): void
    {
        $relative = self::relativeToRoot($path);

        self::assertSame(
            1,
            preg_match('#^V\d+/[^/]+/[^/]+\.php$#', $relative),
            "{$relative} is not shaped as V<n>/<Domain>/<Class>.php"
        );
    }

    /**
     * The base class is the one exception: it sits at the root and carries the
     * error contract for every Request below it.
     */
        public function test_it_keeps_only_the_base_request_at_the_root (): void
    {
        $root = realpath(__DIR__.'/../../app/Http/Requests');
        self::assertIsString($root);

        $atRoot = array_map(
            static fn (string $file): string => pathinfo($file, PATHINFO_FILENAME),
            glob($root.'/*.php') ?: []
        );

        self::assertSame(
            ['BaseFormRequest'],
            $atRoot,
            'app/Http/Requests may only hold BaseFormRequest at its root.'
        );

        // The versioned tree is the only subtree allowed under Requests/: a
        // reintroduced Concerns/ or Traits/ folder would otherwise be skipped
        // by the data provider and never asserted.
        self::assertSame(
            ['V1'],
            array_values(array_diff(scandir($root) ?: [], ['.', '..', 'BaseFormRequest.php'])),
            'app/Http/Requests holds only V1/ and the base class; shared behaviour belongs in app/Concerns.'
        );
    }

    /**
     * Every concrete Request extends the base, so the 422 contract cannot be
     * forgotten by an endpoint that omits the trait.
     */
        public function test_it_gives_every_request_the_shared_error_contract (): void
    {
        foreach (self::requestPaths() as $paths) {
            $path = $paths[0];

            if (basename($path) === 'BaseFormRequest') {
                continue;
            }

            self::assertStringContainsString(
                'extends BaseFormRequest',
                (string) file_get_contents($path),
                "{$path} must extend BaseFormRequest to inherit the 422 contract."
            );
        }
    }

        #[DataProvider('requestPaths')]
    public function test_it_declares_a_namespace_matching_its_path (string $path): void
    {
        // Read the declared namespace rather than relying on the autoloader:
        // a PSR-4 violation must fail this assertion, not raise a fatal error.
        $contents = (string) file_get_contents($path);
        $found = preg_match('/^namespace\s+([^;]+);/m', $contents, $namespaceMatch)
            && preg_match('/^\s*(?:final\s+)?(?:class|trait|interface|enum)\s+(\w+)/m', $contents, $classMatch);

        self::assertTrue($found, "No namespaced type declaration found in {$path}.");

        self::assertSame(
            trim($namespaceMatch[1]).'\\'.$classMatch[1],
            (new ReflectionClass(trim($namespaceMatch[1]).'\\'.$classMatch[1]))->getName(),
            "{$path} is not autoloadable at its declared namespace."
        );

        $namespace = str_replace('/', '\\', dirname(self::relativeToRoot($path)));

        self::assertSame(
            'App\\Http\\Requests'.($namespace === '.' ? '' : '\\'.$namespace),
            trim($namespaceMatch[1]),
            "{$path} declares namespace ".trim($namespaceMatch[1]).', but its path implies '.$namespace
        );
    }

    /**
     * The class inside the file must carry the file name, otherwise the
     * autoloader cannot find it. Traits and interfaces are held to the same
     * rule.
     */
        #[DataProvider('requestPaths')]
    public function test_it_names_each_class_after_its_file (string $path): void
    {
        $contents = (string) file_get_contents($path);
        $declared = preg_match('/^\s*(?:final\s+)?(?:class|trait|interface|enum)\s+(\w+)/m', $contents, $matches);

        self::assertSame(1, $declared, "No type declaration found in {$path}.");
        self::assertSame(
            pathinfo($path, PATHINFO_FILENAME),
            $matches[1],
            "{$matches[1]} does not match its file name {$path}."
        );
    }
}
