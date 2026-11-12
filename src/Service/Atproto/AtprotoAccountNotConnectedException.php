<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Atproto;

use RuntimeException;

/**
 * The local user has not connected an AT Protocol account.
 */
final class AtprotoAccountNotConnectedException extends RuntimeException
{
	public function __construct()
	{
		parent::__construct('Connect an AT Protocol account before publishing.');
	}
}
