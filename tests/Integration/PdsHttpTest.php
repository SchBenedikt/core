<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Integration;

use AlohaSocial\Core\Domain\Atproto\Pds\PdoPdsRepository;
use AlohaSocial\Core\Domain\Atproto\Pds\PdsRev;
use AlohaSocial\Core\Platform\Db\PdoConnection;
use AlohaSocial\Core\Protocol\Atproto\Repository\Bytes;
use AlohaSocial\Core\Protocol\Atproto\Repository\Car;
use AlohaSocial\Core\Protocol\Atproto\Repository\Cid;
use AlohaSocial\Core\Protocol\Atproto\Repository\DagCbor;
use AlohaSocial\Core\Protocol\Atproto\Repository\Ecdsa;
use AlohaSocial\Core\Protocol\Atproto\Repository\Mst;
use AlohaSocial\Core\Protocol\Atproto\Repository\RepoCommit;
use AlohaSocial\Core\Security\OpenSslSecretBox;
use PHPUnit\Framework\TestCase;
use PDO;
use RuntimeException;

use function array_values;
use function bin2hex;
use function fclose;
use function file_get_contents;
use function is_array;
use function is_dir;
use function is_resource;
use function is_string;
use function json_decode;
use function json_encode;
use function mkdir;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function random_bytes;
use function rmdir;
use function scandir;
use function stream_context_create;
use function stream_socket_get_name;
use function stream_socket_server;
use function str_contains;
use function strrpos;
use function substr;
use function sys_get_temp_dir;
use function unlink;
use function usleep;

use const PHP_BINARY;

/**
 * Exercises the native PDS through a real listening socket and a durable
 * SQLite database, including a process restart between reads.
 */
final class PdsHttpTest extends TestCase
{
	private string $directory;

	private string $databasePath;

	private string $secret;

	private string $baseUrl = '';

	/**
	 * @var resource|null
	 */
	private $process = null;

	/**
	 * @var list<resource>
	 */
	private array $pipes = [];

	private string $log;

	private int $firehosePort = 0;

	/**
	 * @var resource|null
	 */
	private $firehoseProcess = null;

	private string $firehoseLog;

	protected function setUp(): void
	{
		$this->directory = sys_get_temp_dir() . '/alohasocial-pds-' . bin2hex(random_bytes(8));
		if (!mkdir($this->directory, 0o770, true) && !is_dir($this->directory)) {
			throw new RuntimeException('Could not create the PDS test directory.');
		}

		$this->databasePath = $this->directory . '/pds.sqlite';
		$this->secret = OpenSslSecretBox::generateKey();
		$this->log = $this->directory . '/server.log';
		$this->firehoseLog = $this->directory . '/firehose.log';
	}

	protected function tearDown(): void
	{
		$this->stopFirehose();
		$this->stopServer();
		$this->remove($this->directory);
	}

	public function testHttpRepositorySurvivesRestartAndCommitsAreVerifiable(): void
	{
		$this->startServer();

		$created = $this->jsonRequest('POST', '/xrpc/com.atproto.identity.createDid', [
			'handle' => 'alice.pds.example',
		], 'alice');
		self::assertSame(200, $created['status'], $created['body']);
		$did = $created['json']['did'] ?? '';
		self::assertMatchesRegularExpression('/^did:plc:[a-z2-7]{24}$/', $did);
		self::assertSame('alice.pds.example', $created['json']['handle'] ?? null);
		self::assertSame($did, $created['json']['didDocument']['id'] ?? null);
		self::assertSame(
			'https://pds.example',
			$created['json']['didDocument']['service'][0]['serviceEndpoint'] ?? null,
		);

		$resolved = $this->jsonRequest('GET', '/xrpc/com.atproto.identity.resolveDid?' . http_build_query(['did' => $did]));
		self::assertSame(200, $resolved['status'], $resolved['body']);
		self::assertSame($did, $resolved['json']['id'] ?? null);
		self::assertCount(2, $resolved['json']['verificationMethod'] ?? []);

		$first = $this->createRecord($did, 'first record', 'alice');
		$second = $this->createRecord($did, 'second record', 'alice');
		self::assertNotSame($first['cid'], $second['cid']);
		self::assertNotSame($first['commit']['cid'], $second['commit']['cid']);
		self::assertGreaterThan(
			PdsRev::decode($first['commit']['rev']),
			PdsRev::decode($second['commit']['rev']),
		);

		$rkey = substr($second['uri'], strrpos($second['uri'], '/') + 1);
		$record = $this->jsonRequest('GET', '/xrpc/com.atproto.repo.getRecord?' . http_build_query([
			'repo' => $did,
			'collection' => 'app.bsky.feed.post',
			'rkey' => $rkey,
		]));
		self::assertSame(200, $record['status'], $record['body']);
		self::assertSame('second record', $record['json']['value']['text'] ?? null);
		$resolvedIdentity = $this->jsonRequest('GET', '/xrpc/com.atproto.identity.resolveIdentity?' . http_build_query(['identifier' => 'alice.pds.example']));
		self::assertSame(200, $resolvedIdentity['status'], $resolvedIdentity['body']);
		self::assertSame($did, $resolvedIdentity['json']['did'] ?? null);
		self::assertSame('alice.pds.example', $resolvedIdentity['json']['handle'] ?? null);

		$pageOne = $this->jsonRequest('GET', '/xrpc/com.atproto.repo.listRecords?' . http_build_query([
			'repo' => $did,
			'collection' => 'app.bsky.feed.post',
			'limit' => 1,
		]));
		self::assertSame(200, $pageOne['status'], $pageOne['body']);
		self::assertCount(1, $pageOne['json']['records'] ?? []);
		self::assertArrayHasKey('cursor', $pageOne['json']);
		$pageTwo = $this->jsonRequest('GET', '/xrpc/com.atproto.repo.listRecords?' . http_build_query([
			'repo' => $did,
			'collection' => 'app.bsky.feed.post',
			'cursor' => $pageOne['json']['cursor'],
		]));
		self::assertCount(1, $pageTwo['json']['records'] ?? []);

		$described = $this->jsonRequest('GET', '/xrpc/com.atproto.repo.describeRepo?' . http_build_query(['repo' => $did]));
		self::assertSame(200, $described['status'], $described['body']);
		self::assertSame(['app.bsky.feed.post'], $described['json']['collections'] ?? []);

		$export = $this->request('GET', '/xrpc/com.atproto.sync.getRepo?' . http_build_query(['did' => $did]));
		self::assertSame(200, $export['status']);
		self::assertSame('application/vnd.ipld.car', $export['contentType']);
		$car = Car::decode($export['body']);
		self::assertSame($second['commit']['cid'], $car->root->toString());
		$commit = RepoCommit::decode($car->blocks[$car->root->toString()]);
		$repository = $this->repository();
		$identity = $repository->identityByDid($did);
		self::assertNotNull($identity);
		$signingKey = $repository->key($did, $identity->signingKeyId);
		self::assertNotNull($signingKey);
		self::assertTrue($commit->verify($signingKey->publicKeyPem));
		self::assertSame($second['commit']['cid'], $identity->head?->toString());

		$mst = new Mst($commit->data, $car->blocks);
		self::assertCount(2, $mst->all());
		$status = $this->jsonRequest('GET', '/xrpc/com.atproto.sync.getRepoStatus?' . http_build_query(['did' => $did]));
		self::assertSame(200, $status['status'], $status['body']);
		self::assertTrue($status['json']['active'] ?? false);
		self::assertSame($second['commit']['rev'], $status['json']['rev'] ?? null);
		$syncRecord = $this->request('GET', '/xrpc/com.atproto.sync.getRecord?' . http_build_query([
			'did' => $did,
			'collection' => 'app.bsky.feed.post',
			'rkey' => $rkey,
		]));
		self::assertSame(200, $syncRecord['status']);
		self::assertSame('application/vnd.ipld.car', $syncRecord['contentType']);
		$proofCar = Car::decode($syncRecord['body']);
		self::assertSame($second['commit']['cid'], $proofCar->root->toString());
		self::assertSame($record['json']['cid'], Cid::forData($proofCar->blocks[$record['json']['cid']] ?? '')->toString());
		self::assertSame($record['json']['cid'], $mst->lookup('app.bsky.feed.post/' . $rkey)?->toString());

		$commits = $repository->commits($did);
		self::assertCount(3, $commits);
		self::assertNull($commits[0]->previousCid);
		self::assertSame($commits[0]->cid->toString(), $commits[1]->previousCid?->toString());
		self::assertSame($commits[1]->cid->toString(), $commits[2]->previousCid?->toString());
		foreach ($commits as $stored) {
			self::assertTrue(RepoCommit::decode($stored->bytes)->verify($signingKey->publicKeyPem));
		}

		$beforeRestartRoot = $car->root->toString();
		$beforeRestartRecord = $record['json']['cid'] ?? null;
		$this->stopServer();
		$this->startServer();

		$afterRestart = $this->jsonRequest('GET', '/xrpc/com.atproto.repo.getRecord?' . http_build_query([
			'repo' => $did,
			'collection' => 'app.bsky.feed.post',
			'rkey' => $rkey,
		]));
		self::assertSame(200, $afterRestart['status'], $afterRestart['body']);
		self::assertSame($beforeRestartRecord, $afterRestart['json']['cid'] ?? null);

		$afterRestartExport = $this->request('GET', '/xrpc/com.atproto.sync.getRepo?' . http_build_query(['did' => $did]));
		self::assertSame(200, $afterRestartExport['status']);
		self::assertSame($beforeRestartRoot, Car::decode($afterRestartExport['body'])->root->toString());
	}

