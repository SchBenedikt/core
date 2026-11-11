<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Platform\Config;

use InvalidArgumentException;

/**
 * In-memory configuration, used by tests, the demo and tools.
 *
 * Values are always strings, exactly like a database- or config-file-backed
 * implementation would hand them over; the typed accessors are what parse
 * them.
 */
final class ArrayConfig implements Config
{
	/**
	 * @param array<string, array<string, string>> $values app id => key => value
	 */
	public function __construct(
		private array $values = [],
	) {
		foreach ($values as $app => $entries) {
			foreach ($entries as $key => $value) {
				$this->assertKey((string) $app, (string) $key);
				if (!is_string($value)) {
					throw new InvalidArgumentException('Configuration values must be strings.');
				}
			}
		}
	}

	public function get(string $app, string $key, ?string $default = null): ?string
	{
		$this->assertKey($app, $key);

		return $this->values[$app][$key] ?? $default;
	}

	public function getBool(string $app, string $key, bool $default = false): bool
	{
		$value = $this->get($app, $key);
		if ($value === null) {
			return $default;
		}

		return match (strtolower(trim($value))) {
			'1', 'true', 'yes', 'on' => true,
			'0', 'false', 'no', 'off', '' => false,
			default => $default,
		};
	}

	public function getInt(string $app, string $key, int $default = 0): int
	{
		$value = $this->get($app, $key);
		if ($value === null || preg_match('/^-?\d+$/', trim($value)) !== 1) {
			return $default;
		}

		return (int) trim($value);
	}

	public function set(string $app, string $key, string $value): void
	{
		$this->assertKey($app, $key);
		$this->values[$app][$key] = $value;
	}

	public function delete(string $app, string $key): void
	{
		$this->assertKey($app, $key);
		unset($this->values[$app][$key]);
	}

	public function has(string $app, string $key): bool
	{
		$this->assertKey($app, $key);

		return array_key_exists($key, $this->values[$app] ?? []);
	}

	public function all(string $app): array
	{
		$this->assertKey($app, 'app');

		$entries = $this->values[$app] ?? [];
		ksort($entries);

		return $entries;
	}

	private function assertKey(string $app, string $key): void
	{
		if ($app === '' || $key === '') {
			throw new InvalidArgumentException('App id and configuration key must not be empty.');
		}
	}
}
