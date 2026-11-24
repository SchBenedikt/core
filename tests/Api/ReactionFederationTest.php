<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Api;

use AlohaSocial\Core\Application;
use AlohaSocial\Core\Domain\Federation\Block;
use AlohaSocial\Core\Domain\Federation\FollowedActor;
use AlohaSocial\Core\Domain\Federation\InMemoryActivityLog;
use AlohaSocial\Core\Domain\Federation\InMemoryActorDirectory;
use AlohaSocial\Core\Domain\Federation\InMemoryBlockRepository;
use AlohaSocial\Core\Domain\Federation\InMemoryFollowingRepository;
use AlohaSocial\Core\Domain\Federation\InMemoryMuteRepository;
use AlohaSocial\Core\Domain\Federation\InMemoryRemotePostRepository;
use AlohaSocial\Core\Domain\Federation\Mute;
use AlohaSocial\Core\Domain\Federation\RemoteActor;
use AlohaSocial\Core\Domain\Federation\RemotePost;
use AlohaSocial\Core\Domain\Federation\RemotePostKind;
use AlohaSocial\Core\Domain\Interaction\InMemoryReactionRepository;
use AlohaSocial\Core\Domain\Interaction\ReactionDirection;
use AlohaSocial\Core\Domain\Interaction\ReactionKind;
use AlohaSocial\Core\Domain\Notification\InMemoryNotificationRepository;
use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Protocol\ActivityPub\HttpSignature;
use AlohaSocial\Core\Protocol\ActivityPub\RsaKeyPair;
use AlohaSocial\Core\Service\CreatePost;
use AlohaSocial\Core\Service\InstanceInfo;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Core\Tests\Support\FakeHttpClient;
use AlohaSocial\Core\Tests\Support\InMemoryActorResolver;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * The inbound reaction slice through the real kernel.
 *
 * Each test starts from a signed peer request, so the signature, actor
 * ownership and replay guard are exercised together with the reaction rule.
 * The assertions are on the rows and counts, not on the response alone: a 202
 * with no row would be the failure this issue is about.
 */
final class ReactionFederationTest extends TestCase
{
	private const ACTOR = 'https://remote.example/users/bob';

	private const KEY_ID = 'https://remote.example/users/bob#main-key';

	private const INBOX = 'https://remote.example/users/bob/inbox';

	private static RsaKeyPair $remoteKey;

	private static RsaKeyPair $localKey;

	private FrozenClock $clock;

	private InMemoryReactionRepository $reactions;

	private InMemoryNotificationRepository $notifications;

	private InMemoryBlockRepository $blocks;

	private InMemoryMuteRepository $mutes;

	private InMemoryFollowingRepository $following;

	private InMemoryRemotePostRepository $remotePosts;

	private Application $app;

	public static function setUpBeforeClass(): void
	{
		self::$remoteKey = RsaKeyPair::generate();
		self::$localKey = RsaKeyPair::generate();
	}

	protected function setUp(): void
	{
		$this->clock = new FrozenClock(Time::parse('2026-11-11T10:00:00Z'));
		$this->reactions = new InMemoryReactionRepository();
		$this->notifications = new InMemoryNotificationRepository();
		$this->blocks = new InMemoryBlockRepository();
		$this->mutes = new InMemoryMuteRepository();
		$this->following = new InMemoryFollowingRepository();
		$this->remotePosts = new InMemoryRemotePostRepository();
		$this->app = $this->app();
	}

	public function testAnInboundLikeCountsOnceAndNotifiesTheAuthor(): void
	{
		$post = $this->post();

		$response = $this->deliver($this->like($post));

		self::assertSame(202, $response->status);
		self::assertSame(1, $this->likeCount($post));
		self::assertSame([], $this->emojiCounts($post));

		$notifications = $this->notifications->listFor('alice', 10);
		self::assertCount(1, $notifications);
		self::assertSame('favourite', $notifications[0]->type->value);
		self::assertSame(self::ACTOR, $notifications[0]->actorId);
	}

