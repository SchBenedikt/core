<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Story;

use AlohaSocial\Core\Domain\Federation\FollowerRepository;
use AlohaSocial\Core\Domain\Federation\FollowingRepository;
use AlohaSocial\Core\Domain\Federation\InboxRejectedException;
use AlohaSocial\Core\Domain\Media\Attachment;
use AlohaSocial\Core\Domain\Media\MediaKind;
use AlohaSocial\Core\Domain\Story\Story;
use AlohaSocial\Core\Domain\Story\StoryReaction;
use AlohaSocial\Core\Domain\Story\StoryReply;
use AlohaSocial\Core\Domain\Story\StoryRepository;
use AlohaSocial\Core\Jobs\JobQueue;
use AlohaSocial\Core\Jobs\JobType;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Config\Config;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;
use AlohaSocial\Core\Service\MediaService;
use AlohaSocial\Core\Service\ValidationException;
use AlohaSocial\Core\Support\IdGenerator;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use InvalidArgumentException;

use function array_is_list;
use function array_map;
use function array_unique;
use function array_values;
use function count;
use function filter_var;
use function in_array;
use function is_array;
use function is_string;
use function max;
use function mb_strlen;
use function min;
use function parse_url;
use function rawurlencode;
use function str_ends_with;
use function trim;

/**
 * Local story lifecycle and the Pixelfed Add/Delete boundary.
 *
 * A story is always followers-only. The service never builds a public post
 * or outbox entry from it, and all read methods go through {@see StoryAccess}.
 * Expiry is enforced by the repository query and by the access check; the
 * worker job only removes the stored row and its local media.
 */
final class StoryService
{
	public const CONFIG_APP = 'social';

	public const CONFIG_KEY = 'stories.enabled';

	public const CONFIG_TTL_KEY = 'stories.ttl_hours';

	public const DEFAULT_TTL_HOURS = 24;

	public const MAX_TTL_HOURS = 24;

	public const DEFAULT_LIMIT = 20;

	public const MAX_LIMIT = 50;

	public const MAX_REPLIES = 50;

	public function __construct(
		private readonly Config $config,
		private readonly StoryRepository $stories,
		private readonly StoryAccess $access,
		private readonly ?MediaService $media,
		private readonly FollowerRepository $followers,
		private readonly FollowingRepository $following,
		private readonly JobQueue $queue,
		private readonly ActivityFactory $activities,
		private readonly Clock $clock,
		private readonly IdGenerator $ids,
	) {
	}

	public function enabled(): bool
	{
		return $this->config->getBool(self::CONFIG_APP, self::CONFIG_KEY);
	}

	public function hasObject(string $objectId): bool
	{
		return $this->stories->findByObjectId($objectId) !== null;
	}

	public function ttlHours(): int
	{
		return max(1, min(self::MAX_TTL_HOURS, $this->config->getInt(self::CONFIG_APP, self::CONFIG_TTL_KEY, self::DEFAULT_TTL_HOURS)));
	}