	public function testWritesRequireAuthAndCannotCrossRepositoryOwnership(): void
	{
		$this->startServer();

		$alice = $this->jsonRequest('POST', '/xrpc/com.atproto.identity.createDid', [
			'handle' => 'alice.pds.example',
		], 'alice');
		$bob = $this->jsonRequest('POST', '/xrpc/com.atproto.identity.createDid', [
			'handle' => 'bob.pds.example',
		], 'bob');
		self::assertSame(200, $alice['status'], $alice['body']);
		self::assertSame(200, $bob['status'], $bob['body']);

		$anonymous = $this->jsonRequest('POST', '/xrpc/com.atproto.repo.createRecord', [
			'repo' => $alice['json']['did'],
			'collection' => 'app.bsky.feed.post',
			'record' => ['$type' => 'app.bsky.feed.post', 'text' => 'anonymous'],
		]);
		self::assertSame(401, $anonymous['status'], $anonymous['body']);
		self::assertSame('AuthRequired', $anonymous['json']['error'] ?? null);

		$foreign = $this->jsonRequest('POST', '/xrpc/com.atproto.repo.createRecord', [
			'repo' => $alice['json']['did'],
			'collection' => 'app.bsky.feed.post',
			'record' => ['$type' => 'app.bsky.feed.post', 'text' => 'foreign'],
		], 'bob');
		self::assertSame(403, $foreign['status'], $foreign['body']);
		self::assertSame('Forbidden', $foreign['json']['error'] ?? null);

		$own = $this->createRecord($bob['json']['did'], 'bob owns this', 'bob');
		self::assertStringContainsString($bob['json']['did'], $own['uri']);
	}

	public function testKeyRotationMakesOnlyTheNewSigningKeyValid(): void
	{
		$this->startServer();

		$created = $this->jsonRequest('POST', '/xrpc/com.atproto.identity.createDid', [
			'handle' => 'alice.pds.example',
		], 'alice');
		self::assertSame(200, $created['status'], $created['body']);
		$did = $created['json']['did'] ?? '';
		$oldSigningMultibase = $created['json']['signingKey'] ?? '';
		$oldRotationMultibase = $created['json']['rotationKey'] ?? '';

		$repository = $this->repository();
		$before = $repository->identityByDid($did);
		self::assertNotNull($before);
		$oldSigning = $repository->key($did, $before->signingKeyId);
		$oldRotation = $repository->key($did, $before->rotationKeyId);
		self::assertNotNull($oldSigning);
		self::assertNotNull($oldRotation);

		$this->createRecord($did, 'before rotation', 'alice');

		$rotated = $this->jsonRequest('POST', '/xrpc/com.atproto.identity.rotateKeys', [], 'alice');
		self::assertSame(200, $rotated['status'], $rotated['body']);
		self::assertSame($did, $rotated['json']['did'] ?? null);
		self::assertNotSame($oldSigningMultibase, $rotated['json']['signingKey'] ?? null);
		self::assertNotSame($oldRotationMultibase, $rotated['json']['rotationKey'] ?? null);

		$repository = $this->repository();
		$after = $repository->identityByDid($did);
		self::assertNotNull($after);
		self::assertNotSame($before->signingKeyId, $after->signingKeyId);
		self::assertNotSame($before->rotationKeyId, $after->rotationKeyId);
		self::assertFalse($repository->key($did, $before->signingKeyId)?->active);
		self::assertFalse($repository->key($did, $before->rotationKeyId)?->active);
		$newSigning = $repository->key($did, $after->signingKeyId);
		self::assertNotNull($newSigning);
		self::assertTrue($newSigning->active);

		$operations = $repository->plcOperations($did);
		self::assertCount(2, $operations);
		$operation = DagCbor::decode($operations[1]->bytes);
		self::assertIsArray($operation);
		self::assertSame('plc_operation', $operation['type'] ?? null);
		self::assertSame('did:key:' . ($rotated['json']['rotationKey'] ?? ''), $operation['rotationKeys'][0] ?? null);
		self::assertInstanceOf(\AlohaSocial\Core\Protocol\Atproto\Repository\Bytes::class, $operation['sig'] ?? null);
		$signature = $operation['sig']->value;
		unset($operation['sig']);
		self::assertTrue(Ecdsa::verify(DagCbor::encode($operation), $signature, $oldRotation->publicKeyPem));

		$afterRotation = $this->createRecord($did, 'after rotation', 'alice');
		$export = $this->request('GET', '/xrpc/com.atproto.sync.getRepo?' . http_build_query(['did' => $did]));
		self::assertSame(200, $export['status']);
		$car = Car::decode($export['body']);
		self::assertSame($afterRotation['commit']['cid'], $car->root->toString());
		$commit = RepoCommit::decode($car->blocks[$car->root->toString()]);
		self::assertTrue($commit->verify($newSigning->publicKeyPem));
		self::assertFalse($commit->verify($oldSigning->publicKeyPem));

		$this->stopServer();
		$this->startServer();

		$persisted = $this->repository();
		self::assertSame($after->signingKeyId, $persisted->identityByDid($did)?->signingKeyId);
		$third = $this->createRecord($did, 'after restart', 'alice');
		$secondExport = $this->request('GET', '/xrpc/com.atproto.sync.getRepo?' . http_build_query(['did' => $did]));
		self::assertSame(200, $secondExport['status']);
		$secondCar = Car::decode($secondExport['body']);
		self::assertSame($third['commit']['cid'], $secondCar->root->toString());
		$secondCommit = RepoCommit::decode($secondCar->blocks[$secondCar->root->toString()]);
		self::assertTrue($secondCommit->verify($newSigning->publicKeyPem));
		self::assertFalse($secondCommit->verify($oldSigning->publicKeyPem));
	}

