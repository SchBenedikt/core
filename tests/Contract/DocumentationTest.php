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
 * The status documents are part of the contract: a route, schema or configuration
 * key that exists in code but not in the reference is how the project silently
 * drifts.
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

	public function testEveryConfigurationKeyIsListedInTheConfigurationReference(): void
	{
		$keys = $this->configurationKeys();

		self::assertContains('instance.url', $keys, 'The key discovery missed a literal read.');
		self::assertContains('instance.email', $keys, 'The key discovery missed a fallback read.');
		self::assertContains('thread.max_depth', $keys, 'The key discovery missed a declared constant.');

		$reference = $this->read('docs/reference/configuration.md');
		foreach ($keys as $key) {
			self::assertStringContainsString(
				'`' . $key . '`',
				$reference,
				'The configuration reference does not mention ' . $key . '.',
			);
		}
	}

	/**
	 * Every `Config` read in `src/` names its app and key as a literal or as a
	 * same-file constant. Resolving both keeps this guard independent of how a
	 * service declares its key constants.
	 *
	 * @return list<string>
	 */
	private function configurationKeys(): array
	{
		$root = dirname(__DIR__, 2) . '/src';
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
		);

		$keys = [];
		foreach ($iterator as $file) {
			if (!$file instanceof SplFileInfo || !str_ends_with($file->getFilename(), '.php')) {
				continue;
			}

			$source = file_get_contents($file->getPathname());
			self::assertIsString($source, 'Could not read ' . $file->getFilename());

			preg_match_all("/\bconst\s+([A-Z][A-Z0-9_]*)\s*=\s*'([^']*)'/", $source, $constants);
			$declared = array_combine($constants[1], $constants[2]);

			preg_match_all('/->get(?:Bool|Int|Float)?\(([^()]*)\)/', $source, $calls);
			foreach ($calls[1] as $arguments) {
				$parts = array_map('trim', explode(',', $arguments));
				if (count($parts) < 2) {
					continue;
				}

				// Only a read of the `social` app is a core configuration key;
				// it keeps unrelated `->get()` calls out of the reference guard.
				if ($this->resolveConfigArgument($parts[0], $declared) !== 'social') {
					continue;
				}

				$key = $this->resolveConfigArgument($parts[1], $declared);
				if ($key !== null) {
					$keys[$key] = true;
				}
			}
		}

		$keys = array_keys($keys);
		sort($keys);

		return $keys;
	}

	/**
	 * @param array<string, string> $declared
	 */
	private function resolveConfigArgument(string $argument, array $declared): ?string
	{
		if (preg_match("/^'([^']*)'$/", $argument, $literal) === 1) {
			return $literal[1];
		}

		if (preg_match('/^(?:self|static|[A-Za-z_][A-Za-z0-9_]*)::([A-Z][A-Z0-9_]*)$/', $argument, $constant) === 1) {
			return $declared[$constant[1]] ?? null;
		}

		return null;
	}

	private function read(string $relativePath): string
	{
		$path = dirname(__DIR__, 2) . '/' . $relativePath;
		$contents = file_get_contents($path);

		self::assertIsString($contents, 'Could not read ' . $relativePath);

		return $contents;
	}
}
