<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto\Pds;

/**
 * Ephemeral implementation used by inMemory() and focused service tests.
 */
final class InMemoryPdsRepository implements PdsRepository
{
	/** @var array<string, PdsIdentity> */
	private array $identities = [];

	/** @var array<string, PdsKey> */
	private array $keys = [];

	/** @var array<string, array<string, PdsRecord>> */
	private array $records = [];

	/** @var array<string, list<PdsCommit>> */
	private array $commits = [];

	/** @var array<string, array<string, string>> */
	private array $blocks = [];

	/** @var array<string, list<PdsPlcOperation>> */
	private array $operations = [];

	/** @var array<string, array<string, PdsBlob>> */
	private array $blobs = [];

	/** @var list<PdsEvent> */
	private array $events = [];

	public function identityForUser(string $localUserId): ?PdsIdentity
	{
		foreach ($this->identities as $identity) {
			if ($identity->localUserId === $localUserId) {
				return $identity;
			}
		}

		return null;
	}

	public function identityByDid(string $did): ?PdsIdentity
	{
		return $this->identities[$did] ?? null;
	}

	public function identityByHandle(string $handle): ?PdsIdentity
	{
		foreach ($this->identities as $identity) {
			if ($identity->handle === $handle) {
				return $identity;
			}
		}

		return null;
	}

	public function keys(string $did): array
	{
		$keys = [];
		foreach ($this->keys as $key) {
			if ($key->did === $did) {
				$keys[] = $key;
			}
		}

		return $keys;
	}

	public function key(string $did, string $keyId): ?PdsKey
	{
		$key = $this->keys[$keyId] ?? null;

		return $key !== null && $key->did === $did ? $key : null;
	}

	public function createIdentity(
		PdsIdentity $identity,
		PdsKey $signingKey,
		PdsKey $rotationKey,
		PdsPlcOperation $operation,
		PdsCommit $commit,
		array $blocks,
	): void {
		$this->identities[$identity->did] = $identity;
		$this->keys[$signingKey->id] = $signingKey;
		$this->keys[$rotationKey->id] = $rotationKey;
		$this->records[$identity->did] = [];
		$this->commits[$identity->did] = [$commit];
		$this->blocks[$identity->did] = $blocks;
		$this->operations[$identity->did] = [$operation];
	}

	public function rotateIdentity(
		PdsIdentity $identity,
		PdsKey $signingKey,
		PdsKey $rotationKey,
		array $retiredKeyIds,
		PdsPlcOperation $operation,
	): void {
		$this->identities[$identity->did] = $identity;
		$this->keys[$signingKey->id] = $signingKey;
		$this->keys[$rotationKey->id] = $rotationKey;

		foreach ($retiredKeyIds as $keyId) {
			$key = $this->keys[$keyId] ?? null;
			if ($key !== null) {
				$this->keys[$keyId] = new PdsKey(
					$key->id,
					$key->localUserId,
					$key->did,
					$key->role,
					$key->sealedPrivateKey,
					$key->publicKeyPem,
					$key->publicKeyMultibase,
					false,
					$key->createdAt,
					$identity->updatedAt,
				);
			}
		}

		$this->operations[$identity->did][] = $operation;
	}

	public function records(string $did): array
	{
		return $this->records[$did] ?? [];
	}

	public function record(string $did, string $collection, string $rkey): ?PdsRecord
	{
		return $this->records[$did][$collection . '/' . $rkey] ?? null;
	}

	public function applyCommit(
		PdsIdentity $identity,
		PdsCommit $commit,
		array $blocks,
		array $puts,
		array $deletes,
		?PdsEvent $event = null,
	): ?PdsEvent {
		$this->identities[$identity->did] = $identity;
		$this->commits[$identity->did][] = $commit;
		foreach ($blocks as $cid => $bytes) {
			$this->blocks[$identity->did][$cid] = $bytes;
		}

		foreach ($deletes as $delete) {
			unset($this->records[$identity->did][$delete[0] . '/' . $delete[1]]);
		}

		foreach ($puts as $put) {
			$this->records[$identity->did][$put->collection . '/' . $put->rkey] = $put;
		}

		return $event === null ? null : $this->appendEvent($event);
	}

	public function appendEvent(PdsEvent $event): PdsEvent
	{
		$stored = $event->withSeq($this->latestSeq() + 1);
		$this->events[] = $stored;

		return $stored;
	}

	public function eventsAfter(int $cursor, int $limit): array
	{
		$events = [];
		foreach ($this->events as $event) {
			if ($event->seq <= $cursor) {
				continue;
			}

			$events[] = $event;
			if (count($events) >= $limit) {
				break;
			}
		}

		return $events;
	}

	public function latestSeq(): int
	{
		return $this->events === [] ? 0 : $this->events[array_key_last($this->events)]->seq;
	}

	public function identities(): array
	{
		return array_values($this->identities);
	}

	public function blobs(string $did): array
	{
		return $this->blobs[$did] ?? [];
	}

	public function blob(string $did, string $cid): ?PdsBlob
	{
		return $this->blobs[$did][$cid] ?? null;
	}

	public function putBlob(PdsBlob $blob): void
	{
		$this->blobs[$blob->did][$blob->cid] = $blob;
	}

	public function totalBlobBytes(string $did): int
	{
		$total = 0;
		foreach ($this->blobs[$did] ?? [] as $blob) {
			$total += $blob->size;
		}

		return $total;
	}

	public function commits(string $did): array
	{
		return $this->commits[$did] ?? [];
	}

	public function blocks(string $did): array
	{
		return $this->blocks[$did] ?? [];
	}

	public function plcOperations(string $did): array
	{
		return $this->operations[$did] ?? [];
	}
}