	/**
	 * @throws StoryDisabledException
	 * @throws StoryMediaUnavailableException
	 * @throws ValidationException
	 */
	public function create(string $ownerId, string $attachmentId, string $caption = ''): Story
	{
		$this->assertEnabled();
		if ($this->media === null) {
			throw new StoryMediaUnavailableException();
		}

		$attachment = $this->media->find($attachmentId);
		if ($attachment === null || $attachment->ownerId !== $ownerId) {
			throw new ValidationException(['attachment_id' => 'The attachment is not owned by this account.']);
		}

		if (!in_array($attachment->kind, [MediaKind::Image, MediaKind::Video], true)) {
			throw new ValidationException(['attachment_id' => 'A story needs an image or a video.']);
		}

		if ($this->media->isAttachedToPost($attachment)) {
			throw new ValidationException(['attachment_id' => 'The attachment is already used by a post.']);
		}

		if ($this->stories->findByAttachmentId($attachmentId) !== null) {
			throw new ValidationException(['attachment_id' => 'The attachment is already used by another story.']);
		}

		$caption = trim($caption);
		if (mb_strlen($caption) > Story::MAX_CAPTION_LENGTH) {
			throw new ValidationException(['caption' => 'The caption is too long.']);
		}

		$now = Time::toSecondPrecision($this->clock->now());
		$id = $this->ids->generate();
		$story = new Story(
			$id,
			$ownerId,
			true,
			$this->activities->baseUrl() . '/stories/' . $id,
			$caption,
			$now,
			$now->modify('+' . $this->ttlHours() . ' hours'),
			$attachmentId,
		);
		$this->stories->insert($story);
		$this->queueDelivery($story, $this->activities->addStory(
			$story,
			$this->storyMediaUrl($story),
			$attachment->mimeType,
		));
		$this->queueExpiry($story);

		return $story;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function feed(string $viewerId, int $limit = self::DEFAULT_LIMIT): array
	{
		$this->assertEnabled();
		$limit = max(1, min(self::MAX_LIMIT, $limit));
		$owners = [$viewerId, ...$this->following->actorIds($viewerId)];
		$localOwners = $this->followers->localUserIdsFollowedBy($this->activities->actorId($viewerId));
		$owners = array_values(array_unique([...$owners, ...$localOwners]));

		$presented = [];
		foreach ($this->stories->activeForOwners($owners, $this->clock->now(), $limit * 2) as $story) {
			if ($this->access->canView($viewerId, $story)) {
				$presented[] = $this->present($story);
			}
			if (count($presented) >= $limit) {
				break;
			}
		}

		return $presented;
	}

	/**
	 * @throws StoryNotFoundException
	 */
	public function show(string $viewerId, string $storyId): array
	{
		return $this->present($this->visible($viewerId, $storyId));
	}

	/**
	 * @throws StoryNotFoundException
	 */
	public function delete(string $ownerId, string $storyId): void
	{
		$this->assertEnabled();
		$story = $this->stories->find($storyId);
		if ($story === null || $story->ownerId !== $ownerId) {
			throw new StoryNotFoundException();
		}

		if (!$story->isDeleted()) {
			$now = Time::toSecondPrecision($this->clock->now());
			$this->stories->softDelete($story->id, $now);
			$this->queueDelivery($story, $this->activities->deleteStory($story, $now));
		}
	}

	/**
	 * @throws StoryNotFoundException
	 */
	public function markView(string $viewerId, string $storyId): void
	{
		$story = $this->visible($viewerId, $storyId);
		$this->stories->recordView($story->id, $viewerId, Time::toSecondPrecision($this->clock->now()));
	}

	/**
	 * @throws StoryForbiddenException
	 * @throws StoryNotFoundException
	 *
	 * @return list<array{viewer_id: string, viewed_at: string}>
	 */
	public function viewers(string $ownerId, string $storyId): array
	{
		$this->assertEnabled();
		$story = $this->stories->find($storyId);
		if ($story === null || $story->isDeleted() || $story->isExpiredAt($this->clock->now())) {
			throw new StoryNotFoundException();
		}

		if ($story->ownerId !== $ownerId) {
			throw new StoryForbiddenException();
		}

		return $this->stories->viewers($story->id);
	}

	/**
	 * @throws StoryNotFoundException
	 *
	 * @return array<string, mixed>
	 */
	public function react(string $viewerId, string $storyId, string $emoji): array
	{
		$story = $this->visible($viewerId, $storyId);
		$reaction = new StoryReaction($story->id, $viewerId, $emoji, Time::toSecondPrecision($this->clock->now()));
		$this->stories->saveReaction($reaction);

		return $reaction->toArray();
	}

	/**
	 * @throws StoryNotFoundException
	 *
	 * @return list<array<string, mixed>>
	 */
	public function reactions(string $viewerId, string $storyId): array
	{
		$story = $this->visible($viewerId, $storyId);

		return array_map(static fn (StoryReaction $reaction): array => $reaction->toArray(), $this->stories->reactions($story->id));
	}

	/**
	 * @throws StoryNotFoundException
	 * @throws ValidationException
	 *
	 * @return array<string, mixed>
	 */
	public function reply(string $viewerId, string $storyId, string $content): array
	{
		$story = $this->visible($viewerId, $storyId);
		$content = trim($content);
		if ($content === '' || mb_strlen($content) > StoryReply::MAX_CONTENT_LENGTH) {
			throw new ValidationException(['content' => 'A reply must be between 1 and ' . StoryReply::MAX_CONTENT_LENGTH . ' characters.']);
		}

		$reply = new StoryReply(
			$this->ids->generate(),
			$story->id,
			$viewerId,
			$content,
			Time::toSecondPrecision($this->clock->now()),
		);
		$this->stories->saveReply($reply);

		return $reply->toArray();
	}

	/**
	 * @throws StoryNotFoundException
	 *
	 * @return list<array<string, mixed>>
	 */
	public function replies(string $viewerId, string $storyId): array
	{
		$story = $this->visible($viewerId, $storyId);

		return array_map(static fn (StoryReply $reply): array => $reply->toArray(), $this->stories->replies($story->id, self::MAX_REPLIES));
	}

	/**
	 * @throws StoryNotFoundException
	 * @throws StoryMediaUnavailableException
	 *
	 * @return array{attachment: Attachment, bytes: string}
	 */
	public function readMedia(string $viewerId, string $storyId): array
	{
		$story = $this->visible($viewerId, $storyId);
		if ($story->attachmentId === null || $this->media === null) {
			throw new StoryMediaUnavailableException();
		}

		$attachment = $this->media->find($story->attachmentId);
		if ($attachment === null) {
			throw new StoryMediaUnavailableException();
		}

		return ['attachment' => $attachment, 'bytes' => $this->media->read($attachment)];
	}

	/**
	 * Marks every expired row deleted and removes its local media when no
	 * post still links it. Repeated calls are safe and idempotent.
	 */
	public function expireDue(): int
	{
		$now = Time::toSecondPrecision($this->clock->now());
		$expired = $this->stories->expiredBefore($now, 200);
		foreach ($expired as $story) {
			if ($this->stories->softDelete($story->id, $now) && $story->attachmentId !== null && $this->media !== null) {
				$attachment = $this->media->find($story->attachmentId);
				if ($attachment !== null && !$this->media->isAttachedToPost($attachment)) {
					$this->media->discard($attachment);
				}
			}
		}

		return count($expired);
	}

	/**
	 * Receives a Pixelfed `Add` whose object is a Story. It is accepted only
	 * for an actor a local account follows; the audience must not include the
	 * public collection, and the stored expiry is clamped to local policy.
	 *
	 * @param array<string, mixed> $activity
	 *
	 * @throws InboxRejectedException
	 */
	public function receiveAdd(string $actorId, array $activity): ?Story
	{
		if (!$this->enabled()) {
			throw new InboxRejectedException('stories_disabled', 'Stories are disabled on this instance.');
		}

		if ($this->following->localUserIdsForActor($actorId) === []) {
			throw new InboxRejectedException('not_followed', 'Stories are accepted only from a followed actor.');
		}

		$object = $activity['object'] ?? null;
		if (!is_array($object)) {
			throw new InboxRejectedException('invalid_story', 'An Add needs a Story object.');
		}

		$type = $object['type'] ?? null;
		if (!is_string($type) || !in_array($type, ['Story', 'Note'], true)) {
			throw new InboxRejectedException('invalid_story', 'The Add object must be a Story.');
		}

		if ($this->containsPublic($object['to'] ?? null) || $this->containsPublic($object['cc'] ?? null)) {
			throw new InboxRejectedException('story_public', 'A story must be followers-only.');
		}

		$objectId = $object['id'] ?? null;
		if (!is_string($objectId) || trim($objectId) === '') {
			throw new InboxRejectedException('invalid_story', 'The story has no object id.');
		}

		$objectId = trim($objectId);
		if ($this->stories->isTombstoned($objectId, $actorId)) {
			return null;
		}

		$existing = $this->stories->findByObjectId($objectId);
		if ($existing !== null) {
			if ($existing->ownerId !== $actorId) {
				throw new InboxRejectedException('actor_mismatch', 'The story belongs to another actor.');
			}

			return $existing->isDeleted() ? null : $existing;
		}

		$mediaUrl = $this->attachmentUrl($object);
		if ($mediaUrl === null || !$this->isHttpUrl($mediaUrl)) {
			throw new InboxRejectedException('invalid_story', 'The story needs an http(s) media URL.');
		}

		$caption = $object['content'] ?? '';
		if (!is_string($caption) || mb_strlen($caption) > Story::MAX_CAPTION_LENGTH) {
			throw new InboxRejectedException('invalid_story', 'The story caption is invalid.');
		}

		$now = Time::toSecondPrecision($this->clock->now());
		$created = $this->parseTime($object['published'] ?? null) ?? $now;
		$end = $this->parseTime($object['endTime'] ?? $object['expiresAt'] ?? null);
		$expires = min(
			$end ?? $created->modify('+' . $this->ttlHours() . ' hours'),
			$now->modify('+' . $this->ttlHours() . ' hours'),
		);
		if ($expires <= $now) {
			return null;
		}

		$story = new Story(
			$this->ids->generate(),
			$actorId,
			false,
			$objectId,
			$caption,
			$created,
			$expires,
			null,
			$mediaUrl,
		);
		$this->stories->insert($story);
		$this->queueExpiry($story);

		return $story;
	}

	/**
	 * @throws InboxRejectedException
	 */
	public function receiveDelete(string $actorId, string $objectId): void
	{
		if (!$this->enabled()) {
			throw new InboxRejectedException('stories_disabled', 'Stories are disabled on this instance.');
		}

		$story = $this->stories->findByObjectId($objectId);
		$now = Time::toSecondPrecision($this->clock->now());
		if ($story === null) {
			$this->stories->markTombstone($objectId, $actorId, $now);

			return;
		}

		if ($story->ownerId !== $actorId) {
			throw new InboxRejectedException('actor_mismatch', 'The story belongs to another actor.');
		}

		if ($story->isDeleted()) {
			$this->stories->markTombstone($story->objectId, $story->ownerId, $now);

			return;
		}

		$this->stories->softDelete($story->id, $now);
	}

	private function assertEnabled(): void
	{
		if (!$this->enabled()) {
			throw new StoryDisabledException();
		}
	}

	/**
	 * @throws StoryNotFoundException
	 */
	private function visible(string $viewerId, string $storyId): Story
	{
		$this->assertEnabled();
		$story = $this->stories->find($storyId);
		if ($story === null || !$this->access->canView($viewerId, $story)) {
			throw new StoryNotFoundException();
		}

		return $story;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function present(Story $story): array
	{
		$data = $story->toArray();
		$data['media'] = null;
		if ($story->attachmentId !== null && $this->media !== null) {
			$attachment = $this->media->find($story->attachmentId);
			if ($attachment !== null) {
				$data['media'] = [
					'attachment_id' => $attachment->id,
					'url' => '/api/v1/stories/' . rawurlencode($story->id) . '/media',
					'mime_type' => $attachment->mimeType,
					'width' => $attachment->width,
					'height' => $attachment->height,
					'alt_text' => $attachment->altText,
					'kind' => $attachment->kind->value,
				];
			}
		} elseif ($story->remoteMediaUrl !== null) {
			$data['media'] = ['url' => $story->remoteMediaUrl, 'remote' => true];
		}

		return $data;
	}

	/**
	 * @param array<string, mixed> $activity
	 */
	private function queueDelivery(Story $story, array $activity): void
	{
		foreach (array_unique($this->followers->inboxesFor($story->ownerId)) as $inbox) {
			$this->queue->push(JobType::FEDERATION_DELIVER, [
				'inbox' => $inbox,
				'activity' => $activity,
				'key_id' => $this->activities->keyId($story->ownerId),
			]);
		}
	}

	private function queueExpiry(Story $story): void
	{
		$this->queue->push(JobType::STORY_EXPIRE, ['story_id' => $story->id], $story->expiresAt);
	}

	private function storyMediaUrl(Story $story): ?string
	{
		return $this->activities->baseUrl() . '/api/v1/stories/' . rawurlencode($story->id) . '/media';
	}

	/**
	 * @param array<string, mixed> $object
	 */
	private function attachmentUrl(array $object): ?string
	{
		$attachments = $object['attachment'] ?? null;
		if (is_array($attachments) && array_is_list($attachments) && $attachments !== []) {
			$attachments = $attachments[0];
		}

		if (is_array($attachments)) {
			$url = $attachments['url'] ?? $attachments['href'] ?? null;
			if (is_string($url)) {
				return trim($url);
			}
		}

		return is_string($object['url'] ?? null) ? trim($object['url']) : null;
	}

	private function isHttpUrl(string $value): bool
	{
		if (mb_strlen($value) > Story::MAX_MEDIA_URL_LENGTH || filter_var($value, FILTER_VALIDATE_URL) === false) {
			return false;
		}

		$scheme = parse_url($value, PHP_URL_SCHEME);

		return $scheme === 'https' || $scheme === 'http';
	}

	private function containsPublic(mixed $value): bool
	{
		if (is_string($value)) {
			return $value === ActivityFactory::PUBLIC_COLLECTION || str_ends_with($value, '#Public');
		}

		if (is_array($value)) {
			foreach ($value as $entry) {
				if ($this->containsPublic($entry)) {
					return true;
				}
			}
		}

		return false;
	}

	private function parseTime(mixed $value): ?DateTimeImmutable
	{
		if (!is_string($value) || trim($value) === '') {
			return null;
		}

		try {
			return Time::parseIso8601($value);
		} catch (InvalidArgumentException) {
			return null;
		}
	}
}
