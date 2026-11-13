<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Platform\Config;

/**
 * Configuration the application owns, scoped by app id and key.
 *
 * The Nextcloud host backs this with `IConfig`; the standalone host with a
 * YAML/env/DB combination. The core never sees where a value comes from.
 *
 * User-scoped settings deliberately are not part of this port: accounts,
 * sessions and roles are host-owned, so those settings stay behind the host's
 * identity boundary too. See `docs/adr/0006-host-owned-accounts.md`.
 */
interface Config
{
	public function get(string $app, string $key, ?string $default = null): ?string;

	public function getBool(string $app, string $key, bool $default = false): bool;

	public function getInt(string $app, string $key, int $default = 0): int;

	public function set(string $app, string $key, string $value): void;

	public function delete(string $app, string $key): void;

	public function has(string $app, string $key): bool;

	/**
	 * @return array<string, string>
	 */
	public function all(string $app): array;
}
