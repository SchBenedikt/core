<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Federation;

use AlohaSocial\Core\Domain\Federation\LocalActorDirectory;
use AlohaSocial\Core\Domain\Federation\UnknownLocalActorException;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;
use AlohaSocial\Core\Protocol\ActivityPub\OrderedCollection;
use AlohaSocial\Core\Protocol\ActivityPub\RsaKeyPair;
use AlohaSocial\Core\Service\FollowerService;
use AlohaSocial\Core\Service\FollowingService;
use AlohaSocial\Core\Service\LocalRelationshipService;

use function array_merge;
use function array_slice;
use function count;

/**
 * The public description of a local account, its followers and following.
 *
 * Every id is derived from the same `ActivityFactory` the outbound side uses,
 * so the actor id here is exactly the actor id a signed request is checked
 * against, and the key id here is exactly the key id the inbox resolves.
 */
final class ActorDocumentService
{
	/** The ActivityStreams security vocabulary that defines `publicKey`. */
	public const CONTEXT_SECURITY = 'https://w3id.org/security/v1';

	public function __construct(
		private readonly ActivityFactory $activities,
		private readonly RsaKeyPair $keys,
		private readonly LocalActorDirectory $actors,
		private readonly FollowerService $followers,
		private readonly FollowingService $following,
		private readonly ?LocalRelationshipService $relationships = null,
	) {
	}

	/**
	 * @return array<string, mixed>
	 *
	 * @throws UnknownLocalActorException when the handle is not an account here
	 */
	public function document(string $handle): array
	{
		$userId = $this->knownUser($handle);
		$actorId = $this->activities->actorId($userId);

		return [
			'@context' => [ActivityFactory::CONTEXT, self::CONTEXT_SECURITY],
			'id' => $actorId,
			'type' => 'Person',
			'preferredUsername' => $userId,
			'name' => $userId,
			// One inbox endpoint serves all local actors; the activity's
			// `object` names the account it is addressed to. The shared inbox
			// is advertised separately so peers can batch server-wide delivery.
			'inbox' => $this->activities->baseUrl() . '/inbox',
			'endpoints' => [
				'sharedInbox' => $this->activities->sharedInboxId(),
			],
			'outbox' => $this->activities->outboxId($userId),
			'followers' => $this->activities->followersId($userId),
			'following' => $this->activities->followingId($userId),
			'publicKey' => [
				'id' => $this->activities->keyId($userId),
				'owner' => $actorId,
				'publicKeyPem' => $this->keys->publicKeyPem(),
			],
		];
	}

	/**
	 * The root document when `$page` is null, otherwise one page of it.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws UnknownLocalActorException when the handle is not an account here
	 */
	public function followers(
		string $handle,
		?int $page = null,
		int $limit = OrderedCollection::DEFAULT_LIMIT,
	): array {
		$userId = $this->knownUser($handle);
		$limit = OrderedCollection::clampLimit($limit);
		$id = $this->activities->followersId($userId);
		$total = $this->followers->count($userId);

		if ($page === null) {
			return OrderedCollection::root($id, $total, $limit);
		}

		$actors = $this->followers->actorIdsPage(
			$userId,
			$limit,
			OrderedCollection::offset($page, $limit),
		);

		return OrderedCollection::page($id, $page, $limit, $total, $actors);
	}

	/**
	 * The root document when `$page` is null, otherwise one page of it.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws UnknownLocalActorException when the handle is not an account here
	 */
	public function following(
		string $handle,
		?int $page = null,
		int $limit = OrderedCollection::DEFAULT_LIMIT,
	): array {
		$userId = $this->knownUser($handle);
		$limit = OrderedCollection::clampLimit($limit);
		$id = $this->activities->followingId($userId);
		$local = $this->relationships?->localFollowingActorIds($userId) ?? [];
		$total = $this->following->count($userId) + count($local);

		if ($page === null) {
			return OrderedCollection::root($id, $total, $limit);
		}

		if ($local === []) {
			$actors = $this->following->actorIdsPage(
				$userId,
				$limit,
				OrderedCollection::offset($page, $limit),
			);
		} else {
			// Local relationships and remote follows live in different
			// directions of the graph. Combining the stable lists by offset
			// keeps every item reachable without adding a second table.
			$actors = array_slice(
				array_merge($this->following->actorIds($userId), $local),
				OrderedCollection::offset($page, $limit),
				$limit,
			);
		}

		return OrderedCollection::page($id, $page, $limit, $total, $actors);
	}

	/**
	 * @throws UnknownLocalActorException
	 */
	private function knownUser(string $handle): string
	{
		$handle = trim($handle);

		if ($handle === '' || !$this->actors->has($handle)) {
			throw new UnknownLocalActorException($handle);
		}

		return $handle;
	}
}
