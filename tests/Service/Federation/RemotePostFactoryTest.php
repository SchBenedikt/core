<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Service\Federation;

use AlohaSocial\Core\Domain\Federation\InboxRejectedException;
use AlohaSocial\Core\Domain\Federation\RemoteEventStatus;
use AlohaSocial\Core\Domain\Federation\RemoteObjectType;
use AlohaSocial\Core\Domain\Federation\RemotePost;
use AlohaSocial\Core\Domain\Federation\RemotePostKind;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Service\Federation\RemotePostFactory;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

use function array_merge;

/**
 * Typed remote objects at the ingestion boundary.
 *
 * The factory is the single place inbox delivery and outbox backfill agree on
 * content, timestamps and metadata, so these cases pin what a Gancio,
 * Mobilizon, PeerTube or Mastodon object turns into before storage.
 */
final class RemotePostFactoryTest extends TestCase
{
	private const ACTOR = 'https://peer.example/users/bob';

	private RemotePostFactory $factory;

	protected function setUp(): void
	{
		$this->factory = new RemotePostFactory(new FrozenClock(Time::parse('2026-11-11T12:00:00Z')));
	}

	public function testPrivateAndUnaddressedCreatesAreRejected(): void
	{
		$private = $this->create([
			'id' => 'https://peer.example/notes/private',
			'type' => 'Note',
			'content' => 'private',
			'attributedTo' => self::ACTOR,
		]);
		$private['object']['to'] = ['https://local.example/users/alice'];
		unset($private['to']);
		try {
			$this->factory->fromCreate(self::ACTOR, $private);
			self::fail('A direct message must not become a timeline post.');
		} catch (InboxRejectedException $exception) {
			self::assertSame('unsupported_audience', $exception->reason);
		}

		$unaddressed = $this->create([
			'id' => 'https://peer.example/notes/unaddressed',
			'type' => 'Note',
			'content' => 'unaddressed',
			'attributedTo' => self::ACTOR,
		]);
		unset($unaddressed['to']);
		try {
			$this->factory->fromCreate(self::ACTOR, $unaddressed);
			self::fail('An unaddressed post must not become timeline data.');
		} catch (InboxRejectedException $exception) {
			self::assertSame('unsupported_audience', $exception->reason);
		}
	}

	public function testFollowersOnlyCreateIsAccepted(): void
	{
		$post = $this->create([
			'id' => 'https://peer.example/notes/followers',
			'type' => 'Note',
			'content' => 'followers only',
			'attributedTo' => self::ACTOR,
		]);
		$post['object']['to'] = [self::ACTOR . '/followers'];

		self::assertSame('followers only', $this->factory->fromCreate(self::ACTOR, $post)->content);
	}

	public function testAnArticleKeepsItsTitleWithAnEmptyBody(): void
	{
		$post = $this->factory->fromCreate(self::ACTOR, $this->create([
			'id' => 'https://peer.example/articles/1',
			'type' => 'Article',
			'name' => 'A long read',
			'content' => '',
			'published' => '2026-11-11T10:00:00Z',
			'attributedTo' => self::ACTOR,
		]));

		self::assertSame(RemoteObjectType::Article, $post->objectType);
		self::assertSame('', $post->content);
		self::assertSame('A long read', $post->metadata?->title);
		self::assertNull($post->metadata?->event);
	}

	public function testATitleIsStoredAsPlainText(): void
	{
		$post = $this->factory->fromCreate(self::ACTOR, $this->create([
			'id' => 'https://peer.example/pages/1',
			'type' => 'Page',
			'name' => '<b>Hello</b> &amp; welcome',
			'content' => 'Body',
			'attributedTo' => self::ACTOR,
		]));

		self::assertSame('Hello & welcome', $post->metadata?->title);
	}