	public function testRecordReplacementDeletionAndBatchWritesAreAtomic(): void
	{
		$this->startServer();

		$created = $this->jsonRequest('POST', '/xrpc/com.atproto.identity.createDid', [
			'handle' => 'alice.pds.example',
		], 'alice');
		self::assertSame(200, $created['status'], $created['body']);
		$did = $created['json']['did'] ?? '';

		$first = $this->jsonRequest('POST', '/xrpc/com.atproto.repo.putRecord', [
			'repo' => $did,
			'collection' => 'app.bsky.feed.post',
			'rkey' => 'fixed',
			'record' => ['$type' => 'app.bsky.feed.post', 'text' => 'first'],
		], 'alice');
		self::assertSame(200, $first['status'], $first['body']);
		$firstCid = $first['json']['cid'] ?? null;
		$stale = $this->jsonRequest('POST', '/xrpc/com.atproto.repo.putRecord', [
			'repo' => $did,
			'collection' => 'app.bsky.feed.post',
			'rkey' => 'fixed',
			'swapRecord' => 'bafyreistale',
			'record' => ['$type' => 'app.bsky.feed.post', 'text' => 'must not overwrite'],
		], 'alice');
		self::assertSame(400, $stale['status']);
		self::assertSame('InvalidSwap', $stale['json']['error'] ?? null);
		self::assertSame($firstCid, $this->jsonRequest('GET', '/xrpc/com.atproto.repo.getRecord?' . http_build_query([
			'repo' => $did,
			'collection' => 'app.bsky.feed.post',
			'rkey' => 'fixed',
		]))['json']['cid'] ?? null);

		$replaced = $this->jsonRequest('POST', '/xrpc/com.atproto.repo.putRecord', [
			'repo' => $did,
			'collection' => 'app.bsky.feed.post',
			'rkey' => 'fixed',
			'swapRecord' => $firstCid,
			'record' => ['$type' => 'app.bsky.feed.post', 'text' => 'replaced'],
		], 'alice');
		self::assertSame(200, $replaced['status'], $replaced['body']);
		self::assertSame($first['json']['uri'] ?? null, $replaced['json']['uri'] ?? null);
		self::assertNotSame($firstCid, $replaced['json']['cid'] ?? null);
		self::assertSame('replaced', $this->jsonRequest('GET', '/xrpc/com.atproto.repo.getRecord?' . http_build_query([
			'repo' => $did,
			'collection' => 'app.bsky.feed.post',
			'rkey' => 'fixed',
		]))['json']['value']['text'] ?? null);

		$second = $this->createRecord($did, 'delete me', 'alice');
		$secondRkey = substr($second['uri'], strrpos($second['uri'], '/') + 1);
		$staleCommit = $this->jsonRequest('POST', '/xrpc/com.atproto.repo.applyWrites', [
			'repo' => $did,
			'swapCommit' => 'bafyreistale',
			'writes' => [[
				'action' => 'update',
				'collection' => 'app.bsky.feed.post',
				'rkey' => 'fixed',
				'value' => ['$type' => 'app.bsky.feed.post', 'text' => 'stale commit'],
			]],
		], 'alice');
		self::assertSame(400, $staleCommit['status']);
		self::assertSame('InvalidSwap', $staleCommit['json']['error'] ?? null);
		$batch = $this->jsonRequest('POST', '/xrpc/com.atproto.repo.applyWrites', [
			'repo' => $did,
			'swapCommit' => $second['commit']['cid'] ?? null,
			'writes' => [
				[
					'action' => 'update',
					'collection' => 'app.bsky.feed.post',
					'rkey' => 'fixed',
					'swapRecord' => $replaced['json']['cid'] ?? null,
					'value' => ['$type' => 'app.bsky.feed.post', 'text' => 'batched'],
				],
				[
					'action' => 'create',
					'collection' => 'app.bsky.feed.post',
					'rkey' => 'third',
					'value' => ['$type' => 'app.bsky.feed.post', 'text' => 'third'],
				],
				[
					'action' => 'delete',
					'collection' => 'app.bsky.feed.post',
					'rkey' => $secondRkey,
				],
			],
		], 'alice');
		self::assertSame(200, $batch['status'], $batch['body']);
		$staleBatch = $this->jsonRequest('POST', '/xrpc/com.atproto.repo.applyWrites', [
			'repo' => $did,
			'swapCommit' => $batch['json']['commit']['cid'] ?? null,
			'writes' => [[
				'action' => 'update',
				'collection' => 'app.bsky.feed.post',
				'rkey' => 'fixed',
				'swapRecord' => $replaced['json']['cid'] ?? null,
				'value' => ['$type' => 'app.bsky.feed.post', 'text' => 'stale batch'],
			]],
		], 'alice');
		self::assertSame(400, $staleBatch['status']);
		self::assertSame('InvalidSwap', $staleBatch['json']['error'] ?? null);
		self::assertSame('batched', $this->jsonRequest('GET', '/xrpc/com.atproto.repo.getRecord?' . http_build_query([
			'repo' => $did,
			'collection' => 'app.bsky.feed.post',
			'rkey' => 'fixed',
		]))['json']['value']['text'] ?? null);
		self::assertCount(3, $batch['json']['results'] ?? []);
		self::assertSame('batched', $this->jsonRequest('GET', '/xrpc/com.atproto.repo.getRecord?' . http_build_query([
			'repo' => $did,
			'collection' => 'app.bsky.feed.post',
			'rkey' => 'fixed',
		]))['json']['value']['text'] ?? null);
		self::assertSame(404, $this->jsonRequest('GET', '/xrpc/com.atproto.repo.getRecord?' . http_build_query([
			'repo' => $did,
			'collection' => 'app.bsky.feed.post',
			'rkey' => $secondRkey,
		]))['status']);

		$records = $this->jsonRequest('GET', '/xrpc/com.atproto.repo.listRecords?' . http_build_query([
			'repo' => $did,
			'collection' => 'app.bsky.feed.post',
		]));
		self::assertCount(2, $records['json']['records'] ?? []);
		self::assertCount(5, $this->repository()->commits($did));

		$deleted = $this->jsonRequest('POST', '/xrpc/com.atproto.repo.deleteRecord', [
			'repo' => $did,
			'collection' => 'app.bsky.feed.post',
			'rkey' => 'fixed',
		], 'alice');
		self::assertSame(200, $deleted['status'], $deleted['body']);
		self::assertSame(404, $this->jsonRequest('GET', '/xrpc/com.atproto.repo.getRecord?' . http_build_query([
			'repo' => $did,
			'collection' => 'app.bsky.feed.post',
			'rkey' => 'fixed',
		]))['status']);
		self::assertCount(6, $this->repository()->commits($did));

		$latest = $this->jsonRequest('GET', '/xrpc/com.atproto.sync.getLatestCommit?' . http_build_query(['did' => $did]));
		self::assertSame(200, $latest['status'], $latest['body']);
		self::assertSame($this->repository()->identityByDid($did)?->head?->toString(), $latest['json']['cid'] ?? null);
		self::assertSame($did, $this->jsonRequest('GET', '/xrpc/com.atproto.identity.resolveHandle?handle=alice.pds.example')['json']['did'] ?? null);
		self::assertNotEmpty($this->jsonRequest('GET', '/xrpc/com.atproto.sync.listRepos')['json']['repos'] ?? []);
		self::assertSame('pds.example', $this->jsonRequest('GET', '/xrpc/com.atproto.server.describeServer')['json']['availableUserDomains'][0] ?? null);
	}