	public function testARetransmittedLikeWithANewActivityIdIsStillOneReaction(): void
	{
		$post = $this->post();

		self::assertSame(202, $this->deliver($this->like($post, 'https://remote.example/activities/like-1'))->status);
		self::assertSame(202, $this->deliver($this->like($post, 'https://remote.example/activities/like-2'))->status);

		self::assertSame(1, $this->likeCount($post));
		self::assertCount(1, $this->notifications->listFor('alice', 10));
	}

	public function testAReplayOfTheSameActivityIdIsAcceptedWithoutChangingState(): void
	{
		$post = $this->post();
		$activity = $this->like($post);

		self::assertSame(202, $this->deliver($activity)->status);
		self::assertSame(202, $this->deliver($activity)->status);

		self::assertSame(1, $this->likeCount($post));
	}

	public function testUndoWithTheEmbeddedLikeRemovesTheReaction(): void
	{
		$post = $this->post();
		self::assertSame(202, $this->deliver($this->like($post))->status);

		$undo = [
			'id' => 'https://remote.example/activities/undo-like-1',
			'type' => 'Undo',
			'actor' => self::ACTOR,
			'object' => $this->like($post),
		];

		self::assertSame(202, $this->deliver($undo)->status);
		self::assertSame(0, $this->likeCount($post));
	}

	public function testUndoThatOnlyNamesTheLikeActivityIdRemovesTheReaction(): void
	{
		$post = $this->post();
		self::assertSame(202, $this->deliver($this->like($post))->status);

		$undo = [
			'id' => 'https://remote.example/activities/undo-like-2',
			'type' => 'Undo',
			'actor' => self::ACTOR,
			'object' => 'https://remote.example/activities/like-1',
		];

		self::assertSame(202, $this->deliver($undo)->status);
		self::assertSame(0, $this->likeCount($post));
	}

	public function testUndoForAReactionThatNeverArrivedIsAccepted(): void
	{
		$post = $this->post();

		$undo = [
			'id' => 'https://remote.example/activities/undo-like-3',
			'type' => 'Undo',
			'actor' => self::ACTOR,
			'object' => $this->like($post),
		];

		self::assertSame(202, $this->deliver($undo)->status);
		self::assertSame(0, $this->likeCount($post));
	}

	public function testAnUndoOfSomebodyElsesLikeIsRefused(): void
	{
		$post = $this->post();
		self::assertSame(202, $this->deliver($this->like($post))->status);

		$embedded = $this->like($post);
		$embedded['actor'] = 'https://mallory.example/users/eve';
		$undo = [
			'id' => 'https://remote.example/activities/undo-like-4',
			'type' => 'Undo',
			'actor' => self::ACTOR,
			'object' => $embedded,
		];

		$response = $this->deliver($undo);

		self::assertSame(403, $response->status);
		self::assertSame('actor_mismatch', $response->decodedBody()['error']['code']);
		self::assertSame(1, $this->likeCount($post));
	}

	public function testEmojiReactionsHaveTheirOwnCountAndDoNotTouchLikes(): void
	{
		$post = $this->post();
		self::assertSame(202, $this->deliver($this->like($post))->status);
		self::assertSame(202, $this->deliver($this->emojiReact($post, '❤'))->status);

		self::assertSame(1, $this->likeCount($post));
		self::assertSame(['❤' => 1], $this->emojiCounts($post));
	}

	public function testUndoLikeDoesNotRemoveAnEmojiReaction(): void
	{
		$post = $this->post();
		self::assertSame(202, $this->deliver($this->emojiReact($post, '❤'))->status);

		$undo = [
			'id' => 'https://remote.example/activities/undo-like-5',
			'type' => 'Undo',
			'actor' => self::ACTOR,
			'object' => $this->like($post),
		];

		self::assertSame(202, $this->deliver($undo)->status);
		self::assertSame(['❤' => 1], $this->emojiCounts($post));
	}

