<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Atproto;

use AlohaSocial\Core\Protocol\Atproto\AtprotoFacetException;
use AlohaSocial\Core\Protocol\Atproto\AtprotoReplyException;
use RuntimeException;

/**
 * The local post cannot be represented as a public AT Protocol post.
 *
 * The reason is a stable machine-readable value so API clients can explain
 * why a post was skipped while still receiving a useful message.
 */
final class AtprotoPostRejectedException extends RuntimeException
{
	public const NOT_PUBLIC = 'not_public';

	public const ATTACHMENTS_UNSUPPORTED = 'attachments_unsupported';

	public const TEXT_TOO_LONG = 'text_too_long';

	public const REPLIES_UNSUPPORTED = 'replies_unsupported';

	public const REPLY_PARENT_NOT_PUBLISHED = 'reply_parent_not_published';

	public const REPLY_PARENT_UNRESOLVABLE = AtprotoReplyException::PARENT_UNRESOLVABLE;

	public const REPLY_ROOT_UNRESOLVABLE = AtprotoReplyException::ROOT_UNRESOLVABLE;

	public const MENTION_UNRESOLVED = AtprotoFacetException::MENTION_UNRESOLVED;

	public function __construct(
		public readonly string $reason,
	) {
		$message = match ($reason) {
			self::NOT_PUBLIC => 'Only public posts can be published to AT Protocol.',
			self::ATTACHMENTS_UNSUPPORTED => 'Posts with attachments cannot be published to AT Protocol yet.',
			self::TEXT_TOO_LONG => 'The post text is too long for AT Protocol.',
			self::REPLIES_UNSUPPORTED => 'Reply references are not configured on this instance.',
			self::REPLY_PARENT_NOT_PUBLISHED => 'The reply parent has no published AT Protocol record.',
			self::REPLY_PARENT_UNRESOLVABLE => 'The reply parent could not be read from the PDS.',
			self::REPLY_ROOT_UNRESOLVABLE => 'The reply root could not be read from the PDS.',
			self::MENTION_UNRESOLVED => 'A mentioned handle does not resolve to an AT Protocol DID.',
			default => 'This post cannot be published to AT Protocol.',
		};

		parent::__construct($message);
	}
}
