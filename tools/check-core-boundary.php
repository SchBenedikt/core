<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Guards the core against a dependency on a host application.
 *
 * Comments may name a host — explaining which adapter implements a port is
 * documentation, not a dependency. Only real code identifiers count, which is
 * why this reads tokens rather than grepping lines.
 *
 * Usage: php tools/check-core-boundary.php
 */

$root = dirname(__DIR__);
$sourcePath = $root . '/src';

$violations = [];
$forbiddenNamespaces = ['OCP\\', 'OCA\\'];
$forbiddenBareNames = ['OCP', 'OCA'];

if (!is_dir($sourcePath)) {
	fwrite(STDERR, "src/ is missing\n");
	exit(1);
}

$files = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator($sourcePath, FilesystemIterator::SKIP_DOTS),
);

foreach ($files as $file) {
	if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
		continue;
	}

	$path = $file->getPathname();
	$relative = substr($path, strlen($root) + 1);
	$code = file_get_contents($path);
	if ($code === false) {
		$violations[] = $relative . ': could not be read';
		continue;
	}

	$tokens = token_get_all($code);
	$line = 1;

	foreach ($tokens as $token) {
		if (!is_array($token)) {
			continue;
		}

		[$id, $text, $tokenLine] = $token;
		$line = $tokenLine;

		if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
			continue;
		}

		if ($id === T_NAME_QUALIFIED || $id === T_NAME_FULLY_QUALIFIED) {
			$name = ltrim($text, '\\');
			foreach ($forbiddenNamespaces as $namespace) {
				if (str_starts_with($name, $namespace)) {
					$violations[] = $relative . ':' . $line . ' references ' . $text;
				}
			}

			continue;
		}

		if ($id === T_STRING && in_array($text, $forbiddenBareNames, true)) {
			$violations[] = $relative . ':' . $line . ' references ' . $text;
		}
	}
}

$composerPath = $root . '/composer.json';
$composer = json_decode((string) file_get_contents($composerPath), true, 512, JSON_THROW_ON_ERROR);
foreach (['require', 'require-dev', 'suggest'] as $section) {
	foreach (array_keys($composer[$section] ?? []) as $package) {
		if (str_starts_with((string) $package, 'nextcloud/')) {
			$violations[] = 'composer.json: ' . $section . ' depends on ' . $package;
		}
	}
}

if ($violations !== []) {
	fwrite(STDERR, "core boundary violated:\n  " . implode("\n  ", $violations) . "\n");
	fwrite(STDERR, "src/ must stay host-neutral. Put host code into the host adapter.\n");
	exit(1);
}

echo "core boundary ok\n";
