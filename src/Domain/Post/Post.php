<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Post;

use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A post written on this instance.
 *
 * Immutable: an edit or a deletion produces a new instance, which keeps the
 * repository contract (and the tests) free of hidden mutation. `revision`
 * starts at one and counts edits, so a caller can detect that somebody else
 * changed the post before its own patch was applied.
 */
final readonly class Post
{
	public const MAX_ATTACHMENTS = 10;

	public function __construct(
		public string $id,
		public string $authorId,
		public string $content,
		public Visibility $visibility,
		public DateTimeImmutable $publishedAt,
		public ?string $contentWarning = null,
		public ?DateTimeImmutable $deletedAt = null,
		/** @var list<string> */
		public array $attachmentIds = [],
		public ?Poll $poll = null,
		/** @var list<string> local account ids for a direct post */
		public array $recipientIds = [],
		public ?string $inReplyToId = null,
		/**
		 * The canonical ActivityPub object id and author of a remote reply
		 * target. Both are set together or not at all; `inReplyToId` stays
		 * reserved for local parents.
		 */
		public ?string $remoteParentObjectId = null,
		public ?string $remoteParentAuthorId = null,
		public ?DateTimeImmutable $editedAt = null,
		public int $revision = 1,
		public ?string $quotedObjectId = null,
		public ?QuoteState $quoteState = null,
		public ?string $quoteAuthorizationId = null,
		public ?string $quoteRequestId = null,
		/** Set when the author archived the post locally; it stays addressable. */
		public ?DateTimeImmutable $archivedAt = null,
		public ?string $language = null,
	) {
		if ($revision < 1) {
			throw new InvalidArgumentException('A post revision starts at one.');
		}

		if ($editedAt !== null && $editedAt < $publishedAt) {
			throw new InvalidArgumentException('A post cannot have been edited before it was published.');
		}

		if ($archivedAt !== null && $archivedAt < $publishedAt) {
			throw new InvalidArgumentException('A post cannot have been archived before it was published.');
		}

		if ($language !== null && (strlen($language) > 35
			|| preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/D', $language) !== 1
		)) {
			throw new InvalidArgumentException('A post language must be a valid BCP 47 language tag.');
		}

		if ($id === '') {
			throw new InvalidArgumentException('A post needs an id.');
		}

		if ($authorId === '') {
			throw new InvalidArgumentException('A post needs an author.');
		}

		if (count($attachmentIds) > self::MAX_ATTACHMENTS) {
			throw new InvalidArgumentException('A post has too many attachments.');
		}

		if (array_values($attachmentIds) !== $attachmentIds) {
			throw new InvalidArgumentException('Post attachment ids must be a list.');
		}

		$seen = [];
		foreach ($attachmentIds as $attachmentId) {
			if (!is_string($attachmentId) || $attachmentId === '') {
				throw new InvalidArgumentException('A post attachment needs an id.');
			}

			if (isset($seen[$attachmentId])) {
				throw new InvalidArgumentException('A post cannot repeat an attachment.');
			}

			$seen[$attachmentId] = true;
		}

		if (array_values($recipientIds) !== $recipientIds) {
			throw new InvalidArgumentException('Post recipient ids must be a list.');
		}

		$seenRecipients = [];
		foreach ($recipientIds as $recipientId) {
			if (!is_string($recipientId) || $recipientId === '') {
				throw new InvalidArgumentException('A post recipient needs an id.');
			}

			if (isset($seenRecipients[$recipientId])) {
				throw new InvalidArgumentException('A post cannot repeat a recipient.');
			}

			$seenRecipients[$recipientId] = true;
		}

		if ($inReplyToId !== null && trim($inReplyToId) === '') {
			throw new InvalidArgumentException('A reply target needs an id.');
		}

		if (($remoteParentObjectId === null) !== ($remoteParentAuthorId === null)) {
			throw new InvalidArgumentException('A remote reply target needs an object id and an author.');
		}

		if ($inReplyToId !== null && $remoteParentObjectId !== null) {
			throw new InvalidArgumentException('A post has one reply target.');
		}

		foreach (['remoteParentObjectId' => $remoteParentObjectId, 'remoteParentAuthorId' => $remoteParentAuthorId] as $name => $value) {
			if ($value !== null && (trim($value) === '' || mb_strlen($value) > 512)) {
				throw new InvalidArgumentException('The ' . $name . ' is invalid.');
			}
		}

		if ($quotedObjectId !== null && trim($quotedObjectId) === '') {
			throw new InvalidArgumentException('A quote target needs an id.');
		}

		if ($quotedObjectId !== null && mb_strlen($quotedObjectId) > 1024) {
			throw new InvalidArgumentException('A quote target id is too long.');
		}

		if (($quotedObjectId === null) !== ($quoteState === null)) {
			throw new InvalidArgumentException('A quote target and its state belong together.');
		}

		if ($quoteState === QuoteState::Pending && $quoteAuthorizationId !== null) {
			throw new InvalidArgumentException('A pending quote has no authorization yet.');
		}

		if ($quoteAuthorizationId !== null && $quoteState !== QuoteState::Accepted) {
			throw new InvalidArgumentException('Only an accepted quote carries an authorization.');
		}

		foreach (['quoteAuthorizationId' => $quoteAuthorizationId, 'quoteRequestId' => $quoteRequestId] as $name => $value) {
			if ($value !== null && mb_strlen($value) > 1024) {
				throw new InvalidArgumentException('The ' . $name . ' is too long.');
			}
		}
	}

	public function isDeleted(): bool
	{
		return $this->deletedAt !== null;
	}

	/**
	 * An archived post stays readable by id but is omitted from every local
	 * listing surface. It is not a deletion and never federates a Delete.
	 */
	public function isArchived(): bool
	{
		return $this->archivedAt !== null;
	}

	/**
	 * The reply target as one reference: a local post id or the remote
	 * object's canonical URL. A post has at most one of the two.
	 */
	public function replyParentRef(): ?string
	{
		return $this->inReplyToId ?? $this->remoteParentObjectId;
	}

	public function withArchivedAt(?DateTimeImmutable $archivedAt): self
	{
		return new self(
			id: $this->id,
			authorId: $this->authorId,
			content: $this->content,
			visibility: $this->visibility,
			publishedAt: $this->publishedAt,
			contentWarning: $this->contentWarning,
			deletedAt: $this->deletedAt,
			attachmentIds: $this->attachmentIds,
			poll: $this->poll,
			recipientIds: $this->recipientIds,
			inReplyToId: $this->inReplyToId,
			remoteParentObjectId: $this->remoteParentObjectId,
			remoteParentAuthorId: $this->remoteParentAuthorId,
			editedAt: $this->editedAt,
			revision: $this->revision,
			quotedObjectId: $this->quotedObjectId,
			quoteState: $this->quoteState,
			quoteAuthorizationId: $this->quoteAuthorizationId,
			quoteRequestId: $this->quoteRequestId,
			archivedAt: $archivedAt,
			language: $this->language,
		);
	}

	/**
	 * The shape the API returns.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'author_id' => $this->authorId,
			'content' => $this->content,
			'content_warning' => $this->contentWarning,
			'language' => $this->language,
			'visibility' => $this->visibility->value,
			'published_at' => Time::format($this->publishedAt),
			'edited_at' => $this->editedAt === null ? null : Time::format($this->editedAt),
			'revision' => $this->revision,
			'deleted_at' => $this->deletedAt === null ? null : Time::format($this->deletedAt),
			'poll' => $this->poll?->toArray(),
			'in_reply_to_id' => $this->replyParentRef(),
			'remote_parent_object_id' => $this->remoteParentObjectId,
			'remote_parent_author_id' => $this->remoteParentAuthorId,
			'quoted_object_id' => $this->quotedObjectId,
			'quote_state' => $this->quoteState?->value,
			'quote_authorization_id' => $this->quoteAuthorizationId,
			'quote_request_id' => $this->quoteRequestId,
			'archived_at' => $this->archivedAt === null ? null : Time::format($this->archivedAt),
			'recipient_ids' => $this->recipientIds,
		];
	}
}
