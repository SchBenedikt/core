<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Contract;

use AlohaSocial\Core\Contract\Routes;
use AlohaSocial\Core\Http\Route;
use AlohaSocial\Core\Service\Mastodon\MastodonApiConfig;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The status documents are part of the contract: a route or schema that exists
 * in code but not in the reference is how the project silently drifts.
 */
final class DocumentationTest extends TestCase
{
	public function testTheRouteReferenceListsEveryManifestRoute(): void
	{
		$manifest = Routes::manifest();
		$reference = $this->read('docs/reference/routes.md');

		$start = strpos($reference, '| Name | Method | Path | Auth | Rate limit |');
		$end = strpos($reference, 'Auth and rate limits are **declared**');
		self::assertNotFalse($start, 'The route reference table has no header.');
		self::assertNotFalse($end, 'The route reference table has no closing paragraph.');

		$table = substr($reference, $start, $end - $start);
		preg_match_all('/^\| `([^`]+)` \| `[A-Z]+` \|/m', $table, $matches);

		$documented = $matches[1];
		$names = array_map(static fn (Route $route): string => $route->name, $manifest->routes());
		sort($documented);
		sort($names);

		self::assertSame(
			$names,
			$documented,
			'docs/reference/routes.md must list every manifest route exactly once.',
		);
	}

	public function testTheAdvertisedRouteCountsMatchTheManifest(): void
	{
		$manifest = Routes::manifest();
		$total = count($manifest->routes());
		$compat = count(array_filter(
			$manifest->routes(),
			static fn (Route $route): bool => str_starts_with($route->path, MastodonApiConfig::DEFAULT_PREFIX . '/'),
		));

		self::assertStringContainsString(
			'There are ' . $total . ' routes in total; ' . $compat . ' use the',
			$this->read('docs/reference/routes.md'),
			'The route reference advertises a stale route count.',
		);
		self::assertStringContainsString(
			'| HTTP contract | ' . $total . ' routes, ' . $compat . ' of them under the Mastodon prefix |',
			$this->read('docs/roadmap.md'),
			'The roadmap advertises a stale route count.',
		);
		self::assertStringContainsString(
			$total . ' routes; ' . $compat . ' under the compatibility prefix',
			$this->read('README.md'),
			'The README advertises a stale route count.',
		);
	}

	public function testEverySchemaIsListedInTheDatabaseGuide(): void
	{
		$root = dirname(__DIR__, 2) . '/src';
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
		);

		$schemas = [];
		foreach ($iterator as $file) {
			if ($file instanceof SplFileInfo && str_ends_with($file->getFilename(), 'Schema.php')) {
				$schemas[] = $file->getFilename();
			}
		}
		sort($schemas);

		self::assertNotSame([], $schemas, 'No schema classes were found.');

		$database = $this->read('docs/guides/database.md');
		foreach ($schemas as $schema) {
			self::assertStringContainsString(
				$schema,
				$database,
				'The database guide does not mention ' . $schema . '.',
			);
		}
	}

	private function read(string $relativePath): string
	{
		$path = dirname(__DIR__, 2) . '/' . $relativePath;
		$contents = file_get_contents($path);

		self::assertIsString($contents, 'Could not read ' . $relativePath);

		return $contents;
	}
}
