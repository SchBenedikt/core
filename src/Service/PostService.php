<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service;

use AlohaSocial\Core\Domain\Federation\InboxResolver;
use AlohaSocial\Core\Domain\Federation\MentionResolver;
use AlohaSocial\Core\Domain\Federation\MentionTarget;
use AlohaSocial\Core\Domain\Media\AttachmentRepository;
use AlohaSocial\Core\Domain\Moderation\HeldPost;
use AlohaSocial\Core\Domain\Moderation\HeldPostRepository;
use AlohaSocial\Core\Domain\Moderation\HeldPostStatus;
use AlohaSocial\Core\Domain\Moderation\PostHoldPolicy;
use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Post\PostAccessDeniedException;
use AlohaSocial\Core\Domain\Post\PostConflictException;
use AlohaSocial\Core\Domain\Post\PostNotFoundException;
use AlohaSocial\Core\Domain\Post\PostPinRepository;
use AlohaSocial\Core\Domain\Post\PostRepository;
use AlohaSocial\Core\Domain\Post\PostRevision;
use AlohaSocial\Core\Domain\Post\PostRevisionRepository;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Jobs\JobQueue;
use AlohaSocial\Core\Jobs\JobSubject;
use AlohaSocial\Core\Jobs\JobType;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Config\Config;
use AlohaSocial\Core\Platform\Db\TransactionRunner;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;
use AlohaSocial\Core\Service\Atproto\AtprotoPublishService;
use AlohaSocial\Core\Service\LocalRelationshipService;
use AlohaSocial\Core\Service\Search\SearchIndexer;
use AlohaSocial\Core\Support\IdGenerator;
use AlohaSocial\Core\Support\Time;
use Closure;
use Throwable;

/**
 * Writing and removing posts.
 *
 * Everything that has to be true about a post — its length, its content
 * warning, its visibility, who may delete it — is decided here, not in a
 * controller. Delivery is a side effect: the post is stored first, then one
 * job per inbox is queued, so a slow peer never blocks the write.
 */
final class PostService
{
	public const MAX_CONTENT_LENGTH = 5000;

	public const MAX_CONTENT_WARNING_LENGTH = 200;

	/**
	 * Configured cap, clamped to what the domain and the API can carry.
	 */
	public const CONFIG_MAX_ATTACHMENTS = 'media.max_attachments';

	/**
	 * `@user` or `@user@host`. The second `@` is optional, so a plain local
	 * mention in prose is recognized without treating an e-mail address as one.
	 */
	private const MENTION_PATTERN = '/(?<![\p{L}\p{N}_@])@([A-Za-z0-9_][A-Za-z0-9_.-]{0,63})(?:@([A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?(?::\d+)?))?/u';

	public function __construct(
		private readonly PostRepository $posts,
		private readonly AttachmentRepository $attachments,
		private readonly JobQueue $queue,
		private readonly InboxResolver $inboxes,
		private readonly ActivityFactory $activities,
		private readonly Clock $clock,
		private readonly IdGenerator $ids,
		private readonly ?AtprotoPublishService $atproto = null,
		private readonly ?LocalRelationshipService $relationships = null,
		private readonly ?NotificationService $notifications = null,
		private readonly ?TransactionRunner $transactions = null,
		private readonly ?PostRevisionRepository $revisions = null,
		private readonly ?QuoteService $quotes = null,
		private readonly ?Config $config = null,
		private readonly ?HeldPostRepository $heldPosts = null,
		private readonly ?PostHoldPolicy $holdPolicy = null,
		private readonly ?SearchIndexer $searchIndexer = null,
		private readonly ?PostPinRepository $pins = null,
		private readonly ?MentionResolver $mentions = null,
	) {
	}

