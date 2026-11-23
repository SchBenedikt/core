<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Interaction;

use DateTimeImmutable;
use InvalidArgumentException;

use function hash;
use function implode;
use function mb_strlen;
use function parse_url;
use function substr;

/**
 * One federated reaction: a remote actor on a local post, or a local account
 * on a remote object.
 *
 * The identity is `(direction, subject, object, kind, emoji)`, not the
 * activity id. A peer that re-sends the same Like with a new id therefore
 * still cannot create a second reaction, and a local Undo removes it by the
 * same identity instead of trusting an id the peer controls. The activity id
 * is stored separately so an `Undo` that only embeds an id can still be
 * resolved.
 *
 * The id is derived from the identity (like the notification id), so an
 * insert is idempotent at the primary key and two concurrent replays race on
 * the same row instead of creating two counts.
 */
final readonly class Reaction
{
	public const MAX_SUBJECT_LENGTH = 255;

	public const MAX_OBJECT_LENGTH = 512;

	public const MAX_POST_LENGTH = 64;

	public const MAX_EMOJI_LENGTH = 64;

	public const MAX_ICON_LENGTH = 512;

	public function __construct(
		public ReactionDirection $direction,
		public string $subjectId,
		public string $objectId,
		public ?string $localPostId,
		public ReactionKind $kind,
		public ?string $emoji,
		public ?string $iconUrl,
		public string $activityId,
		public DateTimeImmutable $createdAt,
	) {
		if ($subjectId === '' || mb_strlen($subjectId) > self::MAX_SUBJECT_LENGTH) {
			throw new InvalidArgumentException('A reaction needs a subject of at most 255 characters.');
		}

		if ($objectId === '' || mb_strlen($objectId) > self::MAX_OBJECT_LENGTH) {
			throw new InvalidArgumentException('A reaction needs an object id of at most 512 characters.');
		}

		if ($activityId === '' || mb_strlen($activityId) > self::MAX_OBJECT_LENGTH) {
			throw new InvalidArgumentException('A reaction needs an activity id of at most 512 characters.');
		}

		if ($direction === ReactionDirection::In) {
			if ($localPostId === null || $localPostId === '' || mb_strlen($localPostId) > self::MAX_POST_LENGTH) {
				throw new InvalidArgumentException('An inbound reaction needs the local post id it was authorized for.');
			}
		} elseif ($localPostId !== null) {
			// An outbound reaction targets a remote object; attaching a local
			// post id would let it leak into that post's local counts.
			throw new InvalidArgumentException('An outbound reaction does not belong to a local post id.');
		}

		if ($kind === ReactionKind::Emoji) {
			if ($emoji === null || $emoji === '' || mb_strlen($emoji) > self::MAX_EMOJI_LENGTH) {
				throw new InvalidArgumentException('An emoji reaction needs an emoji of at most 64 characters.');
			}
		} elseif ($emoji !== null) {
			throw new InvalidArgumentException('A like or boost cannot carry an emoji; use an emoji reaction.');
		}

		if ($iconUrl !== null && ($kind !== ReactionKind::Emoji || mb_strlen($iconUrl) > self::MAX_ICON_LENGTH)) {
			throw new InvalidArgumentException('A reaction icon belongs to a custom emoji and is at most 512 characters.');
		}
	}

	public function id(): string
	{
		return self::idFor($this->direction, $this->subjectId, $this->objectId, $this->kind, $this->emoji);
	}

	/**
	 * The deterministic row id of an identity, usable without a full instance.
	 */
	public static function idFor(
		ReactionDirection $direction,
		string $subjectId,
		string $objectId,
		ReactionKind $kind,
		?string $emoji,
	): string {
		return 'reaction-' . substr(hash(
			'sha256',
			implode("\0", [$direction->value, $subjectId, $objectId, $kind->value, $emoji ?? '']),
		), 0, 32);
	}

	public function emojiKey(): string
	{
		return $this->emoji ?? '';
	}

	/**
	 * Whether the stored icon is something a trusted surface may show.
	 *
	 * The core never proxies or inlines a remote icon; a host that wants to
	 * render a custom emoji has to apply its own media policy. Storing only an
	 * https URL keeps a plain-http or data: value out of the database.
	 */
	public function hasSafeIcon(): bool
	{
		if ($this->iconUrl === null) {
			return false;
		}

		$parts = parse_url($this->iconUrl);

		return $parts !== false
			&& ($parts['scheme'] ?? null) === 'https'
			&& ($parts['host'] ?? '') !== ''
			&& !isset($parts['user'])
			&& !isset($parts['pass']);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'id' => $this->id(),
			'direction' => $this->direction->value,
			'subject_id' => $this->subjectId,
			'object_id' => $this->objectId,
			'local_post_id' => $this->localPostId,
			'kind' => $this->kind->value,
			'emoji' => $this->emoji,
			'icon_url' => $this->iconUrl,
			'activity_id' => $this->activityId,
			'created_at' => $this->createdAt->format('Y-m-d\TH:i:s\Z'),
		];
	}
}
