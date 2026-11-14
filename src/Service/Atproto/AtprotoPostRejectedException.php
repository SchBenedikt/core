<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Atproto;

use AlohaSocial\Core\Protocol\Atproto\AtprotoBlobException;
use AlohaSocial\Core\Protocol\Atproto\AtprotoEmbedException;
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

	public const MEDIA_UNSUPPORTED = 'media_unsupported';

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
			self::MEDIA_UNSUPPORTED => 'Attachments are not configured on this instance.',
			AtprotoBlobException::MIME_UNSUPPORTED => 'The attached media type is not supported by AT Protocol.',
			AtprotoBlobException::TOO_LARGE => 'The attached media is too large for AT Protocol.',
			AtprotoBlobException::MISSING => 'The AT Protocol PDS returned no blob reference.',
			AtprotoBlobException::UPLOAD_FAILED => 'The media could not be uploaded to the PDS.',
			AtprotoEmbedException::MIXED_MEDIA => 'A post cannot embed images and a video at the same time.',
			AtprotoEmbedException::TOO_MANY_IMAGES => 'A post can embed at most four images.',
			AtprotoEmbedException::TOO_MANY_VIDEOS => 'A post can embed at most one video.',
			AtprotoEmbedException::ALT_MISSING => 'Every embedded image needs an alt text; an empty one is allowed.',
			AtprotoEmbedException::ALT_TOO_LONG => 'An alt text is too long for AT Protocol.',
			AtprotoEmbedException::ASPECT_RATIO_INVALID => 'The aspect ratio is incomplete or out of range.',
			default => 'This post cannot be published to AT Protocol.',
		};

		parent::__construct($message);
	}
}