	public function testBlobUploadReadAndListAreScopedToTheRepository(): void
	{
		$this->startServer();

		$alice = $this->jsonRequest('POST', '/xrpc/com.atproto.identity.createDid', ['handle' => 'alice.pds.example'], 'alice');
		$bob = $this->jsonRequest('POST', '/xrpc/com.atproto.identity.createDid', ['handle' => 'bob.pds.example'], 'bob');
		self::assertSame(200, $alice['status'], $alice['body']);
		self::assertSame(200, $bob['status'], $bob['body']);
		$aliceDid = $alice['json']['did'] ?? '';
		$bobDid = $bob['json']['did'] ?? '';

		$bytes = "\x89PNG\r\n\x1a\n" . random_bytes(32);
		$anonymous = $this->rawRequest('POST', '/xrpc/com.atproto.repo.uploadBlob', $bytes, 'image/png');
		self::assertSame(401, $anonymous['status']);

		$uploaded = $this->rawRequest('POST', '/xrpc/com.atproto.repo.uploadBlob', $bytes, 'image/png', 'alice');
		self::assertSame(200, $uploaded['status'], $uploaded['body']);
		$json = json_decode($uploaded['body'], true);
		self::assertIsArray($json);
		$blobCid = $json['blob']['ref']['$link'] ?? '';
		self::assertNotSame('', $blobCid);
		self::assertSame('image/png', $json['blob']['mimeType'] ?? null);
		self::assertSame(strlen($bytes), $json['blob']['size'] ?? null);

		$repository = $this->repository();
		self::assertNotNull($repository->identityByDid($aliceDid));
		self::assertNotNull($repository->blob($aliceDid, $blobCid), json_encode($repository->blobs($aliceDid)));

		$read = $this->rawRequest('GET', '/xrpc/com.atproto.sync.getBlob?' . http_build_query([
			'did' => $aliceDid,
			'cid' => $blobCid,
		]));
		self::assertSame(200, $read['status'], $read['body']);
		self::assertSame($bytes, $read['body']);
		self::assertSame('image/png', $read['contentType']);

		$list = $this->jsonRequest('GET', '/xrpc/com.atproto.sync.listBlobs?' . http_build_query(['did' => $aliceDid]));
		self::assertSame(200, $list['status'], $list['body']);
		self::assertCount(1, $list['json']['blobs'] ?? []);
		self::assertSame($blobCid, $list['json']['blobs'][0]['cid'] ?? null);

		$foreign = $this->rawRequest('GET', '/xrpc/com.atproto.sync.getBlob?' . http_build_query([
			'did' => $bobDid,
			'cid' => $blobCid,
		]));
		self::assertSame(404, $foreign['status']);
	}

	public function testListMissingBlobsFindsRepositoryReferencesAndPaginates(): void
	{
		$this->startServer();

		$alice = $this->jsonRequest('POST', '/xrpc/com.atproto.identity.createDid', ['handle' => 'alice.pds.example'], 'alice');
		$bob = $this->jsonRequest('POST', '/xrpc/com.atproto.identity.createDid', ['handle' => 'bob.pds.example'], 'bob');
		self::assertSame(200, $alice['status'], $alice['body']);
		self::assertSame(200, $bob['status'], $bob['body']);
		$aliceDid = $alice['json']['did'] ?? '';
		$bobDid = $bob['json']['did'] ?? '';

		$anonymous = $this->jsonRequest('GET', '/xrpc/com.atproto.repo.listMissingBlobs?' . http_build_query(['repo' => $aliceDid]));
		self::assertSame(401, $anonymous['status']);
		$wrongRepo = $this->jsonRequest('GET', '/xrpc/com.atproto.repo.listMissingBlobs?' . http_build_query(['repo' => $bobDid]), null, 'alice');
		self::assertSame(403, $wrongRepo['status']);

		$blobCids = [];
		foreach (["\x89PNG\r\n\x1a\nfirst", "\x89PNG\r\n\x1a\nsecond"] as $bytes) {
			$uploaded = $this->rawRequest('POST', '/xrpc/com.atproto.repo.uploadBlob', $bytes, 'image/png', 'bob');
			self::assertSame(200, $uploaded['status'], $uploaded['body']);
			$blobCids[] = json_decode($uploaded['body'], true)['blob']['ref']['$link'] ?? '';
		}
		sort($blobCids, SORT_STRING);

		$recordUris = [];
		foreach ($blobCids as $index => $cid) {
			$response = $this->jsonRequest('POST', '/xrpc/com.atproto.repo.createRecord', [
				'repo' => $aliceDid,
				'collection' => 'app.bsky.feed.post',
				'rkey' => 'blob-' . $index,
				'record' => [
					'$type' => 'app.bsky.feed.post',
					'text' => 'migration blob reference',
					'createdAt' => '2026-11-25T00:00:00Z',
					'embed' => [
						'$type' => 'app.bsky.embed.images',
						'images' => [[
							'alt' => '',
							'image' => ['$type' => 'blob', 'ref' => ['$link' => $cid], 'mimeType' => 'image/png', 'size' => 16],
						]],
					],
				],
			], 'alice');
			self::assertSame(200, $response['status'], $response['body']);
			$recordUris[$cid] = $response['json']['uri'] ?? '';
			$rkey = substr($recordUris[$cid], strrpos($recordUris[$cid], '/') + 1);
			$read = $this->jsonRequest('GET', '/xrpc/com.atproto.repo.getRecord?' . http_build_query([
				'repo' => $aliceDid,
				'collection' => 'app.bsky.feed.post',
				'rkey' => $rkey,
			]));
			self::assertSame($cid, $read['json']['value']['embed']['images'][0]['image']['ref']['$link'] ?? null);
		}

		$query = '/xrpc/com.atproto.repo.listMissingBlobs?' . http_build_query(['repo' => $aliceDid, 'limit' => 1]);
		$pageOne = $this->jsonRequest('GET', $query, null, 'alice');
		self::assertSame(200, $pageOne['status'], $pageOne['body']);
		self::assertCount(1, $pageOne['json']['blobs'] ?? []);
		self::assertSame($blobCids[0], $pageOne['json']['blobs'][0]['cid'] ?? null);
		self::assertSame($recordUris[$blobCids[0]], $pageOne['json']['blobs'][0]['recordUri'] ?? null);
		self::assertSame($blobCids[0], $pageOne['json']['cursor'] ?? null);

		$pageTwo = $this->jsonRequest('GET', $query . '&cursor=' . rawurlencode($pageOne['json']['cursor']), null, 'alice');
		self::assertSame(200, $pageTwo['status'], $pageTwo['body']);
		self::assertCount(1, $pageTwo['json']['blobs'] ?? []);
		self::assertSame($blobCids[1], $pageTwo['json']['blobs'][0]['cid'] ?? null);
		self::assertArrayNotHasKey('cursor', $pageTwo['json']);

		$present = $this->rawRequest('POST', '/xrpc/com.atproto.repo.uploadBlob', "\x89PNG\r\n\x1a\nfirst", 'image/png', 'alice');
		self::assertSame(200, $present['status'], $present['body']);
		$remaining = $this->jsonRequest('GET', '/xrpc/com.atproto.repo.listMissingBlobs?' . http_build_query(['repo' => $aliceDid]), null, 'alice');
		self::assertSame([$blobCids[1]], array_column($remaining['json']['blobs'] ?? [], 'cid'));
	}