	public function create(CreatePost $command, bool $applyHold = true): Post
	{
		$content = trim($command->content);
		$warning = $command->contentWarning === null ? null : trim($command->contentWarning);
		if ($warning === '') {
			$warning = null;
		}

		$inReplyToId = $command->inReplyToId === null ? null : trim($command->inReplyToId);
		if ($inReplyToId === '') {
			$inReplyToId = null;
		}

		$parent = $this->parentFor($inReplyToId, $command->authorId);
		$recipientIds = $this->recipientsFor($command, $parent);

		$this->validate($command, $content, $warning, $recipientIds, $parent);

		if ($applyHold) {
			$this->holdIfNeeded($command, $content, $warning, $inReplyToId);
		}

		$postId = $command->postId ?? $this->ids->generate();
		$quotedPostId = $command->quotedPostId === null ? null : trim($command->quotedPostId);
		if ($quotedPostId === '') {
			$quotedPostId = null;
		}

		$quote = null;
		if ($quotedPostId !== null) {
			if ($this->quotes === null) {
				throw new ValidationException(['quoted_post_id' => 'Quote posts are not configured on this instance.']);
			}

			$quote = $this->quotes->prepare($command->authorId, $postId, $quotedPostId);
		}

		$mentionTargets = $this->resolveMentions($content, $command->authorId);

		$post = new Post(
			id: $postId,
			authorId: $command->authorId,
			content: $content,
			visibility: $command->visibility,
			publishedAt: Time::toSecondPrecision($this->clock->now()),
			contentWarning: $warning,
			attachmentIds: $command->attachmentIds,
			poll: $command->poll,
			recipientIds: $recipientIds,
			inReplyToId: $inReplyToId,
			quotedObjectId: $quote?->quotedObjectId,
			quoteState: $quote?->state,
			quoteAuthorizationId: $quote?->authorizationId,
			quoteRequestId: $quote?->requestId,
		);

		// The post row, its attachments/hashtags/poll, the scheduled AT
		// Protocol publication, successful notification inserts and the
		// delivery jobs all address the same database. One transaction means a
		// failed link, AT Protocol job or queue write cannot leave a visible
		// post without its side rows, or delivery jobs without a post.
		// Notifications remain best-effort by contract: a storage failure is
		// logged by NotificationService and does not roll back the primary
		// write, while a successful insert joins this transaction.
		$this->runWrite(function () use ($post, $parent, $quote, $command, $mentionTargets): void {
			$this->posts->insert($post);
			if ($quote !== null) {
				$this->quotes?->commit($quote, $command->authorId);
			}
			$this->atproto?->schedule($post);
			$this->recordMentions($post, $parent, $mentionTargets);
			$this->enqueueDelivery(
				$post,
				fn (): array => $this->activities->createNote($post),
				$mentionTargets,
			);
		});

		$this->searchIndexer?->indexPost($post);

		return $post;
	}

	/**
	 * A hold policy turns a validated post into a durable review item before
	 * any Post row, attachment link, notification or delivery job exists.
	 *
	 * A poll is not held: the draft shape is the text/attachment composer, and
	 * silently dropping a poll on approval would change what the author wrote.
	 */
	private function holdIfNeeded(CreatePost $command, string $content, ?string $warning, ?string $inReplyToId): void
	{
		if ($this->heldPosts === null || $this->holdPolicy === null || $command->poll !== null) {
			return;
		}

		$reason = $this->holdPolicy->holdReason($command->authorId, $content);
		if ($reason === null || trim($reason) === '') {
			return;
		}

		$held = new HeldPost(
			id: $this->ids->generate(),
			authorId: $command->authorId,
			draft: [
				'content' => $content,
				'visibility' => $command->visibility->value,
				'content_warning' => $warning,
				'attachment_ids' => $command->attachmentIds,
				'recipient_ids' => $command->recipientIds,
				'in_reply_to_id' => $inReplyToId,
				'quoted_post_id' => $command->quotedPostId,
			],
			reason: trim($reason),
			status: HeldPostStatus::Pending,
			postId: null,
			createdAt: Time::toSecondPrecision($this->clock->now()),
		);

		$this->heldPosts->insert($held);

		throw new PostHeldException($held);
	}

	public function get(string $postId): Post
	{
		$post = $this->posts->find($postId);
		if ($post === null || $post->isDeleted()) {
			throw new PostNotFoundException($postId);
		}

		return $post;
	}

