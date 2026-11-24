<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Service;

use AlohaSocial\Core\Domain\Atproto\AtprotoAccount;
use AlohaSocial\Core\Domain\Atproto\AtprotoFollow;
use AlohaSocial\Core\Domain\Atproto\AtprotoFollowRecord;
use AlohaSocial\Core\Domain\Atproto\AtprotoFollowRepository;
use AlohaSocial\Core\Domain\Atproto\InMemoryAtprotoAccountResolver;
use AlohaSocial\Core\Domain\Atproto\InMemoryAtprotoFollowRepository;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Platform\Http\HttpClientException;
use AlohaSocial\Core\Platform\Http\HttpResponse;
use AlohaSocial\Core\Protocol\Atproto\XrpcAtprotoPublisher;
use AlohaSocial\Core\Protocol\Atproto\XrpcAtprotoRecordResolver;
use AlohaSocial\Core\Service\Atproto\AtprotoFollowException;
use AlohaSocial\Core\Service\Atproto\AtprotoFollowService;
use AlohaSocial\Core\Service\Atproto\AtprotoPublishDisabledException;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Core\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class AtprotoFollowServiceTest extends TestCase
{
	private const ACCOUNT_DID = 'did:plc:alice';
	private const SUBJECT_DID = 'did:plc:bob';
	private const PDS = 'https://pds.example';

	private FakeHttpClient $http;
	private InMemoryAtprotoFollowRepository $follows;
	private AtprotoFollowService $service;

	protected function setUp(): void
	{
		$this->http = new FakeHttpClient();
		$this->follows = new InMemoryAtprotoFollowRepository();
		$this->service = $this->service(self::ACCOUNT_DID);
	}

	public function testFollowWritesTheRecordPersistsItsCidAndIsIdempotent(): void
	{
		$this->queuePublishedAndResolvedRecord();
		$follow = $this->service->follow('alice', self::SUBJECT_DID);

		self::assertSame('active', $follow->state);
		self::assertSame('bafy-follow', $follow->cid);
		self::assertSame('at://' . self::ACCOUNT_DID . '/app.bsky.graph.follow/' . $follow->rkey, $follow->uri);
		self::assertCount(2, $this->http->requests);
		$write = json_decode($this->http->requests[0]['body'], true, 64, JSON_THROW_ON_ERROR);
		self::assertSame('app.bsky.graph.follow', $write['collection']);
		self::assertSame(self::SUBJECT_DID, $write['record']['subject']);

		self::assertSame($follow, $this->service->follow('alice', self::SUBJECT_DID));
		self::assertCount(2, $this->http->requests, 'An already active follow must not write again.');
	}

	public function testFailedWriteCanRetryWithTheSameRecordKey(): void
	{
		$this->http->queueTransportFailure(new HttpClientException('connection reset after server accepted the request'));
		try {
			$this->service->follow('alice', self::SUBJECT_DID);
			self::fail('The failed transport should be reported.');
		} catch (\AlohaSocial\Core\Domain\Atproto\AtprotoPublishFailedException) {
		}
		$pending = $this->follows->findForSubject('alice', self::SUBJECT_DID);
		self::assertNotNull($pending);
		self::assertSame('pending', $pending->state);

		$this->queuePublishedAndResolvedRecord();
		$active = $this->service->follow('alice', self::SUBJECT_DID);
		self::assertSame($pending->rkey, $active->rkey);
		self::assertSame('active', $active->state);
	}

	public function testConcurrentInsertRaceResumesTheWinningPendingRow(): void
	{
		$inner = new InMemoryAtprotoFollowRepository();
		$repository = new class($inner) implements AtprotoFollowRepository {
			private bool $firstInsert = true;

			public function __construct(private readonly InMemoryAtprotoFollowRepository $inner)
			{
			}

			public function findForSubject(string $localUserId, string $subjectDid): ?AtprotoFollow
			{
				return $this->inner->findForSubject($localUserId, $subjectDid);
			}

			public function forUser(string $localUserId): array
			{
				return $this->inner->forUser($localUserId);
			}

			public function save(AtprotoFollow $follow): void
			{
				$this->inner->save($follow);
				if ($this->firstInsert) {
					$this->firstInsert = false;
					throw new DatabaseException('Simulated duplicate pending row.');
				}
			}

			public function remove(string $localUserId, string $subjectDid): bool
			{
				return $this->inner->remove($localUserId, $subjectDid);
			}
		};

		$this->queuePublishedAndResolvedRecord();
		$follow = $this->serviceWithRepository($repository)->follow('alice', self::SUBJECT_DID);
		self::assertSame(AtprotoFollow::ACTIVE, $follow->state);
		self::assertSame(
			AtprotoFollowRecord::rkey(Time::parse('2026-11-20T10:00:00Z'), 'alice', self::ACCOUNT_DID, self::SUBJECT_DID),
			$follow->rkey,
		);
	}

	public function testUnfollowDeletesTheStoredRecordAndIsIdempotent(): void
	{
		$this->queuePublishedAndResolvedRecord();
		$follow = $this->service->follow('alice', self::SUBJECT_DID);
		$this->http->queueResponse(new HttpResponse(404, [], '{}'));

		$this->service->unfollow('alice', self::SUBJECT_DID);
		self::assertNull($this->follows->findForSubject('alice', self::SUBJECT_DID));
		$delete = json_decode($this->http->requests[2]['body'], true, 64, JSON_THROW_ON_ERROR);
		self::assertSame('app.bsky.graph.follow', $delete['collection']);
		self::assertSame($follow->rkey, $delete['rkey']);

		$this->service->unfollow('alice', self::SUBJECT_DID);
		self::assertCount(3, $this->http->requests);
	}

	public function testDifferentLinkedDidCannotDeleteTheOldAccountsRecord(): void
	{
		$this->queuePublishedAndResolvedRecord();
		$this->service->follow('alice', self::SUBJECT_DID);
		$service = $this->service('did:plc:replacement');

		try {
			$service->unfollow('alice', self::SUBJECT_DID);
			self::fail('A replacement DID must not delete the previous account record.');
		} catch (AtprotoFollowException $exception) {
			self::assertSame(AtprotoFollowException::ACCOUNT_CHANGED, $exception->reason);
		}
		self::assertCount(2, $this->http->requests);
	}

	public function testDisabledFeatureRefusesToWrite(): void
	{
		$service = $this->service(self::ACCOUNT_DID, false);
		$this->expectException(AtprotoPublishDisabledException::class);
		$service->follow('alice', self::SUBJECT_DID);
	}

	private function service(string $accountDid, bool $enabled = true): AtprotoFollowService
	{
		return $this->serviceWithRepository($this->follows, $accountDid, $enabled);
	}

	private function serviceWithRepository(
		AtprotoFollowRepository $follows,
		string $accountDid = self::ACCOUNT_DID,
		bool $enabled = true,
	): AtprotoFollowService {
		$publisher = new XrpcAtprotoPublisher($this->http);
		return new AtprotoFollowService(
			new ArrayConfig(['social' => ['atproto.publish' => $enabled ? 'true' : 'false']]),
			$follows,
			new InMemoryAtprotoAccountResolver([
				'alice' => new AtprotoAccount($accountDid, 'alice.example', self::PDS, 'secret-token'),
			]),
			$publisher,
			$publisher,
			new XrpcAtprotoRecordResolver($this->http),
			new FrozenClock(Time::parse('2026-11-20T10:00:00Z')),
		);
	}

	private function queuePublishedAndResolvedRecord(): void
	{
		$time = Time::parse('2026-11-20T10:00:00Z');
		$rkey = AtprotoFollowRecord::rkey($time, 'alice', self::ACCOUNT_DID, self::SUBJECT_DID);
		$uri = 'at://' . self::ACCOUNT_DID . '/' . AtprotoFollowRecord::COLLECTION . '/' . $rkey;
		$this->http->queueResponse(new HttpResponse(200, [], json_encode([
			'uri' => $uri,
			'cid' => 'bafy-follow',
		], JSON_THROW_ON_ERROR)));
		$this->http->queueResponse(new HttpResponse(200, [], json_encode([
			'uri' => $uri,
			'cid' => 'bafy-follow',
			'value' => AtprotoFollowRecord::create(self::SUBJECT_DID, $time),
		], JSON_THROW_ON_ERROR)));
	}
}