	public function testAnEmojiReactionCanBeUndone(): void
	{
		$post = $this->post();
		$activity = $this->emojiReact($post, '🎉');
		self::assertSame(202, $this->deliver($activity)->status);

		$undo = [
			'id' => 'https://remote.example/activities/undo-react-1',
			'type' => 'Undo',
			'actor' => self::ACTOR,
			'object' => $activity,
		];

		self::assertSame(202, $this->deliver($undo)->status);
		self::assertSame([], $this->emojiCounts($post));
	}

	public function testAVerifiedCustomEmojiIsStoredWithItsIcon(): void
	{
		$post = $this->post();
		$activity = $this->emojiReact($post, ':blob:', [
			['type' => 'Emoji', 'name' => 'blob', 'icon' => ['type' => 'Image', 'url' => 'https://remote.example/emoji/blob.png']],
		]);

		self::assertSame(202, $this->deliver($activity)->status);
		self::assertSame([':blob:' => 1], $this->emojiCounts($post));

		$stored = $this->reactions->findByActivityId($activity['id']);
		self::assertNotNull($stored);
		self::assertTrue($stored->hasSafeIcon());
	}

	public function testTheMisskeyReactionFieldIsAccepted(): void
	{
		$post = $this->post();
		$activity = $this->emojiReact($post, '');
		unset($activity['content']);
		$activity['_misskey_reaction'] = '🎉';

		self::assertSame(202, $this->deliver($activity)->status);
		self::assertSame(['🎉' => 1], $this->emojiCounts($post));
	}

	public function testACustomEmojiWithoutAVerifiedTagIsRefused(): void
	{
		$post = $this->post();

		$response = $this->deliver($this->emojiReact($post, ':blob:'));

		self::assertSame(400, $response->status);
		self::assertSame('invalid_emoji', $response->decodedBody()['error']['code']);
		self::assertSame([], $this->emojiCounts($post));
	}

	public function testACustomEmojiWithAPlainHttpIconIsRefused(): void
	{
		$post = $this->post();
		$activity = $this->emojiReact($post, ':blob:', [
			['type' => 'Emoji', 'name' => 'blob', 'icon' => ['type' => 'Image', 'url' => 'http://remote.example/emoji/blob.png']],
		]);

		$response = $this->deliver($activity);

		self::assertSame(400, $response->status);
		self::assertSame('invalid_emoji', $response->decodedBody()['error']['code']);
	}

	public function testAPlainWordIsNotAnEmojiReaction(): void
	{
		$post = $this->post();

		$response = $this->deliver($this->emojiReact($post, 'nice post'));

		self::assertSame(400, $response->status);
		self::assertSame('invalid_emoji', $response->decodedBody()['error']['code']);
	}

	public function testALikeForAnUnknownPostIsRefused(): void
	{
		$response = $this->deliver([
			'id' => 'https://remote.example/activities/like-9',
			'type' => 'Like',
			'actor' => self::ACTOR,
			'object' => 'https://localhost:8080/api/v1/posts/ghost',
		]);

		self::assertSame(404, $response->status);
		self::assertSame('unknown_object', $response->decodedBody()['error']['code']);
	}

	public function testALikeForADeletedPostIsRefused(): void
	{
		$post = $this->post();
		$this->app->posts->delete('alice', $post->id);

		$response = $this->deliver($this->like($post));

		self::assertSame(404, $response->status);
		self::assertSame('unknown_object', $response->decodedBody()['error']['code']);
	}

	public function testALikeForADirectPostIsRefused(): void
	{
		$post = $this->post(Visibility::Direct, ['bob']);

		$response = $this->deliver($this->like($post));

		self::assertSame(403, $response->status);
		self::assertSame('not_authorized', $response->decodedBody()['error']['code']);
		self::assertSame(0, $this->likeCount($post));
	}