	/**
	 * Reads a post as a particular viewer.
	 *
	 * A post that is not publicly visible must not be readable just because
	 * somebody guessed its id. The answer is `not found` rather than
	 * `forbidden`, so the existence of a non-public post does not leak either.
	 * A followers-only post is also readable by an accepted local follower and
	 * a direct post by one of its local recipients; the home timeline already
	 * exposes both, so the detail route must agree.
	 *
	 * @throws PostNotFoundException when the post is missing, deleted or not visible to the viewer
	 */
	public function getVisible(string $postId, ?string $viewerId): Post
	{
		$post = $this->get($postId);

		if ($post->visibility->isPubliclyVisible()) {
			return $post;
		}

		if ($viewerId !== null && $post->authorId === $viewerId) {
			return $post;
		}

		if ($viewerId !== null
			&& $post->visibility === Visibility::Direct
			&& in_array($viewerId, $post->recipientIds, true)
		) {
			return $post;
		}

		if ($viewerId !== null
			&& $post->visibility === Visibility::Followers
			&& $this->relationships?->isLocalFollower($viewerId, $post->authorId) === true
		) {
			return $post;
		}

		throw new PostNotFoundException($postId);
	}

	/**
	 * Deletes a post the actor owns.
	 *
	 * @throws PostNotFoundException      when the post does not exist
	 * @throws PostAccessDeniedException  when somebody else wrote it
	 */
	public function delete(string $actorId, string $postId): void
	{
		$post = $this->get($postId);

		if ($post->authorId !== $actorId) {
			throw new PostAccessDeniedException($postId, $actorId);
		}

		$deletedAt = Time::toSecondPrecision($this->clock->now());

		$this->runWrite(function () use ($post, $postId, $deletedAt): void {
			$this->posts->softDelete($postId, $deletedAt);
			$this->pins?->removeForPost($postId);
			$this->atproto?->scheduleDelete($post);

			$this->enqueueDelivery($post, fn (): array => $this->activities->deleteNote($post, $deletedAt));
		});

		$this->searchIndexer?->removePost($postId, $post->authorId);
	}

	/**
	 * Edits a post the actor wrote.
	 *
	 * The author check comes first, then the optimistic one: a caller that read
	 * revision N gets a conflict instead of overwriting an edit that happened
	 * in between. Only the live version is readable afterwards; the replaced
	 * version is appended to the revision history.
	 *
	 * @throws PostNotFoundException     when the post is missing or deleted
	 * @throws PostAccessDeniedException when somebody else wrote it
	 * @throws PostConflictException     when the caller's revision is stale
	 * @throws ValidationException       when the new content or audience is refused
	 */
	public function edit(string $actorId, string $postId, UpdatePost $command): Post
	{
		$post = $this->get($postId);

		if ($post->authorId !== $actorId) {
			throw new PostAccessDeniedException($postId, $actorId);
		}

		if ($command->expectedRevision !== null && $command->expectedRevision !== $post->revision) {
			throw new PostConflictException($postId, $post->revision);
		}

		$content = $command->content === null ? $post->content : trim($command->content);

		$warning = $post->contentWarning;
		if ($command->contentWarningProvided) {
			$warning = $command->contentWarning === null ? null : trim($command->contentWarning);
			if ($warning === '') {
				$warning = null;
			}
		}

		$visibility = $command->visibility ?? $post->visibility;
		$attachmentIds = $command->attachmentIds ?? $post->attachmentIds;

		$this->validateAudienceChange($post, $visibility);

		// The reply target cannot change on an edit, but the parent still
		// decides whether the new visibility is allowed at all.
		$parent = $post->inReplyToId === null ? null : $this->posts->find($post->inReplyToId);
		$this->validateEdit($post, $content, $warning, $attachmentIds, $visibility, $parent);

		$editedAt = Time::toSecondPrecision($this->clock->now());
		$edited = new Post(
			id: $post->id,
			authorId: $post->authorId,
			content: $content,
			visibility: $visibility,
			publishedAt: $post->publishedAt,
			contentWarning: $warning,
			attachmentIds: $attachmentIds,
			poll: $post->poll,
			recipientIds: $post->recipientIds,
			inReplyToId: $post->inReplyToId,
			editedAt: $editedAt,
			revision: $post->revision + 1,
			quotedObjectId: $post->quotedObjectId,
			quoteState: $post->quoteState,
			quoteAuthorizationId: $post->quoteAuthorizationId,
			quoteRequestId: $post->quoteRequestId,
			archivedAt: $post->archivedAt,
		);

		$snapshot = new PostRevision(
			postId: $post->id,
			revision: $post->revision,
			content: $post->content,
			contentWarning: $post->contentWarning,
			visibility: $post->visibility,
			attachmentIds: $post->attachmentIds,
			editedAt: $post->editedAt ?? $post->publishedAt,
		);

		// An Update is only valid while the object keeps its audience. An
		// audience change stays local: re-addressing the note could widen it
		// or strand the previous delivery, and direct posts never federate.
		$deliver = $visibility === $post->visibility && $visibility !== Visibility::Direct;
		$mentionTargets = $deliver ? $this->resolveMentions($content, $post->authorId) : [];

		$this->runWrite(function () use ($edited, $snapshot, $editedAt, $deliver, $mentionTargets): void {
			$this->posts->update($edited);
			$this->revisions?->add($snapshot);

			if ($deliver) {
				$this->enqueueDelivery(
					$edited,
					fn (): array => $this->activities->updateNote($edited, $editedAt),
					$mentionTargets,
				);
			}
		});

		if ($visibility !== Visibility::Public) {
			$this->pins?->removeForPost($post->id);
		}

		$this->searchIndexer?->indexPost($edited);

		return $edited;
	}