	public function testFirehoseStreamsEveryMutationAndReplaysFromACursor(): void
	{
		$this->startServer();

		$created = $this->jsonRequest('POST', '/xrpc/com.atproto.identity.createDid', [
			'handle' => 'alice.pds.example',
		], 'alice');
		self::assertSame(200, $created['status'], $created['body']);
		$did = $created['json']['did'] ?? '';

		$this->startFirehose(true);
		$live = $this->openFirehose();

		$first = $this->createRecord($did, 'one', 'alice');
		$firstRkey = substr($first['uri'], strrpos($first['uri'], '/') + 1);
		$second = $this->createRecord($did, 'two', 'alice');
		$secondRkey = substr($second['uri'], strrpos($second['uri'], '/') + 1);

		$put = $this->jsonRequest('POST', '/xrpc/com.atproto.repo.putRecord', [
			'repo' => $did,
			'collection' => 'app.bsky.feed.post',
			'rkey' => $firstRkey,
			'record' => ['$type' => 'app.bsky.feed.post', 'text' => 'one replaced'],
		], 'alice');
		self::assertSame(200, $put['status'], $put['body']);

		$deleted = $this->jsonRequest('POST', '/xrpc/com.atproto.repo.deleteRecord', [
			'repo' => $did,
			'collection' => 'app.bsky.feed.post',
			'rkey' => $secondRkey,
		], 'alice');
		self::assertSame(200, $deleted['status'], $deleted['body']);

		$batch = $this->jsonRequest('POST', '/xrpc/com.atproto.repo.applyWrites', [
			'repo' => $did,
			'writes' => [[
				'action' => 'create',
				'collection' => 'app.bsky.feed.post',
				'rkey' => 'third',
				'value' => ['$type' => 'app.bsky.feed.post', 'text' => 'three'],
			]],
		], 'alice');
		self::assertSame(200, $batch['status'], $batch['body']);

		$blobBytes = "\x89PNG\r\n\x1a\nfirehose";
		$blob = $this->rawRequest('POST', '/xrpc/com.atproto.repo.uploadBlob', $blobBytes, 'image/png', 'alice');
		self::assertSame(200, $blob['status'], $blob['body']);

		$events = [];
		for ($index = 0; $index < 6; ++$index) {
			$events[] = $this->readFirehoseEvent($live);
		}
		$this->closeFirehose($live);

		foreach ($events as $index => $event) {
			self::assertSame('#commit', $event['$type'] ?? null);
			self::assertSame($index + 1, $event['seq'] ?? null);
			self::assertSame($did, $event['repo'] ?? null);
			self::assertNotSame('', $event['rev'] ?? '');
			self::assertInstanceOf(Bytes::class, $event['blocks'] ?? null);
		}

		self::assertSame('create', $events[0]['ops'][0]['action'] ?? null);
		self::assertSame('app.bsky.feed.post/' . $firstRkey, $events[0]['ops'][0]['path'] ?? null);
		self::assertSame('update', $events[2]['ops'][0]['action'] ?? null);
		self::assertSame('delete', $events[3]['ops'][0]['action'] ?? null);
		self::assertSame('create', $events[4]['ops'][0]['action'] ?? null);
		self::assertSame([], $events[5]['ops'] ?? null);
		self::assertCount(1, $events[5]['blobs'] ?? []);

		$car = Car::decode($events[0]['blocks']->value);
		self::assertSame($events[0]['commit']->toString(), $car->root->toString());
		self::assertArrayHasKey($first['cid'], $car->blocks);

		$replay = $this->openFirehose(1);
		$replayed = [];
		for ($index = 0; $index < 5; ++$index) {
			$replayed[] = $this->readFirehoseEvent($replay);
		}
		$this->closeFirehose($replay);

		self::assertSame([2, 3, 4, 5, 6], array_map(static fn (array $event): int => $event['seq'], $replayed));
		self::assertSame($events[1]['commit']->toString(), $replayed[0]['commit']->toString());

		$current = $this->openFirehose();
		$this->createRecord($did, 'after replay', 'alice');
		$liveAgain = $this->readFirehoseEvent($current);
		$this->closeFirehose($current);
		self::assertSame(7, $liveAgain['seq'] ?? null);
	}

	public function testFirehoseStillAcceptsTheLegacySqliteDatabaseOption(): void
	{
		$this->startFirehose();
		$client = $this->openFirehose();
		$this->closeFirehose($client);
	}