	public function testAFollowersOnlyPostNeedsTheFollowerEdge(): void
	{
		$post = $this->post(Visibility::Followers);

		$refused = $this->deliver($this->like($post));
		self::assertSame(403, $refused->status);
		self::assertSame('not_authorized', $refused->decodedBody()['error']['code']);

		self::assertSame(202, $this->deliver($this->follow())->status);
		self::assertSame(202, $this->deliver($this->like($post, 'https://remote.example/activities/like-10'))->status);
		self::assertSame(1, $this->likeCount($post));
	}

	public function testABlockedActorCannotReact(): void
	{
		$post = $this->post();
		$this->blocks->insert(new Block('block-1', 'alice', self::ACTOR, Time::parse('2026-11-11T09:00:00Z')));

		$response = $this->deliver($this->like($post));

		self::assertSame(403, $response->status);
		self::assertSame('blocked_actor', $response->decodedBody()['error']['code']);
		self::assertSame(0, $this->likeCount($post));
	}

	public function testAnActorThatBlockedTheAuthorCannotReact(): void
	{
		$post = $this->post();
		$block = [
			'id' => 'https://remote.example/activities/block-1',
			'type' => 'Block',
			'actor' => self::ACTOR,
			'object' => 'https://localhost:8080/@alice',
		];
		self::assertSame(202, $this->deliver($block)->status);

		$response = $this->deliver($this->like($post, 'https://remote.example/activities/like-after-block'));

		self::assertSame(403, $response->status);
		self::assertSame('blocked_actor', $response->decodedBody()['error']['code']);
		self::assertSame(0, $this->likeCount($post));
	}

	public function testAMutedActorStillCountsButDoesNotNotify(): void
	{
		$post = $this->post();
		$this->mutes->save(new Mute('alice', self::ACTOR, true, Time::parse('2026-11-11T09:00:00Z')));

		self::assertSame(202, $this->deliver($this->like($post))->status);

		self::assertSame(1, $this->likeCount($post));
		self::assertSame([], $this->notifications->listFor('alice', 10));
	}

	public function testMastodonAndNeutralReadsAgreeAboutCountsAndViewerState(): void
	{
		$post = $this->post();
		self::assertSame(202, $this->deliver($this->like($post))->status);
		self::assertSame(202, $this->deliver($this->emojiReact($post, '❤'))->status);

		$favourite = $this->app->handle(new Request(
			method: 'POST',
			path: '/api/mastodon/v1/statuses/' . $post->id . '/favourite',
			userId: 'bob',
			scopes: ['write:favourites'],
		));
		self::assertSame(200, $favourite->status, (string) json_encode($favourite->decodedBody()));

		$neutral = $this->app->handle(new Request('GET', '/api/v1/posts/' . $post->id));
		$interactions = $neutral->decodedBody()['post']['interactions'];

		self::assertSame(2, $interactions['favourites_count']);
		self::assertSame([['emoji' => '❤', 'count' => 1]], $interactions['emoji_reactions']);
		self::assertFalse($interactions['viewer']['favourited']);

		$mastodon = $this->app->handle(new Request(
			method: 'GET',
			path: '/api/mastodon/v1/statuses/' . $post->id,
			userId: 'bob',
			scopes: ['read:statuses'],
		));

		self::assertSame(200, $mastodon->status);
		self::assertSame(2, $mastodon->decodedBody()['favourites_count']);
		self::assertTrue($mastodon->decodedBody()['favourited']);
	}

