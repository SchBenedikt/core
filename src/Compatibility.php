<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core;

use AlohaSocial\Core\Exceptions\ConfigurationException;

use function preg_match;
use function sprintf;

/**
 * Checks a core, `@alohasocial/ui` and HostBridge combination.
 *
 * For 0.x releases the minor line is the compatibility boundary, matching
 * semver's rule that 0.x minors may break. From 1.0 on, the major line is the
 * boundary. The HostBridge contract version is separate: it only changes when
 * the bridge surface itself breaks.
 */
final class Compatibility
{
	public static function assertSupported(
		string $uiVersion,
		?int $hostBridgeContract = null,
		string $coreVersion = Version::CURRENT,
	): void {
		$coreLine = self::compatibilityLine($coreVersion, 'core');
		$uiLine = self::compatibilityLine($uiVersion, '@alohasocial/ui');

		if ($coreLine !== $uiLine) {
			throw new ConfigurationException(sprintf(
				'Core %s and @alohasocial/ui %s are not on the same compatibility line (%s vs %s). '
				. 'For 0.x releases the minor line is the boundary; pin both packages to %s.x. '
				. 'See docs/guides/host-integration.md#compatibility.',
				$coreVersion,
				$uiVersion,
				$coreLine,
				$uiLine,
				$coreLine,
			));
		}

		if ($hostBridgeContract !== null && $hostBridgeContract !== Version::HOST_BRIDGE_CONTRACT) {
			throw new ConfigurationException(sprintf(
				'HostBridge contract %d is not supported by alohasocial/core %s (contract %d). '
				. 'Use matching core and @alohasocial/ui releases; a bridge without a contract version is contract 1.',
				$hostBridgeContract,
				$coreVersion,
				Version::HOST_BRIDGE_CONTRACT,
			));
		}
	}

	private static function compatibilityLine(string $version, string $component): string
	{
		$matches = [];
		if (preg_match(
			'/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/',
			$version,
			$matches,
		) !== 1) {
			throw new ConfigurationException(sprintf(
				'Invalid %s version "%s"; expected a semantic version such as 0.7.0.',
				$component,
				$version,
			));
		}

		return $matches[1] === '0' ? $matches[1] . '.' . $matches[2] : $matches[1];
	}

	private function __construct()
	{
	}
}