	public function testFirehoseRejectsConnectionsOverItsConfiguredLimit(): void
	{
		$this->startServer();
		$created = $this->jsonRequest('POST', '/xrpc/com.atproto.identity.createDid', [
			'handle' => 'alice.pds.example',
		], 'alice');
		self::assertSame(200, $created['status'], $created['body']);

		$this->startFirehose(true, false, null, 1);
		$first = $this->openFirehose();

		$second = @stream_socket_client('tcp://127.0.0.1:' . $this->firehosePort, $errorCode, $errorMessage, 5.0);
		self::assertIsResource($second, 'Could not open a second firehose connection: ' . $errorMessage);
		fwrite($second, "GET /xrpc/com.atproto.sync.subscribeRepos HTTP/1.1\r\n"
			. "Host: 127.0.0.1\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
			. "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\n\r\n");
		$response = '';
		while (!str_contains($response, "\r\n\r\n")) {
			$chunk = fread($second, 4096);
			if (!is_string($chunk) || $chunk === '') {
				break;
			}
			$response .= $chunk;
		}
		fclose($second);
		$this->closeFirehose($first);

		self::assertStringStartsWith('HTTP/1.1 503 Service Unavailable', $response, $response);
		self::assertStringContainsString('The firehose is at capacity.', $response);
	}

	public function testFirehoseReadsTheHostsDatabaseDsnFromEnvironment(): void
	{
		$this->startFirehose(false, true);
		$client = $this->openFirehose();
		$this->closeFirehose($client);
	}

	public function testFirehoseLoadsTheHostComposerAutoloaderWhenInstalledAsPackage(): void
	{
		$hostVendor = $this->directory . '/vendor';
		$packageBin = $hostVendor . '/alohasocial/core/bin';
		if (!mkdir($packageBin, 0o770, true) && !is_dir($packageBin)) {
			throw new RuntimeException('Could not create the simulated Composer package directory.');
		}

		$packageScript = $packageBin . '/pds-firehose';
		if (!copy(dirname(__DIR__, 2) . '/bin/pds-firehose', $packageScript)
			|| !symlink(dirname(__DIR__, 2) . '/vendor/autoload.php', $hostVendor . '/autoload.php')
		) {
			throw new RuntimeException('Could not prepare the simulated Composer package.');
		}

		$this->startFirehose(false, false, $packageScript);
		$client = $this->openFirehose();
		$this->closeFirehose($client);
	}

	public function testCarExportImportsIntoAFreshRepositoryAndBlocksAreServed(): void
	{
		$this->startServer();

		$alice = $this->jsonRequest('POST', '/xrpc/com.atproto.identity.createDid', ['handle' => 'alice.pds.example'], 'alice');
		$bob = $this->jsonRequest('POST', '/xrpc/com.atproto.identity.createDid', ['handle' => 'bob.pds.example'], 'bob');
		self::assertSame(200, $alice['status'], $alice['body']);
		self::assertSame(200, $bob['status'], $bob['body']);
		$aliceDid = $alice['json']['did'] ?? '';
		$bobDid = $bob['json']['did'] ?? '';

		$first = $this->jsonRequest('POST', '/xrpc/com.atproto.repo.putRecord', [
			'repo' => $aliceDid,
			'collection' => 'app.bsky.feed.post',
			'rkey' => 'import-one',
			'record' => ['$type' => 'app.bsky.feed.post', 'text' => 'exported'],
		], 'alice');
		$second = $this->jsonRequest('POST', '/xrpc/com.atproto.repo.putRecord', [
			'repo' => $aliceDid,
			'collection' => 'app.bsky.feed.post',
			'rkey' => 'import-two',
			'record' => ['$type' => 'app.bsky.feed.post', 'text' => 'also exported'],
		], 'alice');
		self::assertSame(200, $first['status'], $first['body']);
		self::assertSame(200, $second['status'], $second['body']);

		$export = $this->request('GET', '/xrpc/com.atproto.sync.getRepo?' . http_build_query(['did' => $aliceDid]));
		self::assertSame(200, $export['status']);
		$exportCar = Car::decode($export['body']);

		$imported = $this->rawRequest('POST', '/xrpc/com.atproto.repo.importRepo', $export['body'], 'application/vnd.ipld.car', 'bob');
		self::assertSame(200, $imported['status'], $imported['body']);
		$importJson = json_decode($imported['body'], true);
		self::assertIsArray($importJson);
		self::assertSame(2, $importJson['records'] ?? null);

		$bobFirst = $this->jsonRequest('GET', '/xrpc/com.atproto.repo.getRecord?' . http_build_query([
			'repo' => $bobDid,
			'collection' => 'app.bsky.feed.post',
			'rkey' => 'import-one',
		]));
		self::assertSame(200, $bobFirst['status'], $bobFirst['body']);
		self::assertSame($first['json']['cid'] ?? null, $bobFirst['json']['cid'] ?? null);
		self::assertSame('exported', $bobFirst['json']['value']['text'] ?? null);

		$blocks = $this->request('GET', '/xrpc/com.atproto.sync.getBlocks?' . http_build_query([
			'did' => $aliceDid,
			'cids' => $first['json']['cid'],
		]));
		self::assertSame(200, $blocks['status']);
		self::assertSame('application/vnd.ipld.car', $blocks['contentType']);
		$blockCar = Car::decode($blocks['body']);
		self::assertSame($exportCar->root->toString(), $blockCar->root->toString());
		self::assertArrayHasKey($first['json']['cid'], $blockCar->blocks);
	}

	public function testGetRepoSinceReturnsOnlyBlocksChangedAfterARevision(): void
	{
		$this->startServer();

		$created = $this->jsonRequest('POST', '/xrpc/com.atproto.identity.createDid', [
			'handle' => 'since.pds.example',
		], 'since-user');
		self::assertSame(200, $created['status'], $created['body']);
		$did = $created['json']['did'] ?? '';

		$first = $this->createRecord($did, 'unchanged', 'since-user');
		$rev = $first['commit']['rev'] ?? '';
		self::assertNotSame('', $rev);
		$second = $this->createRecord($did, 'changed', 'since-user');

		$full = $this->request('GET', '/xrpc/com.atproto.sync.getRepo?' . http_build_query(['did' => $did]));
		$diff = $this->request('GET', '/xrpc/com.atproto.sync.getRepo?' . http_build_query(['did' => $did, 'since' => $rev]));
		self::assertSame(200, $full['status']);
		self::assertSame(200, $diff['status']);

		$fullCar = Car::decode($full['body']);
		$diffCar = Car::decode($diff['body']);
		self::assertSame($fullCar->root->toString(), $diffCar->root->toString());
		self::assertArrayHasKey($first['cid'], $fullCar->blocks);
		self::assertArrayNotHasKey($first['cid'], $diffCar->blocks);
		self::assertArrayHasKey($second['cid'], $diffCar->blocks);
		self::assertArrayHasKey($diffCar->root->toString(), $diffCar->blocks);

		$same = $this->request('GET', '/xrpc/com.atproto.sync.getRepo?' . http_build_query([
			'did' => $did,
			'since' => $second['commit']['rev'] ?? '',
		]));
		self::assertSame(200, $same['status']);
		$sameCar = Car::decode($same['body']);
		self::assertSame([$sameCar->root->toString()], array_keys($sameCar->blocks));
	}