	public function testMastodonCanLikeUnlikeReblogAndUnreblogAFollowedRemoteStatus(): void
	{
		$objectId = 'https://remote.example/objects/post-1';
		$this->remotePosts->insert(new RemotePost(
			'https://remote.example/activities/create-1',
			$objectId,
			self::ACTOR,
			RemotePostKind::Post,
			'<script>alert(1)</script> hello',
			null,
			Time::parse('2026-11-11T09:00:00Z'),
		));
		$this->following->insert(new FollowedActor(
			'follow-alice-remote',
			'alice',
			self::ACTOR,
			self::INBOX,
			Time::parse('2026-11-11T08:00:00Z'),
		));
		$encodedId = rawurlencode($objectId);

		$like = $this->app->handle(new Request(
			method: 'POST',
			path: '/api/mastodon/v1/statuses/' . $encodedId . '/favourite',
			userId: 'alice',
			scopes: ['write:favourites'],
		));
		self::assertSame(200, $like->status, (string) json_encode($like->decodedBody()));
		self::assertTrue($like->decodedBody()['favourited']);
		self::assertSame($objectId, $like->decodedBody()['uri']);
		self::assertStringContainsString('&lt;script&gt;', $like->decodedBody()['content']);

		$repeated = $this->app->handle(new Request(
			method: 'POST',
			path: '/api/mastodon/v1/statuses/' . $encodedId . '/favourite',
			userId: 'alice',
			scopes: ['write:favourites'],
		));
		self::assertSame(200, $repeated->status);
		self::assertCount(1, array_filter(
			$this->app->queue->pending(),
			static fn ($job): bool => $job->type === 'federation.deliver',
		));

		$read = $this->app->handle(new Request(
			method: 'GET',
			path: '/api/mastodon/v1/statuses/' . $encodedId,
			userId: 'alice',
			scopes: ['read:statuses'],
		));
		self::assertSame(200, $read->status);
		self::assertTrue($read->decodedBody()['favourited']);

		$unlike = $this->app->handle(new Request(
			method: 'POST',
			path: '/api/mastodon/v1/statuses/' . $encodedId . '/unfavourite',
			userId: 'alice',
			scopes: ['write:favourites'],
		));
		self::assertSame(200, $unlike->status, (string) json_encode($unlike->decodedBody()));
		self::assertFalse($unlike->decodedBody()['favourited']);
		self::assertNull($this->reactions->find(
			ReactionDirection::Out,
			'alice',
			$objectId,
			ReactionKind::Like,
			null,
		));

		$reblog = $this->app->handle(new Request(
			method: 'POST',
			path: '/api/mastodon/v1/statuses/' . $encodedId . '/reblog',
			userId: 'alice',
			scopes: ['write:statuses'],
		));
		self::assertSame(200, $reblog->status, (string) json_encode($reblog->decodedBody()));
		self::assertTrue($reblog->decodedBody()['reblogged']);
		self::assertNotNull($this->reactions->find(
			ReactionDirection::Out,
			'alice',
			$objectId,
			ReactionKind::Boost,
			null,
		));

		$unreblog = $this->app->handle(new Request(
			method: 'POST',
			path: '/api/mastodon/v1/statuses/' . $encodedId . '/unreblog',
			userId: 'alice',
			scopes: ['write:statuses'],
		));
		self::assertSame(200, $unreblog->status, (string) json_encode($unreblog->decodedBody()));
		self::assertFalse($unreblog->decodedBody()['reblogged']);
		self::assertNull($this->reactions->find(
			ReactionDirection::Out,
			'alice',
			$objectId,
			ReactionKind::Boost,
			null,
		));
	}

	public function testMastodonRemoteFavouriteRequiresAnUnblockedFollowedAuthor(): void
	{
		$objectId = 'https://remote.example/objects/post-private-to-followers';
		$this->remotePosts->insert(new RemotePost(
			'https://remote.example/activities/create-2',
			$objectId,
			self::ACTOR,
			RemotePostKind::Post,
			'cached remote post',
			null,
			Time::parse('2026-11-11T09:00:00Z'),
		));
		$path = '/api/mastodon/v1/statuses/' . rawurlencode($objectId) . '/favourite';
		$notFollowed = $this->app->handle(new Request(
			method: 'POST',
			path: $path,
			userId: 'alice',
			scopes: ['write:favourites'],
		));
		self::assertSame(404, $notFollowed->status);
		self::assertSame([], $this->app->queue->pending());

		$this->following->insert(new FollowedActor(
			'follow-alice-remote',
			'alice',
			self::ACTOR,
			self::INBOX,
			Time::parse('2026-11-11T08:00:00Z'),
		));
		$this->blocks->insert(new Block('local-block-remote', 'alice', self::ACTOR, Time::parse('2026-11-11T09:30:00Z')));

		$blocked = $this->app->handle(new Request(
			method: 'POST',
			path: $path,
			userId: 'alice',
			scopes: ['write:favourites'],
		));
		self::assertSame(403, $blocked->status);
		self::assertSame('blocked_actor', $blocked->decodedBody()['error']['code']);
		self::assertSame([], $this->app->queue->pending());
	}

