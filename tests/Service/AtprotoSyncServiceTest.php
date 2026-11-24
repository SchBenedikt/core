<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Service;

use AlohaSocial\Core\Domain\Atproto\AtprotoAccountStatus;
use AlohaSocial\Core\Domain\Atproto\AtprotoEvent;
use AlohaSocial\Core\Domain\Atproto\AtprotoEventKind;
use AlohaSocial\Core\Domain\Atproto\AtprotoEventPage;
use AlohaSocial\Core\Domain\Atproto\AtprotoLabel;
use AlohaSocial\Core\Domain\Atproto\AtprotoRecordOperation;
use AlohaSocial\Core\Domain\Atproto\AtprotoSourceStatus;
use AlohaSocial\Core\Domain\Atproto\InMemoryAtprotoInboundRepository;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Service\Atproto\AtprotoSyncException;
use AlohaSocial\Core\Service\Atproto\AtprotoSyncService;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Core\Tests\Support\DenyingAtprotoModerationPolicy;
use AlohaSocial\Core\Tests\Support\FakeAtprotoEventSource;
use AlohaSocial\Core\Tests\Support\FixedAtprotoBlobUrlBuilder;
use DateTimeImmutable;
use RuntimeException;
use PHPUnit\Framework\TestCase;

final class AtprotoSyncServiceTest extends TestCase
{
	private const DID = 'did:plc:alice';

	private FrozenClock $clock;

	private InMemoryAtprotoInboundRepository $store;

	private FakeAtprotoEventSource $source;

	protected function setUp(): void
	{
		$this->clock = new FrozenClock($this->at('2026-11-15T12:00:00Z'));
		$this->store = new InMemoryAtprotoInboundRepository();
		$this->source = new FakeAtprotoEventSource('relay', []);
	}

	public function testARecordCommitIsStoredAndProjectedWithAHandleReplyAndImage(): void
	{
		$replyUri = $this->uri('a');
		$this->source->append([new AtprotoEventPage([
			$this->accountEvent(1, 'alice.test'),
			$this->postEvent('a', 2, $this->post('hello from bsky', '2026-11-15T10:00:00Z')),
			$this->postEvent('b', 3, $this->post(
				'with an image',
				'2026-11-15T11:00:00Z',
				[
					'root' => ['uri' => $replyUri, 'cid' => 'cid-a'],
					'parent' => ['uri' => $replyUri, 'cid' => 'cid-a'],
				],
				[
					'$type' => 'app.bsky.embed.images',
					'images' => [[
						'image' => [
							'$type' => 'blob',
							'ref' => ['$link' => 'bafyimage'],
							'mimeType' => 'image/jpeg',
							'size' => 123,
						],
						'alt' => 'a blue square',
						'aspectRatio' => ['width' => 10, 'height' => 20],
					]],
				],
			)),
		], 'cursor-1')]);

		$service = $this->service(blobs: new FixedAtprotoBlobUrlBuilder());
		$result = $service->sync(50);

		self::assertSame(3, $result->applied);
		self::assertSame('cursor-1', $result->checkpoint->cursor);
		self::assertSame(3, $result->checkpoint->lastSequence);
		self::assertTrue($result->status->isFresh());

		$page = $service->feed(self::DID, 10);
		self::assertCount(2, $page->posts);
		self::assertSame('with an image', $page->posts[0]->text);
		self::assertSame('alice.test', $page->posts[0]->handle);
		self::assertSame($replyUri, $page->posts[0]->replyToUri);
		self::assertSame('https://cdn.example/' . rawurlencode(self::DID) . '/bafyimage', $page->posts[0]->images[0]['url']);
		self::assertSame('a blue square', $page->posts[0]->images[0]['alt']);
		self::assertSame(10, $page->posts[0]->images[0]['width']);
	}

