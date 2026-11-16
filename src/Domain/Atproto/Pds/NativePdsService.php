<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto\Pds;

use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Config\Config;
use AlohaSocial\Core\Platform\Storage\BlobStore;
use AlohaSocial\Core\Protocol\Atproto\Repository\Car;
use AlohaSocial\Core\Protocol\Atproto\Repository\Cid;
use AlohaSocial\Core\Protocol\Atproto\Repository\DagCbor;
use AlohaSocial\Core\Protocol\Atproto\Repository\Mst;
use AlohaSocial\Core\Protocol\Atproto\Repository\MstBuilder;
use AlohaSocial\Core\Protocol\Atproto\Repository\MstNode;
use AlohaSocial\Core\Protocol\Atproto\Repository\RepoCommit;
use AlohaSocial\Core\Protocol\Atproto\Tid;
use AlohaSocial\Core\Security\SecretBox;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A native AT Protocol repository service.
 *
 * It owns the pieces a PDS must own: account identity, a signed commit per
 * write, an MST root that changes with every write, record create/update/delete
 * (including atomic batches), blob metadata and bytes, DID documents backed by
 * rotation keys, and CAR export and sync metadata. The host still owns HTTP
 * authentication and account existence; this service only accepts a local
 * user id that the host has already authenticated.
 */
final class NativePdsService
{
	private const MAX_RECORDS = 10_000;

	private const MAX_WRITES = 200;

	private const MAX_BLOB_BYTES = 5_242_880;

	private const MAX_BLOB_QUOTA = 52_428_800;

