<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core;

/**
 * The current core release line and the UI boundary it implements.
 *
 * This constant is the runtime source for the core version. `composer.json`
 * intentionally has no `version` field: the distribution version comes from
 * the git tag. Bump this constant and `ui/package.json` together at release
 * time; the compatibility tests reject a drift.
 */
final class Version
{
	public const CURRENT = '0.8.0';

	/**
	 * The HostBridge contract this core and the current UI package implement.
	 * A bridge that omits its optional `contractVersion` is treated as 1.
	 */
	public const HOST_BRIDGE_CONTRACT = 1;

	private function __construct()
	{
	}
}