	public function testRepeatedAndOutOfOrderEventsAreIdempotent(): void
	{
		$service = $this->service();
		$event = $this->postEvent('a', 1, $this->post('one', '2026-11-15T10:00:00Z'));
		$this->source->append([new AtprotoEventPage([$event], null)]);

		self::assertSame(1, $service->sync()->applied);
		self::assertSame(0, $service->sync()->applied);
		self::assertSame(1, $this->store->record('relay', $event->uri())?->sequence);

		$this->source->append([new AtprotoEventPage([
			$this->postEvent('b', 3, $this->post('three', '2026-11-15T12:00:00Z')),
			$this->postEvent('c', 2, $this->post('two', '2026-11-15T11:00:00Z')),
		], null)]);

		$result = $service->sync();
		self::assertSame(1, $result->applied);
		self::assertSame(1, $result->skipped);
		self::assertNotNull($this->store->record('relay', $this->uri('b')));
		self::assertNull($this->store->record('relay', $this->uri('c')));
	}

	public function testASequenceGapRequestsReconciliationAndBackfillClearsIt(): void
	{
		$service = $this->service();
		$this->source->append([new AtprotoEventPage([
			$this->postEvent('a', 1, $this->post('one', '2026-11-15T10:00:00Z')),
		], null)]);
		$service->sync();

		$this->source->append([new AtprotoEventPage([
			$this->postEvent('b', 3, $this->post('three', '2026-11-15T12:00:00Z')),
		], 'cursor-3', true)]);
		$gapped = $service->sync();
		self::assertTrue($gapped->reconcileRequired());
		self::assertNotNull($this->store->record('relay', $this->uri('b')));

		$this->source->append([new AtprotoEventPage([], null, false)]);
		$backfill = $service->backfill();

		self::assertTrue($backfill->reachedEnd);
		self::assertFalse($backfill->reconcileRequired());
	}

	public function testDeleteBeforeCreateLeavesATombstoneAndALaterCreateRestoresThePost(): void
	{
		$service = $this->service();
		$this->source->append([new AtprotoEventPage([
			$this->deleteEvent('a', 1),
		], null)]);
		$service->sync();

		self::assertTrue($this->store->record('relay', $this->uri('a'))?->deleted);
		self::assertSame([], $service->feed(self::DID)->posts);

		$this->source->append([new AtprotoEventPage([
			$this->postEvent('a', 2, $this->post('restored', '2026-11-15T11:00:00Z')),
		], null)]);
		$service->sync();

		self::assertSame('restored', $service->feed(self::DID)->posts[0]->text);
	}

	public function testARepeatedDeleteIsAnIdempotentNoOp(): void
	{
		$service = $this->service();
		$this->source->append([new AtprotoEventPage([$this->deleteEvent('a', 1)], null)]);
		self::assertSame(1, $service->sync()->applied);

		$this->source->append([new AtprotoEventPage([$this->deleteEvent('a', 1)], null)]);
		$second = $service->sync();

		self::assertSame(0, $second->applied);
		self::assertSame(1, $second->skipped);
		self::assertTrue($this->store->record('relay', $this->uri('a'))?->deleted);
	}

	public function testAccountTakedownPurgesRecordsAndBlocksLaterRecordEvents(): void
	{
		$service = $this->service();
		$this->source->append([new AtprotoEventPage([
			$this->accountEvent(1, 'alice.test'),
			$this->postEvent('a', 2, $this->post('visible', '2026-11-15T10:00:00Z')),
		], null)]);
		$service->sync();
		self::assertCount(1, $service->feed(self::DID)->posts);

		$this->source->append([new AtprotoEventPage([
			$this->accountEvent(3, null, false, AtprotoAccountStatus::Takedown),
			$this->postEvent('b', 4, $this->post('should not appear', '2026-11-15T11:00:00Z')),
		], null)]);
		$result = $service->sync();

		self::assertSame(1, $result->deleted);
		self::assertSame([], $service->feed(self::DID)->posts);
		self::assertNull($this->store->record('relay', $this->uri('b')));
		self::assertFalse($this->store->account('relay', self::DID)?->active);
	}

