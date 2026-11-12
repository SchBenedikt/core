<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Atproto;

use RuntimeException;

/**
 * The host has enabled the feature but supplied no account resolver or
 * publisher. This is a deployment state, not a user error.
 */
final class AtprotoNotConfiguredException extends RuntimeException
{
	public function __construct()
	{
		parent::__construct('AT Protocol account linking is not configured on this host.');
	}
}
