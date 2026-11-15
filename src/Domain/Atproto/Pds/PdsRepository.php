<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto\Pds;

/**
 * Persistence boundary for the native PDS.
 *
 * Writes that change the commit head are atomic repository operations: a
 * commit, its new blocks, the affected record and the identity head either all
 * become visible together or none do.
 */
interface PdsRepository
{
	public function identityForUser(string $localUserId): ?PdsIdentity;

	public function identityByDid(string $did): ?PdsIdentity;

	public function identityByHandle(string $handle): ?PdsIdentity;

	/**
	 * @return list<PdsKey>
	 */
	public function keys(string $did): array;

	public function key(string $did, string $keyId): ?PdsKey;

	/**
	 * @param array<string, string> $blocks CID string to block bytes
	 */
	public function createIdentity(
		PdsIdentity $identity,
		PdsKey $signingKey,
		PdsKey $rotationKey,
		PdsPlcOperation $operation,
		PdsCommit $commit,
		array $blocks,
	): void;

	/**
	 * @param list<string> $retiredKeyIds
	 */
	public function rotateIdentity(
		PdsIdentity $identity,
		PdsKey $signingKey,
		PdsKey $rotationKey,
		array $retiredKeyIds,
		PdsPlcOperation $operation,
	): void;

	/**
	 * @return array<string, PdsRecord> keyed by collection/rkey
	 */
	public function records(string $did): array;

	public function record(string $did, string $collection, string $rkey): ?PdsRecord;

	/**
	 * @param array<string, string> $blocks CID string to block bytes
	 * @param array{0: string, 1: string}|null $delete collection and record key
	 */
	public function applyCommit(
		PdsIdentity $identity,
		PdsCommit $commit,
		array $blocks,
		?PdsRecord $put,
		?array $delete,
	): void;

	/**
	 * @return list<PdsCommit>
	 */
	public function commits(string $did): array;

	/**
	 * @return array<string, string> CID string to block bytes
	 */
	public function blocks(string $did): array;

	/**
	 * @return list<PdsPlcOperation>
	 */
	public function plcOperations(string $did): array;
}