	public function testAHandleCannotBeReboundToAnotherDID(): void
	{
		$this->source->append([new AtprotoEventPage([
			$this->accountEvent(1, 'alice.test'),
			new AtprotoEvent(
				'relay',
				AtprotoEventKind::Account,
				'did:plc:bob',
				$this->at('2026-11-15T10:01:00Z'),
				sequence: 2,
				active: true,
				accountStatus: AtprotoAccountStatus::Active,
				handle: 'alice.test',
			),
		], null)]);

		$this->expectException(AtprotoSyncException::class);
		$this->service()->sync();
	}

	public function testABlockedLabelerAndBlockedAuthorAreSkipped(): void
	{
		$policy = new DenyingAtprotoModerationPolicy([], ['did:plc:bad-labeler']);
		$service = $this->service($policy);

		$this->source->append([new AtprotoEventPage([
			new AtprotoEvent(
				'relay',
				AtprotoEventKind::Record,
				self::DID,
				$this->at('2026-11-15T10:00:00Z'),
				operation: AtprotoRecordOperation::Create,
				collection: 'app.bsky.feed.post',
				rkey: 'a',
				cid: 'cid-a',
				rev: '3ka',
				sequence: 1,
				record: $this->post('labelled', '2026-11-15T10:00:00Z'),
				labels: [new AtprotoLabel('spam', 'did:plc:bad-labeler')],
			),
		], null)]);

		$result = $service->sync();

		self::assertSame(0, $result->applied);
		self::assertSame(1, $result->skipped);
		self::assertNull($this->store->record('relay', $this->uri('a')));
	}

	public function testANegatedLabelFromABlockedLabelerDoesNotBypassPolicy(): void
	{
		$service = $this->service(new DenyingAtprotoModerationPolicy([], ['did:plc:bad-labeler']));
		$event = $this->postEvent('negated', 1, $this->post('hidden', '2026-11-15T10:00:00Z'));
		$event = new AtprotoEvent(
			$event->source, $event->kind, $event->did, $event->receivedAt,
			operation: $event->operation, collection: $event->collection, rkey: $event->rkey,
			cid: $event->cid, rev: $event->rev, sequence: $event->sequence,
			record: $event->record, labels: [new AtprotoLabel('spam', 'did:plc:bad-labeler', true)],
		);
		$this->source->append([new AtprotoEventPage([$event], null)]);

		$result = $service->sync();

		self::assertSame(1, $result->skipped);
		self::assertNull($this->store->record('relay', $this->uri('negated')));
	}

	public function testBlockedLabelUpdateTombstonesPreviouslyCachedRecord(): void
	{
		$service = $this->service();
		$this->source->append([new AtprotoEventPage([
			$this->postEvent('a', 1, $this->post('visible', '2026-11-15T10:00:00Z')),
		], null)]);
		$service->sync();
		self::assertCount(1, $service->feed(self::DID)->posts);

		$update = $this->postEvent('a', 2, $this->post('blocked update', '2026-11-15T11:00:00Z'));
		$update = new AtprotoEvent(
			$update->source, $update->kind, $update->did, $this->at('2026-11-15T11:00:00Z'),
			operation: AtprotoRecordOperation::Update, collection: $update->collection, rkey: $update->rkey,
			cid: 'cid-a2', rev: '3ka2', sequence: 2, record: $update->record,
			labels: [new AtprotoLabel('spam', 'did:plc:bad-labeler')],
		);
		$this->source->append([new AtprotoEventPage([$update], null)]);
		$result = $this->service(new DenyingAtprotoModerationPolicy([], ['did:plc:bad-labeler']))->sync();

		self::assertSame(1, $result->deleted);
		self::assertTrue($this->store->record('relay', $this->uri('a'))?->deleted);
		self::assertSame([], $service->feed(self::DID)->posts);
	}

	public function testFeedHidesCachedPostsFromCurrentlyBlockedAuthor(): void
	{
		$service = $this->service();
		$this->source->append([new AtprotoEventPage([
			$this->postEvent('a', 1, $this->post('cached', '2026-11-15T10:00:00Z')),
		], null)]);
		$service->sync();

		$page = $this->service(new DenyingAtprotoModerationPolicy([self::DID]))->feed(self::DID);

		self::assertSame([], $page->posts);
	}

