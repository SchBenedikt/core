<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Federation;

use AlohaSocial\Core\Domain\Federation\FollowerRepository;
use AlohaSocial\Core\Domain\Federation\LocalActorProfileProvider;
use AlohaSocial\Core\Domain\Federation\UnknownLocalActorException;
use AlohaSocial\Core\Jobs\JobQueue;
use AlohaSocial\Core\Jobs\JobSubject;
use AlohaSocial\Core\Jobs\JobType;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;

use function array_filter;
use function array_unique;
use function array_values;
use function count;
use function is_string;
use function trim;

/**
 * Publishes a signed profile Update(Person) after the host changed an account.
 *
 * The host keeps the edit; this service only reads the new public projection
 * and fans the actor document out to accepted followers. The actor id and key
 * id are unchanged, so peers update the existing actor instead of creating a
 * second identity. No delivery is attempted when nobody follows the account.
 */
final class ActorProfileUpdateService
{
	public function __construct(
		private readonly ActorDocumentService $documents,
		private readonly FollowerRepository $followers,
		private readonly ActivityFactory $activities,
		private readonly JobQueue $queue,
		private readonly ?LocalActorProfileProvider $profiles = null,
	) {
	}

	/**
	 * Queues the Update and returns the number of delivery jobs.
	 *
	 * @throws UnknownLocalActorException when the handle is not a local account
	 */
	public function publish(string $localUserId): int
	{
		$localUserId = trim($localUserId);
		$profile = $this->profiles?->profile($localUserId);
		$at = $profile === null
			? Time::toSecondPrecision(new DateTimeImmutable())
			: $profile->updatedAt;

		$document = $this->documents->document($localUserId);
		$activity = $this->activities->updateActor($localUserId, $document, $at);
		$inboxes = array_values(array_unique(array_filter(
			$this->followers->inboxesFor($localUserId),
			static fn (mixed $inbox): bool => is_string($inbox) && trim($inbox) !== '',
		)));

		foreach ($inboxes as $inbox) {
			$this->queue->push(JobType::FEDERATION_DELIVER, [
				'inbox' => $inbox,
				'activity' => $activity,
				'key_id' => $this->activities->keyId($localUserId),
			], subject: JobSubject::account($localUserId));
		}

		return count($inboxes);
	}

	/**
	 * The exact document a host can inspect or test without delivering it.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws UnknownLocalActorException
	 */
	public function activity(string $localUserId): array
	{
		$profile = $this->profiles?->profile(trim($localUserId));
		$at = $profile === null
			? Time::toSecondPrecision(new DateTimeImmutable())
			: $profile->updatedAt;

		return $this->activities->updateActor(
			trim($localUserId),
			$this->documents->document(trim($localUserId)),
			$at,
		);
	}
}
