<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service;

use AlohaSocial\Core\Domain\Post\Poll;
use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Post\Visibility;

use function count;
use function sort;
use function str_starts_with;
use function strtolower;
use function trim;

/**
 * The input of {@see PostService::create()}.
 *
 * A command object rather than eight positional parameters: it keeps the call
 * sites readable and gives validation one place to look at.
 */
final readonly class CreatePost
{
	public function __construct(
		public string $authorId,
		public string $content,
		public Visibility $visibility = Visibility::Public,
		public ?string $contentWarning = null,
		/** @var list<string> */
		public array $attachmentIds = [],
		public ?string $postId = null,
		public ?Poll $poll = null,
		/** @var list<string> local recipient ids for a direct post */
		public array $recipientIds = [],
		public ?string $inReplyToId = null,
		public ?string $quotedPostId = null,
		public ?string $language = null,
	) {
	}

	/**
	 * True when `$post` is exactly what this command would have written.
	 *
	 * Used to decide whether a retried client write (idempotency key) is a
	 * replay of the stored post or a conflicting payload. The comparison
	 * normalizes like {@see PostService::create()} — trimmed content,
	 * warning, language and local reply target, order-insensitive recipients
	 * — and compares poll shape only: option titles, `multiple` and
	 * `hide_totals`. Fields that legitimately differ between attempts stay
	 * out: derived poll expiry, publication time and quote resolution (a
	 * `quotedPostId` change under a reused key is not detected). A remote
	 * reply target is compared by existence only, because the stored
	 * `remoteParentObjectId` is the canonical object id, which need not be
	 * byte-identical to the URL the client sent.
	 */
	public function matchesPost(Post $post): bool
	{
		$content = trim($this->content);
		$warning = $this->contentWarning === null ? null : trim($this->contentWarning);
		$warning = $warning === '' ? null : $warning;
		$language = $this->language === null ? null : trim($this->language);
		$language = $language === '' ? null : $language;
		$replyTo = $this->inReplyToId === null ? null : trim($this->inReplyToId);
		$replyTo = $replyTo === '' ? null : $replyTo;

		if ($post->content !== $content
			|| $post->visibility !== $this->visibility
			|| $post->contentWarning !== $warning
			|| $post->attachmentIds !== $this->attachmentIds
			|| $post->language !== $language
		) {
			return false;
		}

		$storedRecipients = $post->recipientIds;
		$requestedRecipients = $this->recipientIds;
		sort($storedRecipients);
		sort($requestedRecipients);
		if ($storedRecipients !== $requestedRecipients) {
			return false;
		}

		if ($replyTo !== null && str_starts_with(strtolower($replyTo), 'http')) {
			// Remote parent: only existence is stable across resolutions.
			if ($post->remoteParentObjectId === null) {
				return false;
			}
		} elseif ($post->inReplyToId !== $replyTo || $post->remoteParentObjectId !== null) {
			return false;
		}

		if (($post->poll === null) !== ($this->poll === null)) {
			return false;
		}
		if ($post->poll === null || $this->poll === null) {
			return true;
		}

		if ($post->poll->multiple !== $this->poll->multiple
			|| $post->poll->hideTotals !== $this->poll->hideTotals
			|| count($post->poll->options) !== count($this->poll->options)
		) {
			return false;
		}
		foreach ($post->poll->options as $index => $option) {
			if ($option->title !== $this->poll->options[$index]->title) {
				return false;
			}
		}

		return true;
	}
}
