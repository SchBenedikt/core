<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Federation;

use AlohaSocial\Core\Domain\Federation\LocalActorDirectory;
use AlohaSocial\Core\Domain\Federation\LocalActorProfile;
use AlohaSocial\Core\Domain\Federation\LocalActorProfileField;
use AlohaSocial\Core\Domain\Federation\LocalActorProfileProvider;
use AlohaSocial\Core\Domain\Federation\UnknownLocalActorException;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;
use AlohaSocial\Core\Protocol\ActivityPub\OrderedCollection;
use AlohaSocial\Core\Protocol\ActivityPub\RsaKeyPair;
use AlohaSocial\Core\Service\AccountMoveService;
use AlohaSocial\Core\Service\FollowerService;
use AlohaSocial\Core\Service\FollowingService;
use AlohaSocial\Core\Service\LocalRelationshipService;
use AlohaSocial\Core\Support\Time;

use function array_merge;
use function array_slice;
use function count;
use function trim;

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
		private readonly ?LocalActorProfileProvider $profiles = null,
		private readonly ?AccountMoveService $moves = null,
	) {}

	/**
	 * @return array<string, mixed>
	 *
	 * @throws UnknownLocalActorException when the handle is not an account here
	 */
	public function document(string $handle): array
	{
		$userId = $this->knownUser($handle);
		$actorId = $this->activities->actorId($userId);
		$profile = $this->profiles?->profile($userId);

		$document = [
			'@context' => [ActivityFactory::CONTEXT, self::CONTEXT_SECURITY],
			'id' => $actorId,
			'type' => 'Person',
			'preferredUsername' => $userId,
			'name' => $profile?->displayName ?? $userId,
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

		$move = $this->moves?->findOutboundByOrigin($actorId);
		if ($move !== null) {
			// The destination is published in both fields: movedTo tells a
			// peer where to go, alsoKnownAs is the reciprocal proof the target
			// must carry before it accepts the Move.
			$document['movedTo'] = $move->targetActorId;
			$document['alsoKnownAs'] = [$move->targetActorId];
		}

		if ($profile === null) {
			return $document;
		}

		if ($profile->summary !== '') {
			$document['summary'] = $profile->summary;
		}

		if ($profile->avatarUrl !== null) {
			$document['icon'] = ['type' => 'Image', 'url' => $profile->avatarUrl];
		}

		if ($profile->headerUrl !== null) {
			$document['image'] = ['type' => 'Image', 'url' => $profile->headerUrl];
		}

		if ($profile->locked) {
			$document['manuallyApprovesFollowers'] = true;
		}

		$document['updated'] = Time::format($profile->updatedAt);

		$attachments = array_map(
			static fn (string $url): array => ['type' => 'Image', 'url' => $url],
			$profile->attachmentUrls,
		);
		foreach ($profile->fields as $field) {
			$attachments[] = $this->profileField($field);
		}
		if ($attachments !== []) {
			$document['attachment'] = $attachments;
		}

		return $document;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function profileField(LocalActorProfileField $field): array
	{
		$value = [
			'type' => 'PropertyValue',
			'name' => $field->name,
			'value' => $field->value,
		];

		// The host only sets this after checking the link. The core never
		// fetches an arbitrary URL to discover a rel=me claim itself.
		if ($field->verified) {
			$value['rel'] = ['me'];
		}

		return $value;
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
		?string $viewerId = null,
	): array {
		$userId = $this->knownUser($handle);
		$limit = OrderedCollection::clampLimit($limit);
		$id = $this->activities->followersId($userId);
		$total = $this->followers->count($userId);

		if ($page === null) {
			return OrderedCollection::root($id, $total, $limit);
		}

		if ($this->hiddenFrom($userId, $viewerId)) {
			return OrderedCollection::page($id, $page, $limit, $total, []);
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
		?string $viewerId = null,
	): array {
		$userId = $this->knownUser($handle);
		$limit = OrderedCollection::clampLimit($limit);
		$id = $this->activities->followingId($userId);
		$local = $this->relationships?->localFollowingActorIds($userId) ?? [];
		$total = $this->following->count($userId) + count($local);

		if ($page === null) {
			return OrderedCollection::root($id, $total, $limit);
		}

		if ($this->hiddenFrom($userId, $viewerId)) {
			return OrderedCollection::page($id, $page, $limit, $total, []);
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
	 * A hidden collection keeps its root counts but refuses member pages to
	 * anyone except its owner.
	 */
	private function hiddenFrom(string $userId, ?string $viewerId): bool
	{
		return $viewerId !== $userId
			&& ($this->profiles?->profile($userId)?->hideCollections ?? false);
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
