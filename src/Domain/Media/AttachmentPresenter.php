<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Media;

use AlohaSocial\Core\Domain\Post\Post;

/**
 * Serializes a post's attachments for an ActivityStreams document.
 *
 * The protocol layer owns the shape of the document; a presenter owns the
 * media URLs, because only the media side knows how an attachment is served.
 * The two stay separate so a note can be built for delivery and for the
 * outbox from the same code without the protocol depending on storage.
 */
interface AttachmentPresenter
{
	/**
	 * The `attachment` entries of a post, in the order the post links them.
	 *
	 * Only a post whose audience is public publishes these URLs: the media
	 * route is unauthenticated, so a followers-only or direct post must not
	 * send raw blob links that whoever holds them can open.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function presentActivityAttachments(Post $post): array;
}
