<?php

namespace Tests\Arch;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Docs-tree integrity, gated (Phase 16 E1).
 *
 * Phase 16 spent its A–D groups finding doc drift by hand — broken relative
 * links, a heading that skipped a level, tables mangled by a stray leading
 * pipe. Every one of those defects is mechanical, so the check that keeps
 * them from coming back is mechanical too. This is the same walk the A2
 * sweep ran, pinned as a gate: two fence-aware scans over every Markdown
 * file under `docs/`, a single iterator, no app boot beyond the base test
 * case.
 *
 * Deliberately NOT gated: fenced blocks without an info string (measured
 * 2026-11-04: 101 of 187 — that is house style, not a defect), external
 * URLs (no network in CI), and intra-file anchors.
 */
class DocumentationLinkIntegrityTest extends TestCase
{
    /**
     * Relative links that resolve to nothing, as "file:line -> target".
     *
     * @return list<string>
     */
    private function brokenLinks(): array
    {
        $broken = [];

        foreach ($this->markdownFiles() as $file) {
            $inFence = false;

            foreach (explode("\n", file_get_contents($file)) as $number => $line) {
                if (preg_match('/^\s*(```|~~~)/', $line)) {
                    $inFence = ! $inFence;

                    continue;
                }

                if ($inFence) {
                    continue;
                }

                foreach (self::LINK_PATTERN as $pattern) {
                    if (! preg_match_all($pattern, $line, $matches)) {
                        continue;
                    }

                    foreach ($matches[1] as $target) {
                        if ($this->shouldResolve($target)) {
                            $this->recordIfMissing($broken, $file, $number, $target);
                        }
                    }
                }
            }
        }

        return $broken;
    }

    /**
     * Heading level jumps (h1 → h3), ignoring `#` comment lines inside fences.
     *
     * @return list<string>
     */
    private function headingSkips(): array
    {
        $skips = [];

        foreach ($this->markdownFiles() as $file) {
            $inFence = false;
            $previous = 0;

            foreach (explode("\n", file_get_contents($file)) as $number => $line) {
                if (preg_match('/^\s*(```|~~~)/', $line)) {
                    $inFence = ! $inFence;

                    continue;
                }

                if ($inFence || ! preg_match('/^(#{1,6}) /', $line, $m)) {
                    continue;
                }

                $level = strlen($m[1]);

                if ($previous > 0 && $level > $previous + 1) {
                    $skips[] = $file.':'.($number + 1)." (h{$previous} -> h{$level})";
                }

                $previous = $level;
            }
        }

        return $skips;
    }

    /** @return list<string> */
    private function markdownFiles(): array
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(base_path('docs'), FilesystemIterator::SKIP_DOTS),
        );

        $files = [];

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'md') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private const LINK_PATTERN = [
        '/\[[^\]]*\]\(([^)\s]+)\)/',
        '/\[[^\]]*\]:\s*(\S+)/',
    ];

    private function shouldResolve(string $target): bool
    {
        // http(s)/mailto are external; a bare or leading-# anchor lives in the
        // page itself; braces mean a template placeholder, not a path.
        return ! str_starts_with($target, '#')
            && ! preg_match('#^[a-z+.-]+://#i', $target)
            && ! str_starts_with($target, 'mailto:')
            && ! str_contains($target, '{');
    }

    private function recordIfMissing(array &$broken, string $file, int $number, string $target): void
    {
        $path = explode('#', $target)[0];

        if ($path === '') {
            return;
        }

        $resolved = str_starts_with($path, '/')
            ? base_path(ltrim($path, '/'))
            : dirname($file).'/'.$path;

        if (! file_exists($resolved)) {
            $broken[] = basename($file).':'.($number + 1)." -> {$target}";
        }
    }

    public function test_every_relative_link_in_the_docs_tree_resolves(): void
    {
        $this->assertSame(
            [],
            $this->brokenLinks(),
            'Relative links in docs/ point at files that do not exist. Fix the path or remove the link.'
        );
    }

    public function test_heading_hierarchy_never_skips_a_level(): void
    {
        $this->assertSame(
            [],
            $this->headingSkips(),
            'A heading jumped more than one level — outside code fences, `###` must follow `##`.'
        );
    }

    /**
     * A table row that begins with two pipes renders as a broken table with
     * an empty first cell. This corruption arrived twice through scripted
     * edits (Phases 15–16), which is why it is a gate and not a grep.
     */
    public function test_no_table_row_begins_with_a_double_pipe(): void
    {
        $broken = [];

        foreach ($this->markdownFiles() as $file) {
            $inFence = false;

            foreach (explode("\n", file_get_contents($file)) as $number => $line) {
                if (preg_match('/^\s*(```|~~~)/', $line)) {
                    $inFence = ! $inFence;

                    continue;
                }

                if (! $inFence && str_starts_with($line, '||')) {
                    $broken[] = basename($file).':'.($number + 1);
                }
            }
        }

        $this->assertSame([], $broken, 'Table rows start with `||` — one leading pipe is correct.');
    }
}
