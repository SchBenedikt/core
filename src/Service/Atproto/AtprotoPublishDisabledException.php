<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Atproto;

use RuntimeException;

/**
 * AT Protocol publishing is turned off by configuration.
 */
final class AtprotoPublishDisabledException extends RuntimeException
{
	public function __construct()
	{
		parent::__construct('AT Protocol publishing is disabled.');
	}
}