	public function testAMaliciousExternalEmbedIsSkippedWithoutStoringTheRecord(): void
	{
		$service = $this->service();
		$this->source->append([new AtprotoEventPage([
			$this->postEvent('a', 1, $this->post(
				'look',
				'2026-11-15T10:00:00Z',
				null,
				[
					'$type' => 'app.bsky.embed.external',
					'external' => [
						'uri' => 'javascript:alert(1)',
						'title' => 'bad',
						'description' => 'bad',
					],
				],
			)),
		], null)]);

		$result = $service->sync();

		self::assertSame(1, $result->skipped);
		self::assertNull($this->store->record('relay', $this->uri('a')));
	}

	public function testASourceFailureMarksCachedPostsStaleWithoutHidingThem(): void
	{
		$service = $this->service();
		$this->source->append([new AtprotoEventPage([
			$this->postEvent('a', 1, $this->post('cached', '2026-11-15T10:00:00Z')),
		], null)]);
		$service->sync();

		$this->source->failure = new RuntimeException('relay unavailable');
		try {
			$service->sync();
			self::fail('A failed source must throw.');
		} catch (AtprotoSyncException $exception) {
			self::assertSame(AtprotoSyncException::SOURCE_UNAVAILABLE, $exception->reason);
		}

		$status = $service->status();
		self::assertTrue($status->stale);
		self::assertSame('relay unavailable', $status->detail);

		$page = $service->feed(self::DID);
		self::assertTrue($page->status->stale);
		self::assertSame('cached', $page->posts[0]->text);
	}

	public function testStatusReportsANonLiveSourceHealth(): void
	{
		$service = $this->service();
		$this->source->sourceStatus = AtprotoSourceStatus::unavailable(
			'relay',
			$this->at('2026-11-15T11:59:00Z'),
			'maintenance',
		);

		$status = $service->status();

		self::assertSame(AtprotoSourceStatus::UNAVAILABLE, $status->label);
		self::assertSame('maintenance', $status->detail);
	}

	public function testBackfillSkipsPostsOlderThanItsRetentionWindowAndStopsAtTheEnd(): void
	{
		$service = $this->service();
		$this->source->append([
			new AtprotoEventPage([
				$this->postEvent('old', 1, $this->post('old', '2026-10-01T10:00:00Z')),
				$this->postEvent('new', 2, $this->post('new', '2026-11-14T10:00:00Z')),
			], 'cursor-2', true),
			new AtprotoEventPage([], null, false),
		]);

		$result = $service->backfill(10, 50, 30);

		self::assertSame(1, $result->applied);
		self::assertSame(1, $result->skipped);
		self::assertSame(2, $result->pages);
		self::assertTrue($result->reachedEnd);
		self::assertSame(['new'], array_map(
			static fn ($post): string => $post->text,
			$service->feed(self::DID)->posts,
		));
	}

	public function testFeedPaginationUsesAStableOpaqueCursor(): void
	{
		$service = $this->service();
		$this->source->append([new AtprotoEventPage([
			$this->postEvent('a', 1, $this->post('one', '2026-11-15T10:00:00Z')),
			$this->postEvent('b', 2, $this->post('two', '2026-11-15T11:00:00Z')),
			$this->postEvent('c', 3, $this->post('three', '2026-11-15T12:00:00Z')),
		], null)]);
		$service->sync();

		$first = $service->feed(self::DID, 2);
		self::assertSame(['three', 'two'], array_map(static fn ($post): string => $post->text, $first->posts));
		self::assertNotNull($first->nextCursor);

		$second = $service->feed(self::DID, 2, $first->nextCursor);
		self::assertSame(['one'], array_map(static fn ($post): string => $post->text, $second->posts));
		self::assertNull($second->nextCursor);

		$this->expectException(AtprotoSyncException::class);
		$service->feed(self::DID, 2, 'not-a-cursor');
	}