	private function app(): Application
	{
		return Application::inMemory(
			clock: $this->clock,
			config: new ArrayConfig([InstanceInfo::CONFIG_APP => [
				'instance.url' => 'https://localhost:8080',
				'mastodon.api' => 'true',
			]]),
			localActors: new InMemoryActorDirectory(['alice', 'bob']),
			httpClient: new FakeHttpClient(),
			signingKey: self::$localKey,
			actorResolver: new InMemoryActorResolver([
				self::KEY_ID => new RemoteActor(
					self::ACTOR,
					self::KEY_ID,
					self::INBOX,
					self::$remoteKey->publicKeyPem(),
				),
			]),
			activityLog: new InMemoryActivityLog(),
			followingRepository: $this->following,
			remotePostRepository: $this->remotePosts,
			blockRepository: $this->blocks,
			muteRepository: $this->mutes,
			reactionRepository: $this->reactions,
			notificationRepository: $this->notifications,
		);
	}

	/**
	 * @param list<string> $recipientIds
	 */
	private function post(Visibility $visibility = Visibility::Public, array $recipientIds = []): Post
	{
		return $this->app->posts->create(new CreatePost(
			authorId: 'alice',
			content: 'Hello from the local instance',
			visibility: $visibility,
			recipientIds: $recipientIds,
		));
	}

	private function likeCount(Post $post): int
	{
		return $this->reactions->countsForPosts([$post->id])[$post->id]['likes'];
	}

	/**
	 * @return array<string, int>
	 */
	private function emojiCounts(Post $post): array
	{
		return $this->reactions->countsForPosts([$post->id])[$post->id]['emojis'];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function like(Post $post, string $activityId = 'https://remote.example/activities/like-1'): array
	{
		return [
			'id' => $activityId,
			'type' => 'Like',
			'actor' => self::ACTOR,
			'object' => 'https://localhost:8080/api/v1/posts/' . $post->id,
		];
	}

	/**
	 * @param list<array<string, mixed>> $tags
	 *
	 * @return array<string, mixed>
	 */
	private function emojiReact(
		Post $post,
		string $emoji,
		array $tags = [],
	): array {
		$activity = [
			'id' => 'https://remote.example/activities/react-1',
			'type' => 'EmojiReact',
			'actor' => self::ACTOR,
			'object' => 'https://localhost:8080/api/v1/posts/' . $post->id,
			'content' => $emoji,
		];

		if ($tags !== []) {
			$activity['tag'] = $tags;
		}

		return $activity;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function follow(): array
	{
		return [
			'id' => 'https://remote.example/activities/follow-1',
			'type' => 'Follow',
			'actor' => self::ACTOR,
			'object' => 'https://localhost:8080/@alice',
		];
	}

	/**
	 * @param array<string, mixed> $activity
	 */
	private function deliver(array $activity, ?DateTimeImmutable $at = null): Response
	{
		$body = json_encode($activity, JSON_THROW_ON_ERROR);
		$headers = HttpSignature::sign(
			self::$remoteKey,
			self::KEY_ID,
			'POST',
			'/inbox',
			'localhost',
			$body,
			$at ?? $this->clock->now(),
		);

		return $this->app->handle(new Request(
			method: 'POST',
			path: '/inbox',
			headers: $headers,
			body: $body,
		));
	}
}