	public function testAGancioEventKeepsItsScheduleLocationAndStatus(): void
	{
		$post = $this->factory->fromCreate(self::ACTOR, $this->create([
			'id' => 'https://gancio.example/events/1',
			'type' => 'Event',
			'name' => 'Concert',
			'startTime' => '2026-12-01T18:00:00Z',
			'endTime' => '2026-12-01T20:00:00Z',
			'location' => ['type' => 'Place', 'name' => 'Town <i>hall</i>'],
			'eventStatus' => 'cancelled',
			'attributedTo' => self::ACTOR,
		], 'https://gancio.example/activities/1'));

		self::assertSame(RemoteObjectType::Event, $post->objectType);
		self::assertSame('Concert', $post->metadata?->event?->name);
		self::assertSame('Town hall', $post->metadata?->event?->location);
		self::assertSame(RemoteEventStatus::Cancelled, $post->metadata?->event?->status);
		self::assertSame('2026-12-01T18:00:00Z', Time::format($post->metadata->event->startsAt));
		self::assertNull($post->deletedAt, 'A cancelled event is not a Delete.');

		$presented = $post->toArray();
		self::assertSame('event', $presented['object_type']);
		self::assertSame('Concert', $presented['metadata']['event']['name']);
		self::assertSame('cancelled', $presented['metadata']['event']['status']);
	}

	public function testAnUnknownEventStatusIsIgnored(): void
	{
		$post = $this->eventWith(['eventStatus' => 'postponed-by-peer']);

		self::assertNull($post->metadata?->event?->status);
	}

	public function testAMobilizonParticipationUrlStaysASeparateValidatedLink(): void
	{
		$post = $this->eventWith([
			'externalParticipationUrl' => 'https://mobilizon.example/events/1/participate',
		]);

		self::assertSame(
			'https://mobilizon.example/events/1/participate',
			$post->metadata?->event?->participationUrl,
		);
		self::assertSame('https://gancio.example/events/1', $post->objectId);
	}

	public function testAnUnsafeParticipationUrlIsRefused(): void
	{
		$this->expectException(InboxRejectedException::class);

		$this->eventWith(['externalParticipationUrl' => 'javascript:alert(1)']);
	}

	public function testAnInvalidEventDateIsRefused(): void
	{
		$this->expectException(InboxRejectedException::class);

		$this->eventWith(['startTime' => 'next tuesday']);
	}

	public function testAnEventCannotEndBeforeItStarts(): void
	{
		$this->expectException(InboxRejectedException::class);

		$this->eventWith(['endTime' => '2026-12-01T17:00:00Z']);
	}

	public function testAMediaOnlyVideoKeepsDurationSourcesAndThumbnail(): void
	{
		$post = $this->factory->fromCreate(self::ACTOR, $this->create([
			'id' => 'https://peertube.example/videos/1',
			'type' => 'Video',
			'content' => '',
			'duration' => 'PT1M30S',
			'icon' => ['type' => 'Image', 'url' => 'https://peertube.example/thumb.jpg'],
			'url' => [
				['type' => 'Link', 'mediaType' => 'video/mp4', 'href' => 'https://peertube.example/v.mp4'],
				['type' => 'Link', 'mediaType' => 'video/webm', 'href' => 'https://peertube.example/v.webm'],
			],
			'attributedTo' => self::ACTOR,
		]));

		self::assertSame(RemoteObjectType::Video, $post->objectType);
		self::assertSame('', $post->content);
		self::assertSame(90, $post->metadata?->media?->durationSeconds);
		self::assertSame('https://peertube.example/thumb.jpg', $post->metadata?->media?->thumbnailUrl);
		self::assertSame([
			'video/mp4' => 'https://peertube.example/v.mp4',
			'video/webm' => 'https://peertube.example/v.webm',
		], $post->metadata?->media?->sources);
	}

	public function testAMalformedDurationIsDroppedRatherThanRefusingTheObject(): void
	{
		$post = $this->factory->fromCreate(self::ACTOR, $this->create([
			'id' => 'https://peer.example/audio/1',
			'type' => 'Audio',
			'duration' => 'ninety seconds',
			'url' => 'https://peer.example/a.mp3',
			'attributedTo' => self::ACTOR,
		]));

		self::assertSame(RemoteObjectType::Audio, $post->objectType);
		self::assertNull($post->metadata?->media?->durationSeconds);
		self::assertSame(['source0' => 'https://peer.example/a.mp3'], $post->metadata?->media?->sources);
	}

	public function testAnObjectWithoutContentOrMetadataIsStillRefused(): void
	{
		$this->expectException(InboxRejectedException::class);

		$this->factory->fromCreate(self::ACTOR, $this->create([
			'id' => 'https://peer.example/notes/empty',
			'type' => 'Note',
			'attributedTo' => self::ACTOR,
		]));
	}

