<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Federation;

use AlohaSocial\Core\Domain\Federation\RemotePost;
use AlohaSocial\Core\Domain\Federation\RemotePostKind;
use AlohaSocial\Core\Platform\Events\StreamAudience;
use AlohaSocial\Core\Platform\Events\StreamEventDraft;
use AlohaSocial\Core\Support\Time;

/** Builds home-scoped events for newly imported remote statuses. */
final class RemoteStatusEventFactory
{
	private const PUBLIC_COLLECTION = 'https://www.w3.org/ns/activitystreams#Public';

	/**
	 * @param array<string, mixed> $activity
	 * @param list<string> $recipientIds
	 */
	public function forCreate(RemotePost $post, array $activity, array $recipientIds): ?StreamEventDraft
	{
		if ($post->kind !== RemotePostKind::Post || $recipientIds === []) {
			return null;
		}

		$object = $activity['object'] ?? null;
		if (!is_array($object)) {
			return null;
		}

		$to = $object['to'] ?? $activity['to'] ?? [];
		$cc = $object['cc'] ?? $activity['cc'] ?? [];
		$followerCollection = $post->actorId . '/followers';
		if (!$this->containsPublic($to)
			&& !$this->containsPublic($cc)
			&& !$this->containsAddress($to, $followerCollection)
			&& !$this->containsAddress($cc, $followerCollection)
		) {
			// Direct deliveries can share an inbox path with public posts. Do not
			// infer that every local follower may see a recipient-only message.
			return null;
		}

		return new StreamEventDraft(
			name: 'status.created',
			audience: StreamAudience::Followers,
			actorId: $post->actorId,
			recipientIds: array_values(array_unique($recipientIds)),
			payload: ['status_id' => $post->objectId],
			occurredAt: Time::format($post->publishedAt),
		);
	}

	private function containsPublic(mixed $value): bool
	{
		return $this->containsAddress($value, self::PUBLIC_COLLECTION);
	}

	private function containsAddress(mixed $value, string $address): bool
	{
		$addresses = is_string($value) ? [$value] : $value;
		if (!is_array($addresses)) {
			return false;
		}

		foreach ($addresses as $entry) {
			if ($entry === $address) {
				return true;
			}
		}

		return false;
	}
}