	/**
	 * Archives a post the actor owns.
	 *
	 * Archiving changes only local listing state: no Delete is dispatched and
	 * the post's public URL keeps resolving. A pinned post is unpinned because
	 * a featured collection must not advertise a post hidden from the profile.
	 *
	 * @throws PostNotFoundException     when the post does not exist
	 * @throws PostAccessDeniedException when somebody else wrote it
	 */
	public function archive(string $actorId, string $postId): Post
	{
		$post = $this->get($postId);
		if ($post->authorId !== $actorId) {
			throw new PostAccessDeniedException($postId, $actorId);
		}

		if (!$post->isArchived()) {
			$archivedAt = Time::toSecondPrecision($this->clock->now());
			$this->posts->archive($postId, $archivedAt);
			$this->pins?->removeForPost($postId);
			$this->searchIndexer?->removePost($postId, $post->authorId);
		}

		return $this->get($postId);
	}

	/**
	 * Reverses {@see archive()} and restores public discovery indexing when
	 * the post is public. No federation activity is involved either way.
	 *
	 * @throws PostNotFoundException     when the post does not exist
	 * @throws PostAccessDeniedException when somebody else wrote it
	 */
	public function unarchive(string $actorId, string $postId): Post
	{
		$post = $this->get($postId);
		if ($post->authorId !== $actorId) {
			throw new PostAccessDeniedException($postId, $actorId);
		}

		$this->posts->unarchive($postId);
		$restored = $this->get($postId);
		if ($restored->visibility === Visibility::Public) {
			$this->searchIndexer?->indexPost($restored);
		}

		return $restored;
	}

	/**
	 * @return list<Post>
	 */
	public function archived(string $actorId, int $limit = 20, ?DateTimeImmutable $before = null, ?string $beforeId = null): array
	{
		return $this->posts->archivedTimeline($actorId, max(1, min(40, $limit)), $before, $beforeId);
	}

	/**
	 * Resolves a reply target through the same visibility check as a read.
	 *
	 * A missing parent and a parent the author may not see are the same 422:
	 * the write route must not turn an invisible post into an existence
	 * oracle. The parent is needed before recipient validation, because a
	 * direct reply inherits the participants of a direct parent.
	 */
	private function parentFor(?string $inReplyToId, string $authorId): ?Post
	{
		if ($inReplyToId === null) {
			return null;
		}

		try {
			return $this->getVisible($inReplyToId, $authorId);
		} catch (PostNotFoundException) {
			throw new ValidationException([
				'in_reply_to_id' => 'The reply target does not exist or is not visible to this account.',
			]);
		}
	}

