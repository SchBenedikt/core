<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Checks relative Markdown links and images without network access.
 *
 * Usage: php tools/check-links.php [path ...]
 *
 * With no arguments every Markdown file in the repository is checked. Fenced
 * code and inline code are ignored because they often contain examples that
 * are not links. Absolute URLs, mail links and pure anchors are out of scope.
 */

$root = dirname(__DIR__);

/**
 * @return list<string>
 */
function markdownFiles(string $root, array $arguments): array
{
	if ($arguments !== []) {
		$files = [];
		foreach ($arguments as $argument) {
			$path = str_starts_with($argument, '/') ? $argument : $root . '/' . $argument;
			if (!is_file($path)) {
				fwrite(STDERR, "not a file: {$argument}\n");
				continue;
			}
			$files[] = $path;
		}

		return $files;
	}

	$files = [];
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator(
			$root,
			FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS,
		),
	);

	foreach ($iterator as $file) {
		if (!$file instanceof SplFileInfo || $file->getExtension() !== 'md') {
			continue;
		}

		$path = $file->getPathname();
		$relative = substr($path, strlen($root) + 1);
		if (str_starts_with($relative, 'vendor/')
			|| str_starts_with($relative, '.git/')
			|| str_starts_with($relative, 'node_modules/')) {
			continue;
		}

		$files[] = $path;
	}

	sort($files);

	return $files;
}

/**
 * @return list<array{line: int, target: string}>
 */
function linksIn(string $path): array
{
	$contents = file_get_contents($path);
	if ($contents === false) {
		throw new RuntimeException('could not read ' . $path);
	}

	$links = [];
	$lines = preg_split('/\R/', $contents) ?: [];
	$fence = null;

	foreach ($lines as $index => $line) {
		if (preg_match('/^\s*(`{3,}|~{3,})/', $line, $match) === 1) {
			if ($fence === null) {
				$fence = $match[1][0];
			} elseif ($match[1][0] === $fence) {
				$fence = null;
			}

			continue;
		}

		if ($fence !== null) {
			continue;
		}

		$text = preg_replace('/`[^`]*`/', '', $line) ?? $line;

		if (preg_match_all('/(?:!?)\[[^\]]*\]\(\s*([^)\s]+)(?:\s+["\'][^"\']*["\'])?\s*\)/', $text, $matches) > 0) {
			foreach ($matches[1] as $target) {
				$links[] = ['line' => $index + 1, 'target' => $target];
			}
		}

		if (preg_match('/^\s{0,3}\[[^\]]+\]:\s*(\S+)/', $text, $definition) === 1) {
			$links[] = ['line' => $index + 1, 'target' => $definition[1]];
		}

		if (preg_match_all('/href\s*=\s*["\']([^"\']+)["\']/i', $text, $htmlLinks) > 0) {
			foreach ($htmlLinks[1] as $target) {
				$links[] = ['line' => $index + 1, 'target' => $target];
			}
		}
	}

	return $links;
}

$arguments = array_slice($argv, 1);
$files = markdownFiles($root, $arguments);
$checked = 0;
$missing = [];

foreach ($files as $path) {
	$relativeFile = substr($path, strlen($root) + 1);

	try {
		$links = linksIn($path);
	} catch (RuntimeException $exception) {
		$missing[] = $relativeFile . ': ' . $exception->getMessage();
		continue;
	}

	foreach ($links as $link) {
		$target = $link['target'];
		if ($target === ''
			|| str_starts_with($target, '#')
			|| str_starts_with($target, '//')
			|| preg_match('/^[a-z][a-z0-9+.-]*:/i', $target) === 1) {
			continue;
		}

		$target = preg_replace('/[?#].*$/', '', $target) ?? $target;
		if ($target === '') {
			continue;
		}

		$target = rawurldecode($target);
		$resolved = str_starts_with($target, '/')
			? $root . '/' . ltrim($target, '/')
			: dirname($path) . '/' . $target;
		$checked++;

		if (!is_file($resolved) && !is_dir($resolved)) {
			$missing[] = $relativeFile . ':' . $link['line'] . ' -> ' . $link['target'];
		}
	}
}

if ($missing !== []) {
	fwrite(STDERR, "broken relative link(s):\n  " . implode("\n  ", $missing) . "\n");
	exit(1);
}

echo 'links ok: ' . count($files) . ' file(s), ' . $checked . " relative link(s)\n";
