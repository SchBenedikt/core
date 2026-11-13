<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service;

/**
 * A local account reached the private followed-hashtag limit.
 *
 * The limit keeps the exact-match timeline query bounded. It is a client
 * error: the caller has to unfollow a tag before adding another one.
 */
final class FollowedHashtagLimitException extends \RuntimeException
{
	public function __construct(public readonly int $limit)
	{
		parent::__construct(
			'An account can follow at most ' . $limit . ' hashtags.',
		);
	}
}