	/**
	 * @return array<string, mixed>
	 */
	private function createRecord(string $did, string $text, string $user): array
	{
		$response = $this->jsonRequest('POST', '/xrpc/com.atproto.repo.createRecord', [
			'repo' => $did,
			'collection' => 'app.bsky.feed.post',
			'record' => [
				'$type' => 'app.bsky.feed.post',
				'text' => $text,
				'createdAt' => '2026-11-15T00:00:00Z',
			],
			'rkey' => 'r' . bin2hex(random_bytes(6)),
		], $user);
		self::assertSame(200, $response['status'], $response['body']);
		self::assertSame('valid', $response['json']['validationStatus'] ?? null);

		return $response['json'];
	}

	private function repository(): PdoPdsRepository
	{
		$pdo = new PDO('sqlite:' . $this->databasePath);
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

		return new PdoPdsRepository(new PdoConnection($pdo));
	}

	/**
	 * @param array<string, mixed>|null $body
	 *
	 * @return array{status: int, body: string, contentType: string, json: array<string, mixed>}
	 */
	private function jsonRequest(string $method, string $path, ?array $body = null, ?string $user = null): array
	{
		$response = $this->request($method, $path, $body, $user);
		$decoded = json_decode($response['body'], true);
		self::assertIsArray($decoded, $response['body']);
		$response['json'] = $decoded;

		return $response;
	}