	/**
	 * @return list<string>
	 */
	private function recipientsFor(CreatePost $command, ?Post $parent): array
	{
		$raw = $command->recipientIds;
		$errors = [];

		if (!array_is_list($raw)) {
			throw new ValidationException(['recipient_ids' => 'Recipients must be a list of local account ids.']);
		}

		$recipients = [];
		foreach ($raw as $recipientId) {
			if (!is_string($recipientId) || trim($recipientId) === '') {
				$errors['recipient_ids'] = 'Every recipient must be a non-empty local account id.';
				continue;
			}

			$recipientId = trim($recipientId);
			if ($recipientId === $command->authorId) {
				$errors['recipient_ids'] = 'A direct post cannot address its own author.';
				continue;
			}

			if (isset($recipients[$recipientId])) {
				$errors['recipient_ids'] = 'A recipient cannot be repeated.';
				continue;
			}

			$recipients[$recipientId] = $recipientId;
		}

		if ($command->visibility !== Visibility::Direct && $recipients !== []) {
			$errors['recipient_ids'] = 'Recipients are only used by direct posts.';
		}

		if ($command->visibility === Visibility::Direct && $parent !== null) {
			// A direct reply is part of the parent conversation. Keeping the
			// parent author and participants makes it visible to the people
			// who can already see the message it answers.
			if ($parent->authorId !== $command->authorId) {
				$recipients[$parent->authorId] = $parent->authorId;
			}

			foreach ($parent->recipientIds as $recipientId) {
				if ($recipientId !== $command->authorId) {
					$recipients[$recipientId] = $recipientId;
				}
			}
		}

		if ($command->visibility === Visibility::Direct) {
			if ($this->relationships === null) {
				$errors['recipient_ids'] = 'Direct posts require a configured local actor directory.';
			} else {
				foreach ($recipients as $recipientId) {
					if (!$this->relationships->hasLocalActor($recipientId)) {
						$errors['recipient_ids'] = 'Every direct recipient must be a known local account.';
						break;
					}
				}
			}
		}

		if ($errors !== []) {
			throw new ValidationException($errors);
		}

		return array_values($recipients);
	}

	/**
	 * @param list<string> $recipientIds
	 * @param array<string, string> $errors
	 */
	private function validate(
		CreatePost $command,
		string $content,
		?string $warning,
		array $recipientIds,
		?Post $parent,
	): void {
		$errors = [];

		if (trim($command->authorId) === '') {
			$errors['author'] = 'A post needs an author.';
		}

		$errors += $this->contentErrors(
			$content,
			$warning,
			$command->attachmentIds !== [] || $command->poll !== null,
		);
		$errors += $this->attachmentErrors($command->attachmentIds, $command->authorId);
		$errors += $this->parentErrors($parent, $command->visibility);

		if ($command->visibility === Visibility::Direct && $recipientIds === []) {
			$errors['recipient_ids'] = 'A direct post needs at least one local recipient.';
		}

		if ($errors !== []) {
			throw new ValidationException($errors);
		}
	}

	/**
	 * The validation an edit shares with a create, so the two cannot drift.
	 *
	 * @param list<string> $attachmentIds
	 */
	private function validateEdit(
		Post $post,
		string $content,
		?string $warning,
		array $attachmentIds,
		Visibility $visibility,
		?Post $parent,
	): void {
		$errors = $this->contentErrors(
			$content,
			$warning,
			$attachmentIds !== [] || $post->poll !== null,
		)
			+ $this->attachmentErrors($attachmentIds, $post->authorId)
			+ $this->parentErrors($parent, $visibility);

		if ($errors !== []) {
			throw new ValidationException($errors);
		}
	}

	/**
	 * An edit must not widen who can see the post.
	 *
	 * Direct posts are excluded entirely: their audience is an explicit list
	 * of local recipients that an edit cannot change, so moving one into or
	 * out of the direct model would change a different audience than the one
	 * the recipients agreed to.
	 */
	private function validateAudienceChange(Post $post, Visibility $target): void
	{
		if ($target === $post->visibility) {
			return;
		}

		if ($post->visibility === Visibility::Direct || $target === Visibility::Direct) {
			throw new ValidationException([
				'visibility' => 'A direct post keeps its audience; its recipients cannot be edited.',
			]);
		}

		if ($this->audienceRank($target) > $this->audienceRank($post->visibility)) {
			throw new ValidationException(['visibility' => 'An edit cannot widen the audience of a post.']);
		}
	}

	private function audienceRank(Visibility $visibility): int
	{
		return match ($visibility) {
			Visibility::Direct => 0,
			Visibility::Followers => 1,
			Visibility::Unlisted => 2,
			Visibility::Public => 3,
		};
	}

	/**
	 * @return array<string, string>
	 */
	private function contentErrors(string $content, ?string $warning, bool $hasMedia): array
	{
		$errors = [];

		// A media-only post is valid. Only an empty post without an attachment
		// or poll has nothing to publish and is refused.
		if (mb_strlen($content) === 0 && !$hasMedia) {
			$errors['content'] = 'A post needs text or media.';
		} elseif (mb_strlen($content) > self::MAX_CONTENT_LENGTH) {
			$errors['content'] = 'A post may be at most ' . self::MAX_CONTENT_LENGTH . ' characters long.';
		}

		if ($warning !== null && mb_strlen($warning) > self::MAX_CONTENT_WARNING_LENGTH) {
			$errors['content_warning'] = 'A content warning may be at most '
				. self::MAX_CONTENT_WARNING_LENGTH . ' characters long.';
		}

		return $errors;
	}

