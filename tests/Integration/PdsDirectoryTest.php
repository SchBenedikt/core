<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Integration;

use AlohaSocial\Core\Domain\Atproto\Pds\InMemoryPdsRepository;
use AlohaSocial\Core\Domain\Atproto\Pds\NativePdsService;
use AlohaSocial\Core\Domain\Atproto\Pds\PdsDirectory;
use AlohaSocial\Core\Domain\Atproto\Pds\PdsError;
use AlohaSocial\Core\Domain\Atproto\Pds\PdsPlcOperation;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Platform\Http\HttpResponse;
use AlohaSocial\Core\Protocol\Atproto\PlcDirectoryClient;
use AlohaSocial\Core\Security\OpenSslSecretBox;
use AlohaSocial\Core\Tests\Support\FakeHttpClient;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PdsDirectoryTest extends TestCase
{
	public function testIdentityCreationRotationAndRetryPublishTheSignedPlcOperation(): void
	{
		$directory = new RecordingPdsDirectory();
		$service = $this->service($directory);

		$identity = $service->createIdentity('alice');
		self::assertMatchesRegularExpression('/^did:plc:[a-z2-7]{24}$/', $identity['did']);
		self::assertCount(1, $directory->submitted);
		self::assertSame(1, $directory->submitted[0]->seq);

		$rotated = $service->rotateKeys('alice');
		self::assertSame($identity['did'], $rotated['did']);
		self::assertCount(2, $directory->submitted);
		self::assertSame(2, $directory->submitted[1]->seq);
		self::assertNotSame(
			$directory->submitted[0]->cid->toString(),
			$directory->submitted[1]->cid->toString(),
		);

		$retry = $service->submitPlcOperation('alice');
		self::assertCount(3, $directory->submitted);
		self::assertSame($directory->submitted[1]->cid->toString(), $retry['cid']);
		self::assertSame($identity['did'], $retry['did']);
	}

	public function testTheHttpDirectoryClientPostsTheSignedJsonOperation(): void
	{
		$http = new FakeHttpClient();
		$directory = new PlcDirectoryClient($http, 'https://plc.example/');
		$service = $this->service($directory);

		$identity = $service->createIdentity('alice');
		self::assertCount(1, $http->requests);
		self::assertSame('POST', $http->requests[0]['method']);
		self::assertStringContainsString('https://plc.example/did%3Aplc%3A', $http->requests[0]['url']);
		self::assertStringContainsString(rawurlencode($identity['did']), $http->requests[0]['url']);

		$body = json_decode($http->requests[0]['body'], true);
		self::assertIsArray($body);
		self::assertSame('plc_operation', $body['type'] ?? null);
		self::assertNull($body['prev'] ?? null);
		self::assertSame('https://pds.example', $body['services']['atproto_pds']['endpoint'] ?? null);
		self::assertIsString($body['sig'] ?? null);
		self::assertStringNotContainsString('+', $body['sig']);
		self::assertStringNotContainsString('/', $body['sig']);
	}

	public function testRetryingWithoutAConfiguredDirectoryIsExplicitlyUnavailable(): void
	{
		$service = $this->service(null);
		$service->createIdentity('alice');

		try {
			$service->submitPlcOperation('alice');
			self::fail('A missing PLC transport must not pretend to submit.');
		} catch (PdsError $error) {
			self::assertSame(503, $error->status);
			self::assertSame('PlcDirectoryNotConfigured', $error->error);
		}
	}

	public function testANonSuccessfulDirectoryStatusIsReportedAsAnUpstreamFailure(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse(new HttpResponse(409, [], 'operation conflict'));
		$service = $this->service(new PlcDirectoryClient($http, 'https://plc.example'));

		try {
			$service->createIdentity('alice');
			self::fail('A rejected PLC operation must fail the identity operation.');
		} catch (PdsError $error) {
			self::assertSame(502, $error->status);
			self::assertSame('UpstreamFailure', $error->error);
		}
	}

	public function testARejectedDirectoryOperationIsAStableUpstreamFailure(): void
	{
		$directory = new RecordingPdsDirectory();
		$directory->fail = true;
		$service = $this->service($directory);

		try {
			$service->createIdentity('alice');
			self::fail('A rejected PLC operation must fail the identity operation.');
		} catch (PdsError $error) {
			self::assertSame(502, $error->status);
			self::assertSame('UpstreamFailure', $error->error);
		}

		self::assertCount(1, $directory->submitted);
		self::assertSame(
			$directory->submitted[0]->did,
			$service->resolveDid($directory->submitted[0]->did)['id'] ?? null,
		);
	}

	private function service(?PdsDirectory $directory): NativePdsService
	{
		$config = new ArrayConfig(['social' => [
			'atproto.pds.enabled' => 'true',
			'atproto.pds.public_url' => 'https://pds.example',
			'atproto.pds.handle_domain' => 'pds.example',
		]]);

		return new NativePdsService(
			$config,
			new InMemoryPdsRepository(),
			OpenSslSecretBox::fromBase64Key(OpenSslSecretBox::generateKey()),
			new FrozenClock(new DateTimeImmutable('2026-11-16T00:00:00Z')),
			null,
			$directory,
		);
	}
}

final class RecordingPdsDirectory implements PdsDirectory
{
	/** @var list<PdsPlcOperation> */
	public array $submitted = [];

	public bool $fail = false;

	public function submit(PdsPlcOperation $operation): void
	{
		$this->submitted[] = $operation;
		if ($this->fail) {
			throw new RuntimeException('PLC directory unavailable.');
		}
	}
}
