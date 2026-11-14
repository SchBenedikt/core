<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service;

use AlohaSocial\Core\Domain\Media\Attachment;
use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Post\PostRepository;
use AlohaSocial\Core\Domain\Post\Visibility;

use function array_filter;
use function array_values;
use function in_array;

/**
 * Who may read the bytes behind an attachment id.
 *
 * The id is unguessable, but unguessability is not authorization: any id that
 * leaks (a screenshot, a log line, a shared link) must not hand out a private
 * photo. The binding that matters is the post linking the attachment, so the
 * rule is evaluated against the visibility of that post and the relationship
 * between the requester and its author:
 *
 * - a `public` or `unlisted` post publishes its media, because both are
 *   readable by link by design;
 * - a `followers` post is readable by its author and by accepted local
 *   followers, so those readers get the media too;
 * - a `direct` post is readable by its author and its local recipients;
 * - a deleted post keeps its links (cleanup may still need them) but only its
 *   author may read the bytes afterwards.
 *
 * An attachment no post references yet stays readable: the composer shows the
 * upload preview before publishing and that route predates this rule. Only one
 * author may attach an attachment (post creation checks ownership), so a
 * reused id cannot widen one author's audience through another author's post.
 *
 * Remote readers over federation are not authenticated on the media route, so
 * private media is not advertised to them (see
 * {@see MediaService::presentActivityAttachments()}); authenticated remote
 * fetch is a separate feature, not something a static id should bypass.
 */
final class MediaAccess
{
	public function __construct(
		private readonly PostRepository $posts,
		private readonly ?LocalRelationshipService $relationships = null,
	) {
	}

	public function evaluate(Attachment $attachment, ?string $viewerId): MediaAccessDecision
	{
		$linked = $this->posts->postsForAttachment($attachment->id);
		if ($linked === []) {
			return MediaAccessDecision::permit(publiclyCacheable: true);
		}

		$live = array_values(array_filter(
			$linked,
			static fn (Post $post): bool => !$post->isDeleted(),
		));

		foreach ($live as $post) {
			if ($post->visibility->isPubliclyVisible()) {
				return MediaAccessDecision::permit(publiclyCacheable: true);
			}
		}

		if ($viewerId === null) {
			return MediaAccessDecision::refuse();
		}

		foreach ($live as $post) {
			if ($this->mayReadPost($post, $viewerId)) {
				return MediaAccessDecision::permit(publiclyCacheable: false);
			}
		}

		foreach ($linked as $post) {
			if ($post->authorId === $viewerId) {
				return MediaAccessDecision::permit(publiclyCacheable: false);
			}
		}

		return MediaAccessDecision::refuse();
	}

	private function mayReadPost(Post $post, string $viewerId): bool
	{
		if ($post->authorId === $viewerId) {
			return true;
		}

		if ($post->visibility === Visibility::Followers) {
			// The same rule the detail route uses, so media and post agree.
			return $this->relationships?->isLocalFollower($viewerId, $post->authorId) === true;
		}

		if ($post->visibility === Visibility::Direct) {
			return in_array($viewerId, $post->recipientIds, true);
		}

		return false;
	}
}