	public function testAnIriTypeAndATypeListResolveToTheSameKind(): void
	{
		self::assertSame(
			RemoteObjectType::Question,
			RemoteObjectType::fromActivityType(['https://www.w3.org/ns/activitystreams#Question']),
		);
		self::assertSame(RemoteObjectType::Video, RemoteObjectType::fromActivityType('Video'));
		self::assertNull(RemoteObjectType::fromActivityType('Thing'));
	}

	public function testAnUnknownExplicitTypeFallsBackToANote(): void
	{
		$post = $this->factory->fromCreate(self::ACTOR, $this->create([
			'id' => 'https://peer.example/things/1',
			'type' => 'Thing',
			'content' => 'Still readable',
			'attributedTo' => self::ACTOR,
		]));

		self::assertSame(RemoteObjectType::Note, $post->objectType);
		self::assertNull($post->metadata);
	}

	public function testAnAnnounceOfAnArticleCarriesTheEmbeddedMetadata(): void
	{
		$post = $this->factory->fromAnnounce(self::ACTOR, [
			'id' => 'https://peer.example/announces/1',
			'type' => 'Announce',
			'object' => [
				'id' => 'https://peer.example/articles/1',
				'type' => 'Article',
				'name' => 'Shared read',
				'content' => 'Body',
				'attributedTo' => 'https://peer.example/users/carol',
			],
			'published' => '2026-11-11T11:00:00Z',
		]);

		self::assertSame(RemotePostKind::Boost, $post->kind);
		self::assertSame(RemoteObjectType::Article, $post->objectType);
		self::assertSame('Shared read', $post->metadata?->title);
	}

	public function testAnAnnounceWithoutAnEmbeddedObjectFallsBackToTheTarget(): void
	{
		$target = $this->factory->fromCreate(self::ACTOR, $this->create([
			'id' => 'https://peer.example/videos/1',
			'type' => 'Video',
			'duration' => 'PT30S',
			'url' => 'https://peer.example/v.mp4',
			'attributedTo' => self::ACTOR,
		]));

		$post = $this->factory->fromAnnounce('https://other.example/users/dave', [
			'id' => 'https://other.example/announces/1',
			'type' => 'Announce',
			'object' => 'https://peer.example/videos/1',
			'published' => '2026-11-11T11:00:00Z',
		], $target);

		self::assertSame(RemoteObjectType::Video, $post->objectType);
		self::assertSame(30, $post->metadata?->media?->durationSeconds);
	}

	public function testAnUpdateKeepsTheStoredTypeAndMetadata(): void
	{
		$target = $this->factory->fromCreate(self::ACTOR, $this->create([
			'id' => 'https://peer.example/articles/1',
			'type' => 'Article',
			'name' => 'Original',
			'content' => 'Before',
			'attributedTo' => self::ACTOR,
		]));

		$updated = $this->factory->fromUpdate($target, [
			'id' => 'https://peer.example/updates/1',
			'type' => 'Update',
			'object' => [
				'id' => 'https://peer.example/articles/1',
				'type' => 'Article',
				'name' => 'Original',
				'content' => 'After',
				'updated' => '2026-11-11T11:30:00Z',
				'attributedTo' => self::ACTOR,
			],
		]);

		self::assertSame('After', $updated->content);
		self::assertSame(RemoteObjectType::Article, $updated->objectType);
		self::assertSame('Original', $updated->metadata?->title);
		self::assertSame('2026-11-11T11:30:00Z', Time::format($updated->updatedAt));
	}

	/**
	 * @param array<string, mixed> $object
	 *
	 * @return array<string, mixed>
	 */
	private function create(array $object, ?string $activityId = null): array
	{
		return [
			'id' => $activityId ?? 'https://peer.example/activities/1',
			'type' => 'Create',
			'actor' => self::ACTOR,
			'to' => ['https://www.w3.org/ns/activitystreams#Public'],
			'object' => $object,
		];
	}

	/**
	 * @param array<string, mixed> $extra
	 */
	private function eventWith(array $extra): RemotePost
	{
		return $this->factory->fromCreate(self::ACTOR, $this->create(array_merge([
			'id' => 'https://gancio.example/events/1',
			'type' => 'Event',
			'name' => 'Concert',
			'startTime' => '2026-12-01T18:00:00Z',
			'attributedTo' => self::ACTOR,
		], $extra), 'https://gancio.example/activities/1'));
	}
}