	/**
	 * @param array<mixed> $attachmentIds
	 *
	 * @return array<string, string>
	 */
	private function attachmentErrors(array $attachmentIds, string $authorId): array
	{
		if (!array_is_list($attachmentIds)) {
			return ['attachment_ids' => 'Attachments must be a list of ids.'];
		}

		$maxAttachments = $this->maxAttachments();
		if (count($attachmentIds) > $maxAttachments) {
			return ['attachment_ids' => 'A post may have at most ' . $maxAttachments . ' attachments.'];
		}

		$seen = [];
		foreach ($attachmentIds as $attachmentId) {
			if (!is_string($attachmentId) || $attachmentId === '') {
				return ['attachment_ids' => 'An attachment id must be a non-empty string.'];
			}

			if (isset($seen[$attachmentId])) {
				return ['attachment_ids' => 'An attachment cannot be used twice.'];
			}

			$seen[$attachmentId] = true;
			$attachment = $this->attachments->find($attachmentId);
			if ($attachment === null || $attachment->ownerId !== $authorId) {
				return ['attachment_ids' => 'An attachment does not exist for this account.'];
			}
		}

		return [];
	}

	/**
	 * @return array<string, string>
	 */
	private function parentErrors(?Post $parent, Visibility $visibility): array
	{
		if ($parent === null) {
			return [];
		}

		if ($parent->visibility === Visibility::Direct && $visibility !== Visibility::Direct) {
			return ['in_reply_to_id' => 'A reply to a direct post must also be direct.'];
		}

		if ($parent->visibility === Visibility::Followers
			&& ($visibility === Visibility::Public || $visibility === Visibility::Unlisted)
		) {
			return ['in_reply_to_id' => 'A reply to a followers-only post cannot be public or unlisted.'];
		}

		return [];
	}

	private function maxAttachments(): int
	{
		$configured = $this->config?->getInt('social', self::CONFIG_MAX_ATTACHMENTS, Post::MAX_ATTACHMENTS)
			?? Post::MAX_ATTACHMENTS;

		return max(1, min(Post::MAX_ATTACHMENTS, $configured));
	}

	/**
	 * A reply notifies its parent author; a direct post notifies every other
	 * recipient. The notification service uses a deterministic id, so the
	 * parent author appearing in both sets is still one row.
	 */
	/**
	 * @param list<MentionTarget> $mentionTargets
	 */
	private function recordMentions(Post $post, ?Post $parent, array $mentionTargets = []): void
	{
		if ($this->notifications === null) {
			return;
		}

		$notified = [$post->authorId => true];
		if ($parent !== null && $parent->authorId !== $post->authorId) {
			$this->notifications->recordMention($parent->authorId, $post->authorId, $post->id);
			$notified[$parent->authorId] = true;
		}

		foreach ($post->recipientIds as $recipientId) {
			if ($recipientId !== $post->authorId && !isset($notified[$recipientId])) {
				$this->notifications->recordMention($recipientId, $post->authorId, $post->id);
				$notified[$recipientId] = true;
			}
		}

		foreach ($mentionTargets as $target) {
			$recipientId = $target->localId;
			if ($recipientId !== null && !isset($notified[$recipientId])) {
				$this->notifications->recordMention($recipientId, $post->authorId, $post->id);
				$notified[$recipientId] = true;
			}
		}
	}