	public function __construct(
		private readonly Config $config,
		private readonly PdsRepository $repository,
		private readonly SecretBox $secrets,
		private readonly Clock $clock,
		private readonly ?BlobStore $blobs = null,
		private readonly ?PdsDirectory $directory = null,
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
		$this->publishOperation($operation);

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
	public function resolveHandle(string $handle): array
	{
		$identity = $this->repository->identityByHandle(strtolower(trim($handle)));
		if ($identity === null) {
			throw PdsError::handleNotFound();
		}

		return ['did' => $identity->did];
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
		$this->publishOperation($operation);

		return $this->identityView($rotated);
	}

	/**
	 * Re-submit the newest signed PLC operation after a directory outage.
	 *
	 * @return array<string, mixed>
	 */
	public function submitPlcOperation(string $localUserId): array
	{
		if ($this->directory === null) {
			throw PdsError::directoryNotConfigured();
		}

		$identity = $this->requireIdentityForUser($localUserId);
		$operations = $this->repository->plcOperations($identity->did);
		if ($operations === []) {
			throw PdsError::internal('The identity has no PLC operation.');
		}

		$operation = $operations[array_key_last($operations)];
		$this->publishOperation($operation);

		return [
			'did' => $identity->did,
			'cid' => $operation->cid->toString(),
			'seq' => $operation->seq,
		];
	}

	private function publishOperation(PdsPlcOperation $operation): void
	{
		if ($this->directory === null) {
			return;
		}

		try {
			$this->directory->submit($operation);
		} catch (PdsError $e) {
			throw $e;
		} catch (\Throwable $e) {
			throw PdsError::directoryFailure($e->getMessage());
		}
	}

	/**
	 * @param array<string, mixed> $input
	 *
	 * @return array<string, mixed>
	 */
	public function createRecord(string $localUserId, array $input): array
	{
		return $this->writeRecord($localUserId, $input, false);
	}

	/**
	 * @param array<string, mixed> $input
	 *
	 * @return array<string, mixed>
	 */
	public function putRecord(string $localUserId, array $input): array
	{
		return $this->writeRecord($localUserId, $input, true);
	}

	/**
	 * @param array<string, mixed> $input
	 *
	 * @return array<string, mixed>
	 */
	public function deleteRecord(string $localUserId, array $input): array
	{
		$identity = $this->requireIdentityForUser($localUserId);
		$this->assertRepo($identity, $input['repo'] ?? null);

		$collection = $this->validatedCollection($input['collection'] ?? null);
		$rkey = $this->validatedRkey($input['rkey'] ?? null, true);
		$existing = $this->repository->record($identity->did, $collection, $rkey);
		if ($existing === null) {
			throw PdsError::recordNotFound();
		}

		$now = $this->clock->now();
		$records = $this->repository->records($identity->did);
		unset($records[$collection . '/' . $rkey]);

		$commit = $this->commit(
			$identity,
			$records,
			[],
			[[$collection, $rkey]],
			$now,
			[['action' => 'delete', 'path' => $collection . '/' . $rkey]],
		);

		return ['commit' => $commit];
	}

	/**
	 * @param array<string, mixed> $input
	 *
	 * @return array<string, mixed>
	 */
	public function applyWrites(string $localUserId, array $input): array
	{
		$identity = $this->requireIdentityForUser($localUserId);
		$this->assertRepo($identity, $input['repo'] ?? null);

		$writes = $input['writes'] ?? null;
		if (!is_array($writes) || !array_is_list($writes) || $writes === []) {
			throw PdsError::invalid('writes must be a non-empty array.');
		}

		if (count($writes) > self::MAX_WRITES) {
			throw PdsError::invalid('writes may contain at most ' . self::MAX_WRITES . ' operations.');
		}

		$now = $this->clock->now();
		$timestamp = Time::format($now);
		$records = $this->repository->records($identity->did);
		$puts = [];
		$deletes = [];
		$ops = [];
		$results = [];
		$seen = [];

		foreach ($writes as $write) {
			if (!is_array($write) || array_is_list($write)) {
				throw PdsError::invalid('Every write must be an object.');
			}

			$action = $write['action'] ?? null;
			if (!in_array($action, ['create', 'update', 'delete'], true)) {
				throw PdsError::invalid('write.action must be create, update or delete.');
			}

			$collection = $this->validatedCollection($write['collection'] ?? null);
			$rkey = $this->validatedRkey($write['rkey'] ?? null, $action !== 'create');
			if ($rkey === null) {
				$rkey = $this->newRkey($identity->did, $collection, $now, $records);
			}

			$key = $collection . '/' . $rkey;
			if (isset($seen[$key])) {
				throw PdsError::invalid('A batch may not touch the same record twice.');
			}
			$seen[$key] = true;

			$existing = $records[$key] ?? null;
			if ($action === 'create' && $existing !== null) {
				throw PdsError::recordExists();
			}
			if ($action !== 'create' && $existing === null) {
				throw PdsError::recordNotFound();
			}

			if ($action === 'delete') {
				unset($records[$key]);
				$deletes[] = [$collection, $rkey];
				$ops[] = ['action' => 'delete', 'path' => $collection . '/' . $rkey];
				$results[] = ['uri' => 'at://' . $identity->did . '/' . $collection . '/' . $rkey];
				continue;
			}

			$record = $this->validatedRecord($write['value'] ?? null, $collection);
			$cbor = DagCbor::encode($record);
			$cid = Cid::forData($cbor);
			$stored = new PdsRecord(
				$identity->did,
				$collection,
				$rkey,
				$cid,
				$cbor,
				$existing?->createdAt ?? $timestamp,
				$timestamp,
			);
			$records[$key] = $stored;
			$puts[] = $stored;
			$ops[] = [
				'action' => $action,
				'path' => $collection . '/' . $rkey,
				'cid' => $cid->toString(),
			];
			$results[] = [
				'uri' => $stored->uri(),
				'cid' => $cid->toString(),
				'validationStatus' => 'valid',
			];
		}

		if (count($records) > self::MAX_RECORDS) {
			throw PdsError::invalid('The repository has reached the local record limit.');
		}

		$commit = $this->commit($identity, $records, $puts, $deletes, $now, $ops);

		return [
			'commit' => $commit,
			'results' => $results,
			'validationStatus' => 'valid',
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getRecord(string $repo, string $collection, string $rkey, ?string $cid = null): array
	{
		$identity = $this->identityForRepo($repo);
		$record = $this->repository->record($identity->did, $collection, $rkey);
		if ($record === null) {
			throw PdsError::recordNotFound();
		}

		if ($cid !== null && $cid !== '' && $cid !== $record->cid->toString()) {
			throw PdsError::recordNotFound('The record CID does not match the stored value.');
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

	public function getRepo(string $repo, ?string $since = null): string
	{
		$identity = $this->identityForRepo($repo);
		if ($identity->head === null) {
			throw PdsError::repoNotFound('The repository has no commits.');
		}

		$blocks = $this->repository->blocks($identity->did);
		$headKey = $identity->head->toString();
		if (!isset($blocks[$headKey])) {
			throw PdsError::internal('The repository head block is missing.');
		}

		if ($since !== null && $since !== '') {
			$diff = $this->repoSince($identity, $since, $blocks);
			if ($diff !== null) {
				return (new Car($identity->head, $diff))->encode();
			}
		}

		return (new Car($identity->head, $blocks))->encode();
	}

	/**
	 * Blocks reachable from the current head but not from an earlier commit.
	 *
	 * An unknown or non-ancestor rev falls back to a complete export instead of
	 * producing a CAR a client cannot apply. The current commit is always the
	 * root, even when it was requested through `since`.
	 *
	 * @param array<string, string> $blocks
	 *
	 * @return array<string, string>|null
	 */
	private function repoSince(PdsIdentity $identity, string $since, array $blocks): ?array
	{
		$commits = [];
		$byRev = [];
		foreach ($this->repository->commits($identity->did) as $commit) {
			$commits[$commit->cid->toString()] = $commit;
			$byRev[$commit->rev] = $commit;
		}

		$old = $byRev[$since] ?? null;
		if ($old === null) {
			return null;
		}

		$current = $identity->head === null ? null : ($commits[$identity->head->toString()] ?? null);
		if ($current === null || !$this->isAncestor($old, $current, $commits)) {
			return null;
		}

		$currentBlocks = $this->reachableBlocks($current->dataCid, $blocks);
		$oldBlocks = $this->reachableBlocks($old->dataCid, $blocks);
		$currentBlocks[$current->cid->toString()] = $blocks[$current->cid->toString()];

		$diff = [];
		foreach ($currentBlocks as $cid => $bytes) {
			if (!isset($oldBlocks[$cid])) {
				$diff[$cid] = $bytes;
			}
		}

		return $diff;
	}

	/**
	 * @param array<string, PdsCommit> $commits
	 */
	private function isAncestor(PdsCommit $old, PdsCommit $current, array $commits): bool
	{
		$cursor = $current;
		$seen = [];
		while (true) {
			if ($cursor->cid->equals($old->cid)) {
				return true;
			}

			$key = $cursor->cid->toString();
			if (isset($seen[$key]) || $cursor->previousCid === null) {
				return false;
			}
			$seen[$key] = true;
			$next = $commits[$cursor->previousCid->toString()] ?? null;
			if ($next === null) {
				return false;
			}
			$cursor = $next;
		}
	}

	/**
	 * @param array<string, string> $blocks
	 *
	 * @return array<string, string>
	 */
	private function reachableBlocks(Cid $dataRoot, array $blocks): array
	{
		$selected = [];
		$stack = [$dataRoot];
		while ($stack !== []) {
			$cid = array_pop($stack);
			$key = $cid->toString();
			if (isset($selected[$key])) {
				continue;
			}

			$bytes = $blocks[$key] ?? null;
			if ($bytes === null) {
				throw PdsError::internal('A repository block is missing.');
			}
			$selected[$key] = $bytes;

			$node = MstNode::decode($cid, $bytes);
			if ($node->left !== null) {
				$stack[] = $node->left;
			}
			foreach ($node->entries as $entry) {
				$selected[$entry->value->toString()] = $blocks[$entry->value->toString()]
					?? throw PdsError::internal('A record block is missing.');
				if ($entry->right !== null) {
					$stack[] = $entry->right;
				}
			}
		}

		return $selected;
	}

	/**
	 * @param list<string> $cids
	 */
	public function getBlocks(string $repo, array $cids): string
	{
		$identity = $this->identityForRepo($repo);
		if ($cids === []) {
			throw PdsError::invalid('cids is required.');
		}

		$blocks = $this->repository->blocks($identity->did);
		$root = $identity->head;
		if ($root === null || !isset($blocks[$root->toString()])) {
			throw PdsError::repoNotFound('The repository has no commit.');
		}

		$selected = [$root->toString() => $blocks[$root->toString()]];
		try {
			foreach ($cids as $value) {
				$cid = Cid::fromString(trim($value));
				$key = $cid->toString();
				if (!isset($blocks[$key])) {
					throw PdsError::invalid('A requested block is not in this repository.');
				}

				$selected[$key] = $blocks[$key];
			}
		} catch (InvalidArgumentException $e) {
			throw PdsError::invalid($e->getMessage());
		}

		return (new Car($root, $selected))->encode();
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getLatestCommit(string $repo): array
	{
		$identity = $this->identityForRepo($repo);
		if ($identity->head === null || $identity->headRev === null) {
			throw PdsError::repoNotFound('The repository has no commits.');
		}

		return [
			'cid' => $identity->head->toString(),
			'rev' => $identity->headRev,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function listRepos(int $limit = 50, ?string $cursor = null): array
	{
		$limit = max(1, min(100, $limit));
		$repos = [];
		foreach ($this->repository->identities() as $identity) {
			if ($cursor !== null && $cursor !== '' && strcmp($identity->did, $cursor) <= 0) {
				continue;
			}

			$repos[] = [
				'did' => $identity->did,
				'head' => $identity->head?->toString(),
				'rev' => $identity->headRev,
			];
		}

		$hasMore = count($repos) > $limit;
		$repos = array_slice($repos, 0, $limit);
		$result = ['repos' => $repos];
		if ($hasMore && $repos !== []) {
			$result['cursor'] = $repos[array_key_last($repos)]['did'];
		}

		return $result;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function describeServer(): array
	{
		$url = $this->pdsUrl();
		$host = parse_url($url, PHP_URL_HOST);

		return [
			'did' => is_string($host) && $host !== '' ? 'did:web:' . $host : 'did:web:localhost',
			'availableUserDomains' => array_values(array_filter([PdsConfig::handleDomain($this->config)])),
			'inviteCodeRequired' => false,
			'phoneVerificationRequired' => false,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function uploadBlob(string $localUserId, string $mimeType, string $bytes): array
	{
		$identity = $this->requireIdentityForUser($localUserId);
		$blobs = $this->blobs;
		if ($blobs === null) {
			throw PdsError::blobsNotConfigured();
		}

		$mimeType = strtolower(trim(explode(';', $mimeType, 2)[0]));
		if (preg_match('~^[a-z0-9][a-z0-9!#$&^_.+-]{0,126}/[a-z0-9][a-z0-9!#$&^_.+-]{0,126}$~i', $mimeType) !== 1) {
			throw PdsError::invalid('Content-Type must be a valid media type.');
		}

		$size = strlen($bytes);
		if ($size === 0) {
			throw PdsError::invalid('A blob may not be empty.');
		}
		if ($size > self::MAX_BLOB_BYTES) {
			throw PdsError::blobTooLarge(self::MAX_BLOB_BYTES);
		}

		$cid = Cid::forBlob($bytes)->toString();
		$existing = $this->repository->blob($identity->did, $cid);
		if ($existing !== null) {
			return ['blob' => $this->blobView($existing)];
		}

		if ($this->repository->totalBlobBytes($identity->did) + $size > self::MAX_BLOB_QUOTA) {
			throw PdsError::blobQuotaExceeded(self::MAX_BLOB_QUOTA);
		}

		$path = $this->blobPath($identity->did, $cid);
		$blobs->put($path, $bytes);
		$timestamp = Time::format($this->clock->now());
		$blob = new PdsBlob($identity->did, $cid, $mimeType, $size, $timestamp);
		try {
			$this->repository->putBlob($blob);
		} catch (\Throwable $e) {
			$blobs->delete($path);

			throw $e;
		}

		$commit = $this->commit(
			$identity,
			$this->repository->records($identity->did),
			[],
			[],
			$this->clock->now(),
			[],
			[$cid],
		);

		return [
			'blob' => $this->blobView($blob),
			'commit' => $commit,
		];
	}

	/**
	 * @return array{mimeType: string, size: int, bytes: string}
	 */
	public function getBlob(string $repo, string $cid): array
	{
		$identity = $this->identityForRepo($repo);
		$blob = $this->repository->blob($identity->did, trim($cid));
		if ($blob === null) {
			throw PdsError::blobNotFound();
		}

		$blobs = $this->blobs;
		if ($blobs === null) {
			throw PdsError::blobsNotConfigured();
		}

		$path = $this->blobPath($identity->did, $blob->cid);
		if (!$blobs->exists($path)) {
			throw PdsError::blobNotFound();
		}

		return [
			'mimeType' => $blob->mimeType,
			'size' => $blob->size,
			'bytes' => $blobs->read($path),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function listBlobs(string $repo, int $limit = 50, ?string $cursor = null): array
	{
		$identity = $this->identityForRepo($repo);
		$limit = max(1, min(100, $limit));
		$blobs = [];
		foreach ($this->repository->blobs($identity->did) as $blob) {
			if ($cursor !== null && $cursor !== '' && strcmp($blob->cid, $cursor) <= 0) {
				continue;
			}

			$blobs[] = [
				'cid' => $blob->cid,
				'mimeType' => $blob->mimeType,
				'size' => $blob->size,
			];
		}

		$hasMore = count($blobs) > $limit;
		$blobs = array_slice($blobs, 0, $limit);
		$result = ['blobs' => $blobs];
		if ($hasMore && $blobs !== []) {
			$result['cursor'] = $blobs[array_key_last($blobs)]['cid'];
		}

		return $result;
	}

	/**
	 * Import a CAR archive into the signed-in account's empty repository.
	 *
	 * The source commit is not trusted as a head: its MST and record blocks
	 * are parsed and revalidated, then a new commit is signed with this PDS's
	 * active signing key. Record CIDs are preserved because the record blocks
	 * themselves are not rewritten.
	 *
	 * @return array<string, mixed>
	 */
	public function importRepo(string $localUserId, string $carBytes): array
	{
		$identity = $this->requireIdentityForUser($localUserId);
		if ($this->repository->records($identity->did) !== []) {
			throw PdsError::invalid('importRepo requires an empty repository.');
		}

		try {
			$car = Car::decode($carBytes);
			$commitBlock = $car->blocks[$car->root->toString()] ?? null;
			if ($commitBlock === null) {
				throw new InvalidArgumentException('The CAR root block is missing.');
			}

			$imported = RepoCommit::decode($commitBlock);
			$leaves = (new Mst($imported->data, $car->blocks))->all();
		} catch (InvalidArgumentException|\UnexpectedValueException $e) {
			throw PdsError::invalid('The CAR archive is not a valid repository: ' . $e->getMessage());
		}

		if (count($leaves) > self::MAX_RECORDS) {
			throw PdsError::invalid('The repository has reached the local record limit.');
		}

		$now = $this->clock->now();
		$timestamp = Time::format($now);
		$records = [];
		$puts = [];
		$ops = [];
		foreach ($leaves as $path => $cid) {
			$separator = strpos($path, '/');
			if ($separator === false) {
				throw PdsError::invalid('An imported MST key is not collection/rkey.');
			}

			$collection = $this->validatedCollection(substr($path, 0, $separator));
			$rkey = $this->validatedRkey(substr($path, $separator + 1), true);
			$block = $car->blocks[$cid->toString()] ?? null;
			if ($block === null) {
				throw PdsError::invalid('A record block referenced by the imported MST is missing.');
			}

			$value = DagCbor::decode($block);
			if (!is_array($value)) {
				throw PdsError::invalid('An imported record block is not a DAG-CBOR map.');
			}
			$this->validatedRecord($value, $collection);

			$record = new PdsRecord($identity->did, $collection, $rkey, $cid, $block, $timestamp, $timestamp);
			$records[$collection . '/' . $rkey] = $record;
			$puts[] = $record;
			$ops[] = ['action' => 'create', 'path' => $collection . '/' . $rkey, 'cid' => $cid->toString()];
		}

		$commit = $this->commit($identity, $records, $puts, [], $now, $ops);

		return [
			'commit' => $commit,
			'records' => count($puts),
		];
	}

	/**
	 * @param array<string, mixed> $input
	 *
	 * @return array<string, mixed>
	 */
	private function writeRecord(string $localUserId, array $input, bool $replace): array
	{
		$identity = $this->requireIdentityForUser($localUserId);
		$this->assertRepo($identity, $input['repo'] ?? null);

		$collection = $this->validatedCollection($input['collection'] ?? null);
		$recordInput = $this->validatedRecord($input['record'] ?? null, $collection);
		$rkey = $this->validatedRkey($input['rkey'] ?? null, $replace);
		$now = $this->clock->now();
		$timestamp = Time::format($now);
		if ($rkey === null) {
			$rkey = $this->newRkey($identity->did, $collection, $now);
		}

		$existing = $this->repository->record($identity->did, $collection, $rkey);
		if (!$replace && $existing !== null) {
			throw PdsError::recordExists();
		}

		if ($existing === null && count($this->repository->records($identity->did)) >= self::MAX_RECORDS) {
			throw PdsError::invalid('The repository has reached the local record limit.');
		}

		$cbor = DagCbor::encode($recordInput);
		$cid = Cid::forData($cbor);
		$record = new PdsRecord(
			$identity->did,
			$collection,
			$rkey,
			$cid,
			$cbor,
			$existing?->createdAt ?? $timestamp,
			$timestamp,
		);

		$records = $this->repository->records($identity->did);
		$records[$collection . '/' . $rkey] = $record;
		$commit = $this->commit(
			$identity,
			$records,
			[$record],
			[],
			$now,
			[[
				'action' => $existing === null ? 'create' : 'update',
				'path' => $collection . '/' . $rkey,
				'cid' => $cid->toString(),
			]],
		);

		return [
			'uri' => $record->uri(),
			'cid' => $cid->toString(),
			'commit' => $commit,
			'validationStatus' => 'valid',
		];
	}

	/**
	 * @param array<string, PdsRecord> $records
	 * @param list<PdsRecord> $puts
	 * @param list<array{0: string, 1: string}> $deletes
	 *
	 * @return array{cid: string, rev: string}
	 */
	private function commit(
		PdsIdentity $identity,
		array $records,
		array $puts,
		array $deletes,
		DateTimeImmutable $now,
		array $ops = [],
		array $blobs = [],
	): array {
		$values = [];
		foreach ($records as $key => $stored) {
			$values[$key] = $stored->cid;
		}

		$mst = (new MstBuilder())->build($values);
		$signing = $this->key($identity, $identity->signingKeyId);
		$seq = $identity->seq + 1;
		$rev = $this->nextRev($identity->headRev, $now);
		$timestamp = Time::format($now);
		$commit = RepoCommit::sign(
			$identity->did,
			$rev,
			$identity->head,
			$mst['root'],
			$this->secrets->open($signing->sealedPrivateKey),
		);

		$blocks = $mst['blocks'];
		foreach ($puts as $record) {
			$blocks[$record->cid->toString()] = $record->cbor;
		}
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

		$event = new PdsEvent(
			0,
			$identity->did,
			$commit->cid()->toString(),
			$rev,
			$identity->headRev ?? '',
			$ops,
			(new Car($commit->cid(), $blocks))->encode(),
			$blobs,
			$timestamp,
		);
		$storedEvent = $this->repository->applyCommit($updated, $storedCommit, $blocks, $puts, $deletes, $event);

		return [
			'cid' => $commit->cid()->toString(),
			'rev' => $rev,
			'seq' => $storedEvent?->seq,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function validatedCollection(mixed $collection): string
	{
		if (!is_string($collection) || preg_match('/^[a-z][a-zA-Z0-9]*(\.[a-z][a-zA-Z0-9]*)+$/', $collection) !== 1) {
			throw PdsError::invalid('collection must be a valid NSID.');
		}

		return $collection;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function validatedRecord(mixed $recordInput, string $collection): array
	{
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

		return $recordInput;
	}

	private function validatedRkey(mixed $rkey, bool $required): ?string
	{
		if ($rkey === null || $rkey === '') {
			if ($required) {
				throw PdsError::invalid('rkey is required.');
			}

			return null;
		}

		if (!is_string($rkey) || preg_match('/^[a-zA-Z0-9._~:-]{1,512}$/', $rkey) !== 1) {
			throw PdsError::invalid('rkey contains characters outside the AT Protocol record key grammar.');
		}

		return $rkey;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function blobView(PdsBlob $blob): array
	{
		return [
			'$type' => 'blob',
			'ref' => ['$link' => $blob->cid],
			'mimeType' => $blob->mimeType,
			'size' => $blob->size,
		];
	}

	private function blobPath(string $did, string $cid): string
	{
		return 'pds/blobs/' . hash('sha256', $did) . '/' . $cid . '.bin';
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

	/**
	 * @param array<string, PdsRecord>|null $records
	 */
	private function newRkey(string $did, string $collection, DateTimeImmutable $now, ?array $records = null): string
	{
		for ($attempt = 0; $attempt < 8; ++$attempt) {
			$candidate = Tid::forRecord($now, $did . '|' . $collection . '|' . bin2hex(random_bytes(8)));
			$exists = $records !== null
				? isset($records[$collection . '/' . $candidate])
				: $this->repository->record($did, $collection, $candidate) !== null;
			if (!$exists) {
				return $candidate;
			}
		}

		throw PdsError::internal('A unique record key could not be generated.');
	}
}
