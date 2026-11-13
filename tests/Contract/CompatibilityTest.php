<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Contract;

use AlohaSocial\Core\Compatibility;
use AlohaSocial\Core\Exceptions\ConfigurationException;
use AlohaSocial\Core\Http\ApiVersion;
use AlohaSocial\Core\Version;
use PHPUnit\Framework\TestCase;

/**
 * Version compatibility is part of the contract: a package pair that cannot
 * work together must fail the build, not a host's first request.
 */
final class CompatibilityTest extends TestCase
{
	public function testTheCoreAndUiVersionsComeFromOneCheckedSource(): void
	{
		$composer = $this->readJson('composer.json');
		$package = $this->readJson('ui/package.json');
		$lock = $this->readJson('ui/package-lock.json');
		$uiVersion = $this->read('ui/src/version.ts');

		self::assertArrayNotHasKey(
			'version',
			$composer,
			'composer.json must not duplicate the core version; use src/Version.php and the release tag.',
		);
		self::assertSame(Version::CURRENT, $package['version'], 'ui/package.json and src/Version.php must use the same release line.');
		self::assertSame($package['version'], $lock['version'], 'ui/package-lock.json must carry the package version.');
		self::assertSame($package['version'], $lock['packages']['']['version'], 'The root lockfile package must carry the package version.');
		self::assertMatchesRegularExpression(
			"/export const UI_VERSION = '" . preg_quote(Version::CURRENT, '/') . "';/",
			$uiVersion,
			'ui/src/version.ts must export the package version.',
		);
		self::assertMatchesRegularExpression(
			"/export const HOST_BRIDGE_CONTRACT_VERSION = " . Version::HOST_BRIDGE_CONTRACT . ';/',
			$uiVersion,
			'ui/src/version.ts must export the HostBridge contract version.',
		);
		self::assertMatchesRegularExpression(
			"/export const CORE_API_VERSION = '" . preg_quote(ApiVersion::CURRENT, '/') . "';/",
			$uiVersion,
			'ui/src/version.ts must export the core API version.',
		);

		$matrix = $this->read('docs/guides/host-integration.md');
		self::assertStringContainsString('| `alohasocial/core` | `' . Version::CURRENT . '` |', $matrix);
		self::assertStringContainsString('| `@alohasocial/ui` | `' . $package['version'] . '` |', $matrix);
		self::assertStringContainsString(
			'| `HostBridge` | contract `' . Version::HOST_BRIDGE_CONTRACT . '` |',
			$matrix,
		);
	}

	public function testTheCurrentPairIsAccepted(): void
	{
		Compatibility::assertSupported('0.7.0');
		Compatibility::assertSupported('0.7.9', Version::HOST_BRIDGE_CONTRACT);
		Compatibility::assertSupported('1.9.0', 1, '1.2.3');

		self::assertSame('0.7.0', Version::CURRENT);
	}

	public function testAnOlderMinorLineIsRejected(): void
	{
		$this->expectException(ConfigurationException::class);
		$this->expectExceptionMessage('not on the same compatibility line');

		Compatibility::assertSupported('0.6.9');
	}

	public function testANewerMinorLineIsRejected(): void
	{
		$this->expectException(ConfigurationException::class);
		$this->expectExceptionMessage('not on the same compatibility line');

		Compatibility::assertSupported('0.8.0');
	}

	public function testANewerMajorLineIsRejected(): void
	{
		$this->expectException(ConfigurationException::class);
		$this->expectExceptionMessage('not on the same compatibility line');

		Compatibility::assertSupported('2.0.0', 1, '1.2.3');
	}

	public function testAnUnsupportedHostBridgeContractIsRejected(): void
	{
		$this->expectException(ConfigurationException::class);
		$this->expectExceptionMessage('HostBridge contract 2 is not supported');

		Compatibility::assertSupported('0.7.0', 2);
	}

	public function testAnInvalidVersionIsRejected(): void
	{
		$this->expectException(ConfigurationException::class);
		$this->expectExceptionMessage('expected a semantic version');

		Compatibility::assertSupported('latest');
	}

	/**
	 * @return array<string, mixed>
	 */
	private function readJson(string $relativePath): array
	{
		$decoded = json_decode($this->read($relativePath), true, 512, JSON_THROW_ON_ERROR);
		self::assertIsArray($decoded);

		return $decoded;
	}

	private function read(string $relativePath): string
	{
		$path = dirname(__DIR__, 2) . '/' . $relativePath;
		$contents = file_get_contents($path);

		self::assertIsString($contents, 'Could not read ' . $relativePath);

		return $contents;
	}
}
