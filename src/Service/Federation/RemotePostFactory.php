<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Federation;

use AlohaSocial\Core\Domain\Federation\InboxRejectedException;
use AlohaSocial\Core\Domain\Federation\PendingRemoteUpdate;
use AlohaSocial\Core\Domain\Federation\RemoteEventMetadata;
use AlohaSocial\Core\Domain\Federation\RemoteEventStatus;
use AlohaSocial\Core\Domain\Federation\RemoteMediaMetadata;
use AlohaSocial\Core\Domain\Federation\RemoteObjectMetadata;
use AlohaSocial\Core\Domain\Federation\RemoteObjectType;
use AlohaSocial\Core\Domain\Federation\RemotePost;
use AlohaSocial\Core\Domain\Federation\RemotePostKind;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use Exception;

use function array_is_list;
use function count;
use function html_entity_decode;
use function in_array;
use function is_array;
use function is_string;
use function mb_strlen;
use function parse_url;
use function preg_match;
use function strip_tags;
use function strtolower;
use function trim;

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
	public function fromCreate(string $actorId, array $activity, ?string $followersUrl = null): RemotePost
	{
		$audience = $this->audience($actorId, $activity, $followersUrl);
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

		$content = $this->optionalContent($object);
		$objectType = RemoteObjectType::fromActivityType($object['type'] ?? null);
		$metadata = $this->metadata($object, $objectType ?? RemoteObjectType::Note);

		// A media-only Video, an Event card or an Article may legitimately have
		// no body. Refuse only an object that has nothing to render at all.
		if (($content === null || trim($content) === '') && $metadata === null) {
			throw new InboxRejectedException(
				'invalid_activity',
				'A created object needs content or typed metadata.',
			);
		}

		return new RemotePost(
			activityId: $activityId,
			objectId: $objectId,
			actorId: $actorId,
			kind: RemotePostKind::Post,
			content: $content ?? '',
			contentWarning: $this->contentWarning($object),
			publishedAt: $this->publishedAt($object['published'] ?? null, $activity['published'] ?? null),
			objectAuthorId: $objectActor,
			objectType: $objectType ?? RemoteObjectType::Note,
			metadata: $metadata,
			quotedObjectId: $this->quotedObjectId($object),
			inReplyToId: $this->referenceId($object['inReplyTo'] ?? null),
			audience: $audience,
		);
	}

	/**
	 * The timeline store currently represents public and follower-visible posts.
	 * Do not turn a direct message (or an unaddressed object) into timeline data.
	 *
	 * @param array<string, mixed> $activity
	 */
	private function audience(string $actorId, array $activity, ?string $followersUrl): Visibility
	{
		$object = $activity['object'] ?? null;
		$public = 'https://www.w3.org/ns/activitystreams#Public';
		$followers = $followersUrl ?? rtrim($actorId, '/') . '/followers';
		$recipients = [];
		foreach ([$activity, is_array($object) ? $object : []] as $document) {
			$documentRecipients = [];
			foreach (['to', 'cc', 'audience'] as $field) {
				$value = $document[$field] ?? [];
				if (is_string($value)) {
					$documentRecipients[] = $value;
				} elseif (is_array($value)) {
					foreach ($value as $recipient) {
						if (is_string($recipient)) {
							$documentRecipients[] = $recipient;
						}
					}
				}
			}
			if ($documentRecipients !== []
				&& !in_array($public, $documentRecipients, true)
				&& !in_array($followers, $documentRecipients, true)
			) {
				throw new InboxRejectedException(
					'unsupported_audience',
					'The remote post is not addressed to a supported public or followers audience.',
				);
			}
			$recipients = [...$recipients, ...$documentRecipients];
		}

		if (in_array($public, $recipients, true)) {
			return Visibility::Public;
		}
		if (in_array($followers, $recipients, true)) {
			return Visibility::Followers;
		}

		throw new InboxRejectedException(
			'unsupported_audience',
			'The remote post is not addressed to a supported public or followers audience.',
		);
	}

	/**
	 * An Announce is often addressed publicly even when its embedded object is
	 * not. The stored target's audience wins; otherwise the activity's own
	 * recipients are the only available signal. Unknown stays unknown and
	 * therefore requires a follow before interaction.
	 *
	 * @param array<string, mixed> $activity
	 */
	private function optionalAudience(string $actorId, array $activity): ?Visibility
	{
		$public = 'https://www.w3.org/ns/activitystreams#Public';
		$followers = rtrim($actorId, '/') . '/followers';
		$recipients = [];
		foreach (['to', 'cc', 'audience'] as $field) {
			$value = $activity[$field] ?? [];
			if (is_string($value)) {
				$recipients[] = $value;
			} elseif (is_array($value)) {
				foreach ($value as $recipient) {
					if (is_string($recipient)) {
						$recipients[] = $recipient;
					}
				}
			}
		}

		if (in_array($public, $recipients, true)) {
			return Visibility::Public;
		}
		if (in_array($followers, $recipients, true)) {
			return Visibility::Followers;
		}

		return null;
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
		$objectType = RemoteObjectType::fromActivityType($embedded['type'] ?? null) ?? $target?->objectType ?? RemoteObjectType::Note;
		$metadata = $this->metadata($embedded, $objectType) ?? $target?->metadata;
		$audience = $target?->audience ?? $this->optionalAudience($actorId, $activity);

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
			objectType: $objectType,
			metadata: $metadata,
			quotedObjectId: $this->quotedObjectId($embedded) ?? $target?->quotedObjectId,
			inReplyToId: $this->referenceId($embedded['inReplyTo'] ?? null) ?? $target?->inReplyToId,
			audience: $audience,
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
	 * The updated version of a stored post.
	 *
	 * The embedded object must be the stored one and written by the same
	 * actor; the timestamp is the peer's version marker, used to refuse an
	 * older update after a newer one has been applied.
	 *
	 * @param array<string, mixed> $activity
	 */
	public function fromUpdate(RemotePost $target, array $activity): RemotePost
	{
		$updated = $this->updatedObject($target->actorId, $activity);

		if ($updated['objectId'] !== $target->objectId) {
			throw new InboxRejectedException(
				'invalid_activity',
				'The updated object does not match the stored post.',
			);
		}

		return new RemotePost(
			activityId: $target->activityId,
			objectId: $target->objectId,
			actorId: $target->actorId,
			kind: $target->kind,
			content: $updated['content'],
			contentWarning: $updated['contentWarning'],
			publishedAt: $target->publishedAt,
			objectAuthorId: $target->objectAuthorId,
			deletedAt: $target->deletedAt,
			updatedAt: $updated['updatedAt'],
			objectType: $target->objectType,
			metadata: $target->metadata,
			quotedObjectId: $target->quotedObjectId,
			inReplyToId: $target->inReplyToId,
			audience: $target->audience,
		);
	}

	/**
	 * The same validated edit, held until its post arrives.
	 *
	 * @param array<string, mixed> $activity
	 */
	public function pendingUpdate(string $actorId, array $activity, DateTimeImmutable $receivedAt): PendingRemoteUpdate
	{
		$updated = $this->updatedObject($actorId, $activity);
		$activityId = $activity['id'] ?? null;

		return new PendingRemoteUpdate(
			objectId: $updated['objectId'],
			actorId: $actorId,
			activityId: is_string($activityId) ? $activityId : $updated['objectId'],
			content: $updated['content'],
			contentWarning: $updated['contentWarning'],
			updatedAt: $updated['updatedAt'],
			receivedAt: $receivedAt,
		);
	}

	/**
	 * @param array<string, mixed> $activity
	 *
	 * @return array{objectId: string, content: string, contentWarning: ?string, updatedAt: DateTimeImmutable}
	 */
	private function updatedObject(string $actorId, array $activity): array
	{
		$object = $activity['object'] ?? null;
		if (!is_array($object)) {
			throw new InboxRejectedException('invalid_activity', 'An Update needs an embedded object.');
		}

		$objectAuthor = $this->objectAuthor($object, $actorId);
		if ($objectAuthor !== $actorId) {
			throw new InboxRejectedException(
				'actor_mismatch',
				'The updated object belongs to another actor.',
			);
		}

		$content = $object['content'] ?? null;
		if (!is_string($content) || trim($content) === '') {
			throw new InboxRejectedException('invalid_activity', 'An updated Note needs string content.');
		}

		if (mb_strlen($content) > PendingRemoteUpdate::MAX_CONTENT_LENGTH) {
			throw new InboxRejectedException('invalid_activity', 'The updated content is too long.');
		}

		return [
			'objectId' => $this->objectId($object, 'Update'),
			'content' => $content,
			'contentWarning' => $this->contentWarning($object),
			'updatedAt' => $this->updatedAt($object, $activity['published'] ?? null),
		];
	}

	/**
	 * The peer's version marker for an update.
	 *
	 * `updated` is the ActivityStreams property for a modified object. A peer
	 * that omits it still gets a monotonic value from its activity, and a
	 * malformed one falls back to the clock rather than rejecting an edit whose
	 * identity checks passed.
	 *
	 * @param array<string, mixed> $object
	 */
	private function updatedAt(array $object, mixed $activityPublished): DateTimeImmutable
	{
		$value = $object['updated'] ?? $activityPublished;
		if (is_string($value) && trim($value) !== '') {
			try {
				return Time::toSecondPrecision(new DateTimeImmutable(trim($value)));
			} catch (Exception) {
				// Fall through to the clock.
			}
		}

		return Time::toSecondPrecision($this->clock->now());
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
	 * The quoted object of a Note.
	 *
	 * FEP-044f sends `quote` (a string or an object with an id); older peers
	 * send only the legacy `quoteUrl`. The reference is stored as a target
	 * only: a foreign authorization is never trusted, and this instance does
	 * not render the quoted content from a peer's word alone.
	 *
	 * @param array<string, mixed> $object
	 */
	private function quotedObjectId(array $object): ?string
	{
		$value = $object['quote'] ?? ($object['quoteUrl'] ?? null);
		if ($value === null) {
			return null;
		}

		$id = is_array($value) ? ($value['id'] ?? ($value['href'] ?? null)) : $value;
		if (!is_string($id) || trim($id) === '') {
			return null;
		}

		$id = trim($id);
		if (mb_strlen($id) > RemotePost::MAX_ID_LENGTH) {
			throw new InboxRejectedException('invalid_activity', 'The quoted object id is too long.');
		}

		$scheme = parse_url($id, PHP_URL_SCHEME);
		if ($scheme !== 'http' && $scheme !== 'https') {
			throw new InboxRejectedException('invalid_activity', 'The quoted object must be an http(s) URL.');
		}

		return $id;
	}

	private function referenceId(mixed $value): ?string
	{
		if ($value === null || $value === '') {
			return null;
		}

		$id = is_array($value) ? ($value['id'] ?? $value['@id'] ?? $value['href'] ?? null) : $value;
		if (!is_string($id) || trim($id) === '' || mb_strlen(trim($id)) > RemotePost::MAX_ID_LENGTH) {
			throw new InboxRejectedException('invalid_activity', 'The reply target must have a valid id.');
		}

		$id = trim($id);
		$scheme = parse_url($id, PHP_URL_SCHEME);
		if (!is_string($scheme) || !in_array(strtolower($scheme), ['http', 'https'], true)) {
			throw new InboxRejectedException('invalid_activity', 'The reply target must be an http(s) URL.');
		}

		return $id;
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

	/**
	 * @param array<string, mixed> $object
	 */
	private function metadata(array $object, RemoteObjectType $type): ?RemoteObjectMetadata
	{
		$title = $this->title($object);
		$event = $type === RemoteObjectType::Event ? $this->event($object, $title) : null;
		$media = $type->isMedia() ? $this->media($object) : null;

		if ($title === null && $event === null && $media === null) {
			return null;
		}

		return new RemoteObjectMetadata($title, $event, $media);
	}

	/**
	 * A title is plain text. Peers send HTML in `name`, and a card must not
	 * turn that into markup, so the tags are stripped before storage.
	 *
	 * @param array<string, mixed> $object
	 */
	private function title(array $object): ?string
	{
		$name = $object['name'] ?? null;
		if ($name === null) {
			return null;
		}

		if (!is_string($name)) {
			throw new InboxRejectedException('invalid_activity', 'A name must be a string.');
		}

		$title = trim(html_entity_decode(strip_tags($name), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
		if ($title === '') {
			return null;
		}

		if (mb_strlen($title) > RemoteObjectMetadata::MAX_TITLE_LENGTH) {
			throw new InboxRejectedException('invalid_activity', 'The title is too long.');
		}

		return $title;
	}

	/**
	 * @param array<string, mixed> $object
	 */
	private function event(array $object, ?string $title): RemoteEventMetadata
	{
		$name = $title ?? $this->title($object);
		if ($name === null) {
			throw new InboxRejectedException('invalid_activity', 'An event needs a name.');
		}

		$startsAt = $this->eventTime($object['startTime'] ?? null);
		if ($startsAt === null) {
			throw new InboxRejectedException('invalid_activity', 'An event needs a valid startTime.');
		}

		try {
			return new RemoteEventMetadata(
				name: $name,
				startsAt: $startsAt,
				endsAt: $this->eventTime($object['endTime'] ?? null),
				location: $this->location($object['location'] ?? null),
				participationUrl: $this->url($object['externalParticipationUrl'] ?? null, 'externalParticipationUrl'),
				status: RemoteEventStatus::fromActivityValue($object['eventStatus'] ?? null),
			);
		} catch (\InvalidArgumentException $exception) {
			throw new InboxRejectedException('invalid_activity', $exception->getMessage());
		}
	}

	private function eventTime(mixed $value): ?DateTimeImmutable
	{
		if ($value === null) {
			return null;
		}

		if (!is_string($value) || trim($value) === '') {
			throw new InboxRejectedException('invalid_activity', 'An event timestamp must be a string.');
		}

		$value = trim($value);
		if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value) !== 1) {
			throw new InboxRejectedException('invalid_activity', 'An event timestamp is not RFC 3339.');
		}

		try {
			return Time::toSecondPrecision(new DateTimeImmutable($value));
		} catch (Exception) {
			throw new InboxRejectedException('invalid_activity', 'An event timestamp is invalid.');
		}
	}

	private function location(mixed $value): ?string
	{
		if ($value === null) {
			return null;
		}

		$name = is_array($value) ? ($value['name'] ?? null) : $value;
		if ($name === null) {
			return null;
		}

		if (!is_string($name)) {
			throw new InboxRejectedException('invalid_activity', 'An event location must be a string or a place name.');
		}

		$location = trim(html_entity_decode(strip_tags($name), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
		if ($location === '') {
			return null;
		}

		if (mb_strlen($location) > RemoteEventMetadata::MAX_LOCATION_LENGTH) {
			throw new InboxRejectedException('invalid_activity', 'The event location is too long.');
		}

		return $location;
	}

	/**
	 * @param array<string, mixed> $object
	 */
	private function media(array $object): ?RemoteMediaMetadata
	{
		$duration = $this->durationSeconds($object['duration'] ?? null);
		$thumbnail = $this->url($object['icon'] ?? ($object['image'] ?? null), 'icon');

		$sources = [];
		$rawSources = $object['url'] ?? null;
		if (is_string($rawSources) || (is_array($rawSources) && !array_is_list($rawSources))) {
			$rawSources = [$rawSources];
		}

		if (is_array($rawSources)) {
			foreach ($rawSources as $index => $source) {
				$url = $this->url($source, 'url');
				if ($url === null) {
					continue;
				}

				$label = is_array($source) && is_string($source['mediaType'] ?? null) && trim($source['mediaType']) !== ''
					? trim($source['mediaType'])
					: 'source' . $index;
				$sources[$label] = $url;

				if (count($sources) >= RemoteMediaMetadata::MAX_SOURCES) {
					break;
				}
			}
		}

		if ($duration === null && $thumbnail === null && $sources === []) {
			return null;
		}

		try {
			return new RemoteMediaMetadata($duration, $thumbnail, $sources);
		} catch (\InvalidArgumentException $exception) {
			throw new InboxRejectedException('invalid_activity', $exception->getMessage());
		}
	}

	private function durationSeconds(mixed $value): ?int
	{
		if (!is_string($value) || preg_match('/^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/D', trim($value), $matches) !== 1) {
			return null;
		}

		$days = isset($matches[1]) && $matches[1] !== '' ? (int) $matches[1] : 0;
		$hours = isset($matches[2]) && $matches[2] !== '' ? (int) $matches[2] : 0;
		$minutes = isset($matches[3]) && $matches[3] !== '' ? (int) $matches[3] : 0;
		$seconds = isset($matches[4]) && $matches[4] !== '' ? (int) $matches[4] : 0;
		$total = $days * 86_400 + $hours * 3_600 + $minutes * 60 + $seconds;

		if ($total < 1 || $total > RemoteMediaMetadata::MAX_DURATION_SECONDS) {
			return null;
		}

		return $total;
	}

	/**
	 * A URL from a string or an ActivityStreams Link object. Only absolute
	 * http(s) URLs are accepted; a peer cannot smuggle a `javascript:` link
	 * into a card or a participation button.
	 */
	private function url(mixed $value, string $field): ?string
	{
		if ($value === null) {
			return null;
		}

		$url = is_array($value) ? ($value['href'] ?? ($value['url'] ?? null)) : $value;
		if ($url === null) {
			return null;
		}

		if (!is_string($url)) {
			throw new InboxRejectedException('invalid_activity', 'The ' . $field . ' must be a URL string.');
		}

		$url = trim($url);
		if ($url === '') {
			return null;
		}

		if (mb_strlen($url) > RemoteMediaMetadata::MAX_URL_LENGTH) {
			throw new InboxRejectedException('invalid_activity', 'The ' . $field . ' is too long.');
		}

		$scheme = parse_url($url, PHP_URL_SCHEME);
		$host = parse_url($url, PHP_URL_HOST);
		if (!is_string($scheme) || !in_array(strtolower($scheme), ['http', 'https'], true)
			|| !is_string($host) || $host === ''
		) {
			throw new InboxRejectedException('invalid_activity', 'The ' . $field . ' must be an absolute http(s) URL.');
		}

		return $url;
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