	/**
	 * @return array{status: int, body: string, contentType: string}
	 */
	private function rawRequest(string $method, string $path, string $body = '', ?string $contentType = null, ?string $user = null): array
	{
		$headers = ['Accept: application/json'];
		if ($contentType !== null) {
			$headers[] = 'Content-Type: ' . $contentType;
		}
		if ($user !== null) {
			$headers[] = 'X-PDS-Test-User: ' . $user;
		}

		$options = ['http' => [
			'method' => $method,
			'header' => implode("\r\n", $headers),
			'ignore_errors' => true,
			'timeout' => 10,
			'content' => $body,
		]];

		$responseBody = @file_get_contents($this->baseUrl . $path, false, stream_context_create($options));
		if (!is_string($responseBody)) {
			throw new RuntimeException('HTTP request failed for ' . $method . ' ' . $path);
		}

		$status = 0;
		$responseType = '';
		foreach ($http_response_header ?? [] as $line) {
			if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $matches) === 1) {
				$status = (int) $matches[1];
			}
			if (stripos($line, 'Content-Type:') === 0) {
				$responseType = trim(substr($line, strlen('Content-Type:')));
			}
		}

		return ['status' => $status, 'body' => $responseBody, 'contentType' => $responseType];
	}

	/**
	 * @param array<string, mixed>|null $body
	 *
	 * @return array{status: int, body: string, contentType: string}
	 */
	private function request(string $method, string $path, ?array $body = null, ?string $user = null): array
	{
		$headers = ['Accept: application/json'];
		if ($body !== null) {
			$headers[] = 'Content-Type: application/json';
		}
		if ($user !== null) {
			$headers[] = 'X-PDS-Test-User: ' . $user;
		}

		$options = ['http' => [
			'method' => $method,
			'header' => implode("\r\n", $headers),
			'ignore_errors' => true,
			'timeout' => 10,
		]];
		if ($body !== null) {
			$options['http']['content'] = json_encode($body, JSON_THROW_ON_ERROR);
		}

		$responseBody = @file_get_contents($this->baseUrl . $path, false, stream_context_create($options));
		if (!is_string($responseBody)) {
			throw new RuntimeException('HTTP request failed for ' . $method . ' ' . $path);
		}

		$status = 0;
		$contentType = '';
		foreach ($http_response_header ?? [] as $line) {
			if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $matches) === 1) {
				$status = (int) $matches[1];
			}
			if (stripos($line, 'Content-Type:') === 0) {
				$contentType = trim(substr($line, strlen('Content-Type:')));
			}
		}

		return ['status' => $status, 'body' => $responseBody, 'contentType' => $contentType];
	}

	private function startFirehose(
		bool $useDsn = false,
		bool $useDsnEnvironment = false,
		?string $scriptPath = null,
		int $maxClients = 256,
	): void
	{
		if (is_resource($this->firehoseProcess)) {
			return;
		}

		$socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
		if ($socket === false) {
			throw new RuntimeException('Could not reserve a firehose test port: ' . $errorMessage);
		}
		$address = stream_socket_get_name($socket, false);
		fclose($socket);
		if (!is_string($address) || !str_contains($address, ':')) {
			throw new RuntimeException('Could not determine the firehose test port.');
		}
		$this->firehosePort = (int) substr($address, strrpos($address, ':') + 1);

		$command = [
			PHP_BINARY,
			$scriptPath ?? dirname(__DIR__, 2) . '/bin/pds-firehose',
			'--host=127.0.0.1',
			'--port=' . $this->firehosePort,
			'--poll-ms=20',
			'--max-clients=' . $maxClients,
		];
		if (!$useDsnEnvironment) {
			$command[] = $useDsn
				? '--dsn=sqlite:' . $this->databasePath
				: '--database=' . $this->databasePath;
		}
		$descriptors = [
			0 => ['pipe', 'r'],
			1 => ['file', $this->firehoseLog, 'a'],
			2 => ['file', $this->firehoseLog, 'a'],
		];
		$previousDsn = getenv('PDS_DATABASE_DSN');
		if ($useDsnEnvironment) {
			putenv('PDS_DATABASE_DSN=sqlite:' . $this->databasePath);
		}
		$process = proc_open($command, $descriptors, $pipes, $this->directory);
		if ($useDsnEnvironment) {
			putenv($previousDsn === false ? 'PDS_DATABASE_DSN' : 'PDS_DATABASE_DSN=' . $previousDsn);
		}
		if (!is_resource($process)) {
			throw new RuntimeException('Could not start the PDS firehose server.');
		}

		$this->firehoseProcess = $process;
		if (isset($pipes[0]) && is_resource($pipes[0])) {
			fclose($pipes[0]);
		}

		$deadline = microtime(true) + 8.0;
		while (microtime(true) < $deadline) {
			$status = proc_get_status($process);
			if (!$status['running']) {
				throw new RuntimeException('The PDS firehose server exited: ' . $this->firehoseLogTail());
			}

			$probe = @stream_socket_client('tcp://127.0.0.1:' . $this->firehosePort, $errorCode, $errorMessage, 0.2);
			if (is_resource($probe)) {
				fclose($probe);
				usleep(25_000);

				return;
			}

			usleep(50_000);
		}

		$this->stopFirehose();

		throw new RuntimeException('The PDS firehose server did not become ready: ' . $this->firehoseLogTail());
	}

	/**
	 * @return array{stream: resource, buffer: string}
	 */
	private function openFirehose(?int $cursor = null): array
	{
		$stream = @stream_socket_client('tcp://127.0.0.1:' . $this->firehosePort, $errorCode, $errorMessage, 5.0);
		if (!is_resource($stream)) {
			throw new RuntimeException('Could not connect to the firehose: ' . $errorMessage);
		}
		stream_set_timeout($stream, 5);

		$key = base64_encode(random_bytes(16));
		$target = '/xrpc/com.atproto.sync.subscribeRepos'
			. ($cursor === null ? '' : '?cursor=' . $cursor);
		$request = "GET {$target} HTTP/1.1\r\n"
			. "Host: 127.0.0.1\r\n"
			. "Upgrade: websocket\r\n"
			. "Connection: Upgrade\r\n"
			. "Sec-WebSocket-Key: {$key}\r\n"
			. "Sec-WebSocket-Version: 13\r\n\r\n";
		if (fwrite($stream, $request) === false) {
			throw new RuntimeException('Could not write the firehose handshake.');
		}

		$response = '';
		while (!str_contains($response, "\r\n\r\n")) {
			$chunk = fread($stream, 4096);
			if (!is_string($chunk) || $chunk === '') {
				throw new RuntimeException('The firehose closed during the handshake: ' . $response);
			}
			$response .= $chunk;
		}

		self::assertStringContainsString('101 Switching Protocols', $response, $response);
		$end = strpos($response, "\r\n\r\n");

		return [
			'stream' => $stream,
			'buffer' => substr($response, (int) $end + 4),
		];
	}

	/**
	 * @param array{stream: resource, buffer: string} $client
	 *
	 * @return array<string, mixed>
	 */
	private function readFirehoseEvent(array &$client): array
	{
		$deadline = microtime(true) + 5.0;
		while (microtime(true) < $deadline) {
			$frame = $this->takeWebSocketFrame($client['buffer']);
			if ($frame !== null) {
				[$opcode, $payload] = $frame;
				if ($opcode === 0x9) {
					continue;
				}
				if ($opcode !== 0x2) {
					throw new RuntimeException('The firehose sent a non-binary frame.');
				}

				$decoded = DagCbor::decode($payload);
				self::assertIsArray($decoded);

				return $decoded;
			}

			$chunk = fread($client['stream'], 8192);
			if (!is_string($chunk) || $chunk === '') {
				$meta = stream_get_meta_data($client['stream']);
				if (($meta['timed_out'] ?? false) === false && feof($client['stream'])) {
					throw new RuntimeException('The firehose closed before sending an event.');
				}
				continue;
			}

			$client['buffer'] .= $chunk;
		}

		throw new RuntimeException('Timed out waiting for a firehose event.');
	}

	/**
	 * @return array{0: int, 1: string}|null
	 */
	private function takeWebSocketFrame(string &$buffer): ?array
	{
		if (strlen($buffer) < 2) {
			return null;
		}

		$first = ord($buffer[0]);
		$second = ord($buffer[1]);
		$opcode = $first & 0x0f;
		$masked = ($second & 0x80) !== 0;
		$length = $second & 0x7f;
		$offset = 2;
		if ($length === 126) {
			if (strlen($buffer) < 4) {
				return null;
			}
			$length = unpack('n', substr($buffer, 2, 2))[1];
			$offset = 4;
		} elseif ($length === 127) {
			if (strlen($buffer) < 10) {
				return null;
			}
			$length = (int) (unpack('J', substr($buffer, 2, 8))[1] ?? 0);
			$offset = 10;
		}

		$maskLength = $masked ? 4 : 0;
		if (strlen($buffer) < $offset + $maskLength + $length) {
			return null;
		}

		$payload = substr($buffer, $offset + $maskLength, $length);
		if ($masked) {
			$mask = substr($buffer, $offset, 4);
			$decoded = '';
			for ($index = 0; $index < $length; ++$index) {
				$decoded .= $payload[$index] ^ $mask[$index % 4];
			}
			$payload = $decoded;
		}

		$buffer = substr($buffer, $offset + $maskLength + $length);

		return [$opcode, $payload];
	}

	/**
	 * @param array{stream: resource, buffer: string} $client
	 */
	private function closeFirehose(array $client): void
	{
		if (is_resource($client['stream'])) {
			fclose($client['stream']);
		}
	}

	private function stopFirehose(): void
	{
		if (is_resource($this->firehoseProcess)) {
			proc_terminate($this->firehoseProcess);
			proc_close($this->firehoseProcess);
			$this->firehoseProcess = null;
		}
	}

	private function firehoseLogTail(): string
	{
		$log = @file_get_contents($this->firehoseLog);

		return is_string($log) ? substr($log, -3000) : '';
	}

	private function startServer(): void
	{
		if (is_resource($this->process)) {
			return;
		}

		$socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
		if ($socket === false) {
			throw new RuntimeException('Could not reserve a PDS test port: ' . $errorMessage);
		}
		$address = stream_socket_get_name($socket, false);
		fclose($socket);
		if (!is_string($address) || !str_contains($address, ':')) {
			throw new RuntimeException('Could not determine the PDS test port.');
		}
		$port = (int) substr($address, strrpos($address, ':') + 1);
		$this->baseUrl = 'http://127.0.0.1:' . $port;

		$command = [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/../Support/pds-router.php'];
		$descriptors = [
			0 => ['pipe', 'r'],
			1 => ['file', $this->log, 'a'],
			2 => ['file', $this->log, 'a'],
		];
		$process = proc_open($command, $descriptors, $pipes, $this->directory, [
			'PDS_TEST_DB' => $this->databasePath,
			'PDS_TEST_SECRET' => $this->secret,
		]);
		if (!is_resource($process)) {
			throw new RuntimeException('Could not start the PDS HTTP test server.');
		}

		$this->process = $process;
		$this->pipes = array_values($pipes);
		if (isset($this->pipes[0]) && is_resource($this->pipes[0])) {
			fclose($this->pipes[0]);
		}

		$deadline = microtime(true) + 8.0;
		while (microtime(true) < $deadline) {
			$status = proc_get_status($process);
			if (!$status['running']) {
				throw new RuntimeException('The PDS HTTP test server exited: ' . $this->logTail());
			}

			$context = stream_context_create(['http' => ['timeout' => 0.2]]);
			$health = @file_get_contents($this->baseUrl . '/health', false, $context);
			if ($health === 'ok') {
				return;
			}

			usleep(50_000);
		}

		$this->stopServer();

		throw new RuntimeException('The PDS HTTP test server did not become ready: ' . $this->logTail());
	}

	private function stopServer(): void
	{
		if (is_resource($this->process)) {
			proc_terminate($this->process);
			proc_close($this->process);
			$this->process = null;
		}

		foreach ($this->pipes as $pipe) {
			if (is_resource($pipe)) {
				fclose($pipe);
			}
		}
		$this->pipes = [];
	}

	private function logTail(): string
	{
		$log = @file_get_contents($this->log);

		return is_string($log) ? substr($log, -3000) : '';
	}

	private function remove(string $path): void
	{
		if (!is_dir($path)) {
			return;
		}

		foreach (scandir($path) ?: [] as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}

			$full = $path . '/' . $entry;
			if (is_dir($full)) {
				$this->remove($full);
			} else {
				@unlink($full);
			}
		}

		@rmdir($path);
	}
}
