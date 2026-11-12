<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service;

use RuntimeException;

/**
 * A local Follow was requested, but no HTTP client and signing key exist to
 * deliver it. Recording the edge anyway would claim a follow the peer never
 * saw.
 */
final class FollowingNotConfiguredException extends RuntimeException
{
	public function __construct()
	{
		parent::__construct(
			'Outbound follow delivery is not configured (HTTP client and signing key are required).',
		);
	}
}
