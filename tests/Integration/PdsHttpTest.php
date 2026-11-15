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
use AlohaSocial\Core\Protocol\Atproto\Repository\Car;
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

	protected function setUp(): void
	{
		$this->directory = sys_get_temp_dir() . '/alohasocial-pds-' . bin2hex(random_bytes(8));
		if (!mkdir($this->directory, 0o770, true) && !is_dir($this->directory)) {
			throw new RuntimeException('Could not create the PDS test directory.');
		}

		$this->databasePath = $this->directory . '/pds.sqlite';
		$this->secret = OpenSslSecretBox::generateKey();
		$this->log = $this->directory . '/server.log';
	}

	protected function tearDown(): void
	{
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