	public function testAConflictingSequenceAndCIDIsNotApplied(): void
	{
		$service = $this->service();
		$this->source->append([new AtprotoEventPage([
			$this->postEvent('a', 1, $this->post('one', '2026-11-15T10:00:00Z')),
		], null)]);
		$service->sync();

		$this->source->append([new AtprotoEventPage([
			$this->postEvent('a', 1, $this->post('changed', '2026-11-15T10:00:00Z'), 'cid-other'),
		], null)]);
		$result = $service->sync();

		self::assertSame(1, $result->conflicted);
		self::assertSame('one', $this->store->record('relay', $this->uri('a'))?->record['text']);
	}

	public function testAnEventFromAnotherSourceIsRefused(): void
	{
		$this->source->append([new AtprotoEventPage([
			new AtprotoEvent(
				'jetstream',
				AtprotoEventKind::Record,
				self::DID,
				$this->at('2026-11-15T10:00:00Z'),
				operation: AtprotoRecordOperation::Create,
				collection: 'app.bsky.feed.post',
				rkey: 'a',
				cid: 'cid-a',
				sequence: 1,
				record: $this->post('wrong source', '2026-11-15T10:00:00Z'),
			),
		], null)]);

		$this->expectException(AtprotoSyncException::class);
		$this->service()->sync();
	}

	private function service(
		?\AlohaSocial\Core\Domain\Atproto\AtprotoModerationPolicy $policy = null,
		?\AlohaSocial\Core\Domain\Atproto\AtprotoBlobUrlBuilder $blobs = null,
	): AtprotoSyncService {
		return new AtprotoSyncService(
			$this->source,
			$this->store,
			$policy ?? new \AlohaSocial\Core\Domain\Atproto\AllowAllAtprotoContent(),
			$this->clock,
			null,
			$blobs,
		);
	}

	private function accountEvent(
		int $sequence,
		?string $handle,
		bool $active = true,
		AtprotoAccountStatus $status = AtprotoAccountStatus::Active,
	): AtprotoEvent {
		return new AtprotoEvent(
			'relay',
			AtprotoEventKind::Account,
			self::DID,
			$this->at('2026-11-15T10:00:00Z'),
			sequence: $sequence,
			active: $active,
			accountStatus: $status,
			handle: $handle,
		);
	}

	private function postEvent(string $rkey, int $sequence, array $record, ?string $cid = null): AtprotoEvent
	{
		return new AtprotoEvent(
			'relay',
			AtprotoEventKind::Record,
			self::DID,
			$this->at('2026-11-15T10:00:00Z'),
			operation: AtprotoRecordOperation::Create,
			collection: 'app.bsky.feed.post',
			rkey: $rkey,
			cid: $cid ?? 'cid-' . $rkey,
			rev: '3k' . $rkey,
			sequence: $sequence,
			record: $record,
		);
	}

	private function deleteEvent(string $rkey, int $sequence): AtprotoEvent
	{
		return new AtprotoEvent(
			'relay',
			AtprotoEventKind::Record,
			self::DID,
			$this->at('2026-11-15T10:00:00Z'),
			operation: AtprotoRecordOperation::Delete,
			collection: 'app.bsky.feed.post',
			rkey: $rkey,
			rev: '3k' . $rkey,
			sequence: $sequence,
		);
	}

	/**
	 * @param array{root: array{uri: string, cid: string}, parent: array{uri: string, cid: string}}|null $reply
	 * @param array<string, mixed>|null $embed
	 *
	 * @return array<string, mixed>
	 */
	private function post(string $text, string $createdAt, ?array $reply = null, ?array $embed = null): array
	{
		$record = [
			'$type' => 'app.bsky.feed.post',
			'text' => $text,
			'createdAt' => $createdAt,
		];

		if ($reply !== null) {
			$record['reply'] = $reply;
		}

		if ($embed !== null) {
			$record['embed'] = $embed;
		}

		return $record;
	}

	private function uri(string $rkey): string
	{
		return 'at://' . self::DID . '/app.bsky.feed.post/' . $rkey;
	}

	private function at(string $value): DateTimeImmutable
	{
		return new DateTimeImmutable($value);
	}
}