	/**
	 * Parses the handles in the text and resolves them to local accounts or
	 * remote actors. Resolution is best effort: an unreachable WebFinger or
	 * actor document must never prevent the post from being stored.
	 *
	 * @return list<MentionTarget>
	 */
	private function resolveMentions(string $content, string $authorId): array
	{
		if (preg_match_all(self::MENTION_PATTERN, $content, $matches, PREG_SET_ORDER) === false) {
			return [];
		}

		$targets = [];
		$seen = [];
		foreach ($matches as $match) {
			$user = rtrim((string) ($match[1] ?? ''), '.');
			$host = isset($match[2]) && $match[2] !== '' ? strtolower((string) $match[2]) : null;
			if ($user === '') {
				continue;
			}

			if ($host === null || $this->isLocalMentionHost($host)) {
				$localId = strtolower($user);
				if ($localId === $authorId || $this->relationships?->hasLocalActor($localId) !== true) {
					continue;
				}

				$target = new MentionTarget(
					handle: $localId,
					actorId: $this->activities->actorId($localId),
					localId: $localId,
				);
			} else {
				$handle = strtolower($user . '@' . $host);
				try {
					$target = $this->mentions?->resolve($handle);
				} catch (Throwable) {
					$target = null;
				}

				if ($target === null || $target->inboxUrl === null) {
					continue;
				}
			}

			if (isset($seen[$target->actorId])) {
				continue;
			}

			$seen[$target->actorId] = true;
			$targets[] = $target;
		}

		return $targets;
	}

	private function isLocalMentionHost(string $host): bool
	{
		$base = $this->activities->baseUrl();
		$configured = strtolower((string) parse_url($base, PHP_URL_HOST));
		$host = strtolower(rtrim($host, '.'));

		if ($host === $configured) {
			return true;
		}

		$port = parse_url($base, PHP_URL_PORT);

		return is_int($port) && $host === $configured . ':' . $port;
	}

	/**
	 * Runs the database writes of one use case in one transaction when the
	 * host supplied a transaction runner. The in-memory assembly runs the same
	 * closure directly; its repositories have no transaction to join.
	 *
	 * @param Closure(): void $write
	 */
	private function runWrite(Closure $write): void
	{
		if ($this->transactions === null) {
			$write();

			return;
		}

		$this->transactions->transaction($write);
	}

	/**
	 * @param Closure(): array<string, mixed> $buildActivity
	 */
	/**
	 * @param list<MentionTarget> $mentionTargets
	 */
	private function enqueueDelivery(Post $post, Closure $buildActivity, array $mentionTargets = []): void
	{
		// Direct posts are local-only: their recipients are local ids, not
		// remote inboxes. Delivering them to the author's followers would
		// leak a message the audience did not ask for.
		if ($post->visibility === Visibility::Direct) {
			return;
		}

		$mentionInboxes = [];
		foreach ($mentionTargets as $target) {
			if ($target->inboxUrl !== null && trim($target->inboxUrl) !== '') {
				$mentionInboxes[] = $target->inboxUrl;
			}
		}

		$inboxes = array_values(array_unique(array_filter(
			array_merge($this->inboxes->inboxesFor($post->authorId), $mentionInboxes),
			static fn (mixed $inbox): bool => is_string($inbox) && trim($inbox) !== '',
		)));

		if ($inboxes === []) {
			return;
		}

		$activity = $this->withMentionTags($buildActivity(), $mentionTargets);
		$keyId = $this->activities->keyId($post->authorId);

		foreach ($inboxes as $inbox) {
			$this->queue->push(JobType::FEDERATION_DELIVER, [
				'inbox' => $inbox,
				'activity' => $activity,
				'key_id' => $keyId,
			], subject: JobSubject::account($post->authorId));
		}
	}

	/**
	 * Adds the standard `Mention` tags and puts the mentioned actors into the
	 * `cc` audience. Delivery already includes their inboxes, but without the
	 * tag a peer renders the text as plain prose and cannot notify the actor.
	 *
	 * @param array<string, mixed> $activity
	 * @param list<MentionTarget>  $targets
	 *
	 * @return array<string, mixed>
	 */
	private function withMentionTags(array $activity, array $targets): array
	{
		if ($targets === []) {
			return $activity;
		}

		$tags = [];
		$cc = is_array($activity['cc'] ?? null) ? array_values($activity['cc']) : [];
		foreach ($targets as $target) {
			$tags[] = [
				'type' => 'Mention',
				'href' => $target->actorId,
				'name' => '@' . $target->handle,
			];

			if (!in_array($target->actorId, $cc, true)) {
				$cc[] = $target->actorId;
			}
		}

		$activity['tag'] = $tags;
		$activity['cc'] = $cc;

		if (is_array($activity['object'] ?? null)) {
			$activity['object']['tag'] = $tags;
			$activity['object']['cc'] = $cc;
		}

		return $activity;
	}
}
