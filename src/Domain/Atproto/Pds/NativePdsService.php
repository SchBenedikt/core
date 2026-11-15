<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto\Pds;

use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Config\Config;
use AlohaSocial\Core\Protocol\Atproto\Repository\Car;
use AlohaSocial\Core\Protocol\Atproto\Repository\Cid;
use AlohaSocial\Core\Protocol\Atproto\Repository\DagCbor;
use AlohaSocial\Core\Protocol\Atproto\Repository\MstBuilder;
use AlohaSocial\Core\Protocol\Atproto\Repository\RepoCommit;
use AlohaSocial\Core\Protocol\Atproto\Tid;
use AlohaSocial\Core\Security\SecretBox;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A small, native AT Protocol repository service.
 *
 * It owns the pieces a PDS must own: account identity, a signed commit per
 * write, an MST root that changes with every write, DID documents backed by
 * rotation keys, and CAR export for sync. The host still owns HTTP
 * authentication and account existence; this service only accepts a local
 * user id that the host has already authenticated.
 */
final class NativePdsService
{
	private const MAX_RECORDS = 10_000;

	public function __construct(
		private readonly Config $config,
		private readonly PdsRepository $repository,
		private readonly SecretBox $secrets,
		private readonly Clock $clock,
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function createIdentity(string $localUserId, ?string $handle = null): array
	{
		$localUserId = trim($localUserId);
		if ($localUserId === '') {
			throw PdsError::invalid('An authenticated local user is required.');
		}

		$existing = $this->repository->identityForUser($localUserId);
		if ($existing !== null) {
			return $this->identityView($existing);
		}

		$handle = strtolower(trim((string) $handle));
		if ($handle === '') {
			$handle = PdsHandle::defaultFor($localUserId, PdsConfig::handleDomain($this->config));
		}

		try {
			PdsHandle::assertValid($handle);
		} catch (InvalidArgumentException $e) {
			throw PdsError::invalid($e->getMessage());
		}

		if ($this->repository->identityByHandle($handle) !== null) {
			throw PdsError::invalid('That handle is already in use.');
		}

		$now = $this->clock->now();
		$createdAt = Time::format($now);
		$did = PdsDid::generate($localUserId . '|' . $handle . '|' . bin2hex(random_bytes(16)));

		$signingMaterial = PdsKeyGenerator::generate();
		$rotationMaterial = PdsKeyGenerator::generate();
		$signing = $this->makeKey($localUserId, $did, PdsKey::ROLE_SIGNING, $signingMaterial, $createdAt);
		$rotation = $this->makeKey($localUserId, $did, PdsKey::ROLE_ROTATION, $rotationMaterial, $createdAt);

		$identity = new PdsIdentity(
			$localUserId,
			$did,
			$handle,
			$signing->id,
			$rotation->id,
			null,
			null,
			0,
			$createdAt,
			$createdAt,
		);

		$pdsUrl = $this->pdsUrl();
		$genesis = PdsPlc::operation($identity, $signing, $rotation, $pdsUrl, null, $rotationMaterial['private']);
		$operation = new PdsPlcOperation($did, $genesis['cid'], 1, $genesis['bytes'], $createdAt);

		$empty = (new MstBuilder())->build([]);
		$rev = $this->nextRev(null, $now);
		$commit = RepoCommit::sign($did, $rev, null, $empty['root'], $signingMaterial['private']);
		$blocks = $empty['blocks'];
		$blocks[$commit->cid()->toString()] = $commit->bytes();

		$storedCommit = new PdsCommit(
			$did,
			$commit->cid(),
			1,
			$rev,
			$empty['root'],
			null,
			$signing->id,
			$commit->bytes(),
			$createdAt,
		);
		$identity = $identity->withHead($commit->cid(), $rev, 1, $createdAt);

		$this->repository->createIdentity($identity, $signing, $rotation, $operation, $storedCommit, $blocks);

		return $this->identityView($identity);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function resolveDid(string $did): array
	{
		$identity = $this->repository->identityByDid(trim($did));
		if ($identity === null) {
			throw PdsError::repoNotFound('The DID is not hosted by this PDS.');
		}

		return $this->didDocument($identity);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function rotateKeys(string $localUserId): array
	{
		$identity = $this->requireIdentityForUser($localUserId);
		$oldSigning = $this->key($identity, $identity->signingKeyId);
		$oldRotation = $this->key($identity, $identity->rotationKeyId);

		$now = $this->clock->now();
		$timestamp = Time::format($now);
		$signingMaterial = PdsKeyGenerator::generate();
		$rotationMaterial = PdsKeyGenerator::generate();
		$signing = $this->makeKey($identity->localUserId, $identity->did, PdsKey::ROLE_SIGNING, $signingMaterial, $timestamp);
		$rotation = $this->makeKey($identity->localUserId, $identity->did, PdsKey::ROLE_ROTATION, $rotationMaterial, $timestamp);

		$rotated = new PdsIdentity(
			$identity->localUserId,
			$identity->did,
			$identity->handle,
			$signing->id,
			$rotation->id,
			$identity->head,
			$identity->headRev,
			$identity->seq,
			$identity->createdAt,
			$timestamp,
		);

		$operations = $this->repository->plcOperations($identity->did);
		$previous = $operations === [] ? null : $operations[array_key_last($operations)]->cid;
		$rotationPrivate = $this->secrets->open($oldRotation->sealedPrivateKey);
		$generated = PdsPlc::operation(
			$rotated,
			$signing,
			$rotation,
			$this->pdsUrl(),
			$previous,
			$rotationPrivate,
		);
		$operation = new PdsPlcOperation(
			$identity->did,
			$generated['cid'],
			count($operations) + 1,
			$generated['bytes'],
			$timestamp,
		);

		$this->repository->rotateIdentity(
			$rotated,
			$signing,
			$rotation,
			[$oldSigning->id, $oldRotation->id],
			$operation,
		);

		return $this->identityView($rotated);
	}

	/**
	 * @param array<string, mixed> $input
	 *
	 * @return array<string, mixed>
	 */
	public function createRecord(string $localUserId, array $input): array
	{
		$identity = $this->requireIdentityForUser($localUserId);
		$this->assertRepo($identity, $input['repo'] ?? null);

		$collection = $input['collection'] ?? null;
		if (!is_string($collection) || preg_match('/^[a-z][a-zA-Z0-9]*(\.[a-z][a-zA-Z0-9]*)+$/', $collection) !== 1) {
			throw PdsError::invalid('collection must be a valid NSID.');
		}

		$recordInput = $input['record'] ?? null;
		if (!is_array($recordInput) || array_is_list($recordInput)) {
			throw PdsError::invalid('record must be a JSON object.');
		}

		if (isset($recordInput['$type']) && (!is_string($recordInput['$type']) || $recordInput['$type'] === '')) {
			throw PdsError::invalid('record.$type must be a string when present.');
		}
		if (!isset($recordInput['$type'])) {
			$recordInput['$type'] = $collection;
		}
		if ($recordInput['$type'] !== $collection) {
			throw PdsError::invalid('record.$type must equal collection.');
		}

		$rkey = $input['rkey'] ?? null;
		if ($rkey === null) {
			$rkey = $this->newRkey($identity->did, $collection, $this->clock->now());
		}
		if (!is_string($rkey) || preg_match('/^[a-zA-Z0-9._~:-]{1,512}$/', $rkey) !== 1) {
			throw PdsError::invalid('rkey contains characters outside the AT Protocol record key grammar.');
		}

		if ($this->repository->record($identity->did, $collection, $rkey) !== null) {
			throw PdsError::recordExists();
		}

		if (count($this->repository->records($identity->did)) >= self::MAX_RECORDS) {
			throw PdsError::invalid('The repository has reached the local record limit.');
		}

		$now = $this->clock->now();
		$timestamp = Time::format($now);
		$cbor = DagCbor::encode($recordInput);
		$cid = Cid::forData($cbor);
		$record = new PdsRecord($identity->did, $collection, $rkey, $cid, $cbor, $timestamp, $timestamp);

		$records = $this->repository->records($identity->did);
		$records[$collection . '/' . $rkey] = $record;
		$values = [];
		foreach ($records as $key => $stored) {
			$values[$key] = $stored->cid;
		}

		$mst = (new MstBuilder())->build($values);
		$signing = $this->key($identity, $identity->signingKeyId);
		$seq = $identity->seq + 1;
		$rev = $this->nextRev($identity->headRev, $now);
		$commit = RepoCommit::sign(
			$identity->did,
			$rev,
			$identity->head,
			$mst['root'],
			$this->secrets->open($signing->sealedPrivateKey),
		);

		$blocks = $mst['blocks'];
		$blocks[$cid->toString()] = $cbor;
		$blocks[$commit->cid()->toString()] = $commit->bytes();

		$updated = $identity->withHead($commit->cid(), $rev, $seq, $timestamp);
		$storedCommit = new PdsCommit(
			$identity->did,
			$commit->cid(),
			$seq,
			$rev,
			$mst['root'],
			$identity->head,
			$signing->id,
			$commit->bytes(),
			$timestamp,
		);

		$this->repository->applyCommit($updated, $storedCommit, $blocks, $record, null);

		return [
			'uri' => $record->uri(),
			'cid' => $cid->toString(),
			'commit' => ['cid' => $commit->cid()->toString(), 'rev' => $rev],
			'validationStatus' => 'valid',
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getRecord(string $repo, string $collection, string $rkey): array
	{
		$identity = $this->identityForRepo($repo);
		$record = $this->repository->record($identity->did, $collection, $rkey);
		if ($record === null) {
			throw PdsError::recordNotFound();
		}

		$value = DagCbor::decode($record->cbor);
		if (!is_array($value)) {
			throw PdsError::internal('The stored record is not a DAG-CBOR map.');
		}

		return [
			'uri' => $record->uri(),
			'cid' => $record->cid->toString(),
			'value' => $value,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function listRecords(
		string $repo,
		string $collection,
		int $limit = 50,
		?string $cursor = null,
		bool $reverse = false,
	): array {
		$identity = $this->identityForRepo($repo);
		$limit = max(1, min(100, $limit));
		$records = [];
		foreach ($this->repository->records($identity->did) as $record) {
			if ($record->collection === $collection) {
				$records[] = $record;
			}
		}

		usort(
			$records,
			static fn (PdsRecord $left, PdsRecord $right): int => strcmp($left->rkey, $right->rkey),
		);
		if ($reverse) {
			$records = array_reverse($records);
		}

		if ($cursor !== null && $cursor !== '') {
			$records = array_values(array_filter(
				$records,
				static fn (PdsRecord $record): bool => $reverse
					? strcmp($record->rkey, $cursor) < 0
					: strcmp($record->rkey, $cursor) > 0,
			));
		}

		$page = array_slice($records, 0, $limit);
		$hasMore = count($records) > $limit;
		$output = [];
		foreach ($page as $record) {
			$value = DagCbor::decode($record->cbor);
			if (!is_array($value)) {
				throw PdsError::internal('A stored record is not a DAG-CBOR map.');
			}

			$output[] = [
				'uri' => $record->uri(),
				'cid' => $record->cid->toString(),
				'value' => $value,
			];
		}

		$result = ['records' => $output];
		if ($hasMore && $page !== []) {
			$result['cursor'] = $page[array_key_last($page)]->rkey;
		}

		return $result;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function describeRepo(string $repo): array
	{
		$identity = $this->identityForRepo($repo);
		$collections = [];
		foreach ($this->repository->records($identity->did) as $record) {
			$collections[$record->collection] = true;
		}
		$collections = array_keys($collections);
		sort($collections);

		return [
			'did' => $identity->did,
			'handle' => $identity->handle,
			'didDoc' => $this->didDocument($identity),
			'collections' => $collections,
			'handleIsCorrect' => true,
		];
	}

	public function getRepo(string $repo): string
	{
		$identity = $this->identityForRepo($repo);
		if ($identity->head === null) {
			throw PdsError::repoNotFound('The repository has no commits.');
		}

		$blocks = $this->repository->blocks($identity->did);
		if (!isset($blocks[$identity->head->toString()])) {
			throw PdsError::internal('The repository head block is missing.');
		}

		return (new Car($identity->head, $blocks))->encode();
	}

	/**
	 * @return array<string, mixed>
	 */
	private function identityView(PdsIdentity $identity): array
	{
		$signing = $this->key($identity, $identity->signingKeyId);
		$rotation = $this->key($identity, $identity->rotationKeyId);

		return [
			'did' => $identity->did,
			'handle' => $identity->handle,
			'signingKey' => $signing->publicKeyMultibase,
			'rotationKey' => $rotation->publicKeyMultibase,
			'head' => $identity->head?->toString(),
			'rev' => $identity->headRev,
			'didDocument' => $this->didDocument($identity),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function didDocument(PdsIdentity $identity): array
	{
		return PdsDid::document(
			$identity,
			$this->key($identity, $identity->signingKeyId),
			$this->key($identity, $identity->rotationKeyId),
			$this->pdsUrl(),
		);
	}

	private function requireIdentityForUser(string $localUserId): PdsIdentity
	{
		$identity = $this->repository->identityForUser(trim($localUserId));
		if ($identity === null) {
			throw PdsError::repoNotFound('Create a DID before writing to a repository.');
		}

		return $identity;
	}

	private function identityForRepo(string $repo): PdsIdentity
	{
		$repo = trim($repo);
		if ($repo === '') {
			throw PdsError::invalid('repo is required.');
		}

		$identity = str_starts_with($repo, 'did:')
			? $this->repository->identityByDid($repo)
			: $this->repository->identityByHandle(strtolower($repo));

		if ($identity === null) {
			throw PdsError::repoNotFound();
		}

		return $identity;
	}

	private function assertRepo(PdsIdentity $identity, mixed $repo): void
	{
		if ($repo === null || $repo === '') {
			return;
		}

		if (!is_string($repo) || ($repo !== $identity->did && strtolower($repo) !== $identity->handle)) {
			throw PdsError::forbidden();
		}
	}

	private function key(PdsIdentity $identity, string $keyId): PdsKey
	{
		$key = $this->repository->key($identity->did, $keyId);
		if ($key === null || !$key->active) {
			throw PdsError::internal('The active PDS key is missing.');
		}

		return $key;
	}

	/**
	 * @param array{private: string, public: string, multibase: string} $material
	 */
	private function makeKey(string $localUserId, string $did, string $role, array $material, string $createdAt): PdsKey
	{
		return new PdsKey(
			bin2hex(random_bytes(16)),
			$localUserId,
			$did,
			$role,
			$this->secrets->seal($material['private']),
			$material['public'],
			$material['multibase'],
			true,
			$createdAt,
		);
	}

	private function pdsUrl(): string
	{
		try {
			return PdsConfig::publicUrl($this->config);
		} catch (InvalidArgumentException $e) {
			throw PdsError::internal($e->getMessage());
		}
	}

	private function nextRev(?string $previous, DateTimeImmutable $now): string
	{
		$microseconds = ((int) $now->format('U')) * 1_000_000 + ((int) $now->format('u'));
		if ($previous !== null) {
			$previousMicroseconds = PdsRev::decode($previous);
			$microseconds = max($microseconds, $previousMicroseconds + 1);
		}

		return PdsRev::encode($microseconds);
	}

	private function newRkey(string $did, string $collection, DateTimeImmutable $now): string
	{
		for ($attempt = 0; $attempt < 8; ++$attempt) {
			$candidate = Tid::forRecord($now, $did . '|' . $collection . '|' . bin2hex(random_bytes(8)));
			if ($this->repository->record($did, $collection, $candidate) === null) {
				return $candidate;
			}
		}

		throw PdsError::internal('A unique record key could not be generated.');
	}
}
