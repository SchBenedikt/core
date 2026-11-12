<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Federation;

use AlohaSocial\Core\Domain\Federation\InboxRejectedException;
use AlohaSocial\Core\Domain\Federation\RemotePost;
use AlohaSocial\Core\Domain\Federation\RemotePostKind;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use Exception;

use function is_array;
use function is_string;

/**
 * Turns a validated ActivityPub Create/Announce into a RemotePost.
 *
 * The inbox and the outbox backfill share these rules so a post delivered in
 * real time and the same post fetched later cannot get different content,
 * timestamps or attribution. Validation refusals use the same stable
 * `InboxRejectedException` reasons as the inbox.
 */
final class RemotePostFactory
{
	public function __construct(
		private readonly Clock $clock,
	) {
	}

	/**
	 * @param array<string, mixed> $activity
	 */
	public function fromCreate(string $actorId, array $activity): RemotePost
	{
		$activityId = $this->activityId($activity);
		$object = $activity['object'] ?? null;
		if (!is_array($object)) {
			throw new InboxRejectedException('invalid_activity', 'A Create needs an embedded object.');
		}

		$objectId = $this->objectId($object, 'Create');
		$objectActor = $this->objectAuthor($object, $actorId);
		if ($objectActor !== $actorId) {
			throw new InboxRejectedException(
				'actor_mismatch',
				'The created object belongs to another actor.',
			);
		}

		$content = $object['content'] ?? null;
		if (!is_string($content) || trim($content) === '') {
			throw new InboxRejectedException('invalid_activity', 'A created Note needs string content.');
		}

		return new RemotePost(
			activityId: $activityId,
			objectId: $objectId,
			actorId: $actorId,
			kind: RemotePostKind::Post,
			content: $content,
			contentWarning: $this->contentWarning($object),
			publishedAt: $this->publishedAt($object['published'] ?? null, $activity['published'] ?? null),
			objectAuthorId: $objectActor,
		);
	}

	/**
	 * @param array<string, mixed> $activity
	 */
	public function fromAnnounce(string $actorId, array $activity, ?RemotePost $target = null): RemotePost
	{
		$activityId = $this->activityId($activity);
		$object = $activity['object'] ?? null;
		$objectId = $this->targetId($object, 'Announce');

		$embedded = is_array($object) ? $object : [];
		$content = $this->optionalContent($embedded);
		$contentWarning = $this->contentWarning($embedded);

		return new RemotePost(
			activityId: $activityId,
			objectId: $objectId,
			actorId: $actorId,
			kind: RemotePostKind::Boost,
			content: $content ?? $target?->content ?? '',
			contentWarning: $contentWarning ?? $target?->contentWarning,
			publishedAt: $this->publishedAt(
				$activity['published'] ?? null,
				$embedded['published'] ?? null,
				$target === null ? null : Time::format($target->publishedAt),
			),
			objectAuthorId: $this->announcedObjectAuthor($embedded) ?? $target?->objectAuthorId,
		);
	}

	/**
	 * Resolves the target of a Delete or Announce.
	 */
	public function targetId(mixed $object, string $context): string
	{
		if (is_string($object) && trim($object) !== '') {
			$id = trim($object);
		} elseif (is_array($object)) {
			$id = $this->objectId($object, $context);
		} else {
			throw new InboxRejectedException(
				'invalid_activity',
				'A ' . $context . ' needs an object id.',
			);
		}

		if (mb_strlen($id) > RemotePost::MAX_ID_LENGTH) {
			throw new InboxRejectedException('invalid_activity', 'The object id is too long.');
		}

		return $id;
	}

	/**
	 * @param array<string, mixed> $activity
	 */
	private function activityId(array $activity): string
	{
		$id = $activity['id'] ?? null;
		if (!is_string($id) || trim($id) === '') {
			throw new InboxRejectedException('invalid_activity', 'An activity needs a string "id".');
		}

		$id = trim($id);
		if (mb_strlen($id) > RemotePost::MAX_ID_LENGTH) {
			throw new InboxRejectedException('invalid_activity', 'The activity id is too long.');
		}

		return $id;
	}

	/**
	 * @param array<string, mixed> $object
	 */
	private function objectId(array $object, string $context): string
	{
		$id = $object['id'] ?? null;
		if (!is_string($id) || trim($id) === '') {
			throw new InboxRejectedException('invalid_activity', 'A ' . $context . ' object needs an id.');
		}

		$id = trim($id);
		if (mb_strlen($id) > RemotePost::MAX_ID_LENGTH) {
			throw new InboxRejectedException('invalid_activity', 'The object id is too long.');
		}

		return $id;
	}

	/**
	 * @param array<string, mixed> $object
	 */
	private function contentWarning(array $object): ?string
	{
		$summary = $object['summary'] ?? null;
		if ($summary === null) {
			return null;
		}

		if (!is_string($summary)) {
			throw new InboxRejectedException('invalid_activity', 'A content warning must be a string.');
		}

		$summary = trim($summary);
		if ($summary === '') {
			return null;
		}

		if (mb_strlen($summary) > RemotePost::MAX_WARNING_LENGTH) {
			throw new InboxRejectedException('invalid_activity', 'The content warning is too long.');
		}

		return $summary;
	}

	/**
	 * @param array<string, mixed> $object
	 */
	private function optionalContent(array $object): ?string
	{
		$content = $object['content'] ?? null;
		if ($content === null) {
			return null;
		}

		if (!is_string($content)) {
			throw new InboxRejectedException('invalid_activity', 'Announced content must be a string.');
		}

		return $content;
	}

	/**
	 * @param array<string, mixed> $object
	 */
	private function objectAuthor(array $object, string $fallback): string
	{
		$author = $object['attributedTo'] ?? $fallback;
		if (!is_string($author) || $author === '') {
			throw new InboxRejectedException('invalid_activity', 'The object author must be an actor id.');
		}

		if (mb_strlen($author) > RemotePost::MAX_ACTOR_LENGTH) {
			throw new InboxRejectedException('invalid_activity', 'The object author id is too long.');
		}

		return $author;
	}

	/**
	 * @param array<string, mixed> $object
	 */
	private function announcedObjectAuthor(array $object): ?string
	{
		$author = $object['attributedTo'] ?? null;
		if ($author === null) {
			return null;
		}

		if (!is_string($author) || $author === '') {
			throw new InboxRejectedException('invalid_activity', 'The announced author must be an actor id.');
		}

		if (mb_strlen($author) > RemotePost::MAX_ACTOR_LENGTH) {
			throw new InboxRejectedException('invalid_activity', 'The announced author id is too long.');
		}

		return $author;
	}

	private function publishedAt(mixed ...$values): DateTimeImmutable
	{
		foreach ($values as $value) {
			if ($value === null) {
				continue;
			}

			if (!is_string($value) || trim($value) === '') {
				throw new InboxRejectedException('invalid_activity', 'A published timestamp must be a string.');
			}

			$value = trim($value);
			if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value) !== 1) {
				throw new InboxRejectedException('invalid_activity', 'A published timestamp is not RFC 3339.');
			}

			try {
				return Time::toSecondPrecision(new DateTimeImmutable($value));
			} catch (Exception) {
				throw new InboxRejectedException('invalid_activity', 'A published timestamp is invalid.');
			}
		}

		return Time::toSecondPrecision($this->clock->now());
	}
}
