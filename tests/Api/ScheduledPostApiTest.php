<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Api;

use AlohaSocial\Core\Application;
use AlohaSocial\Core\Domain\Federation\InMemoryActivityLog;
use AlohaSocial\Core\Domain\Federation\InMemoryActorDirectory;
use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Domain\Scheduling\InMemoryScheduledPostRepository;
use AlohaSocial\Core\Domain\Scheduling\ScheduledPostRepository;
use AlohaSocial\Core\Jobs\JobType;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Service\CreatePost;
use AlohaSocial\Core\Service\InstanceInfo;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Durable scheduled publishing through the real kernel.
 *
 * Every case checks both sides that used to be untestable: an API write that
 * allocates no public post id, and a worker that publishes exactly once when
 * the clock reaches the due time.
 */
final class ScheduledPostApiTest extends TestCase
{
	private FrozenClock $clock;

	private ScheduledPostRepository $scheduled;

	private Application $app;

	protected function setUp(): void
	{
		$this->clock = new FrozenClock(Time::parse('2026-11-11T10:00:00Z'));
		$this->scheduled = new InMemoryScheduledPostRepository();
		$this->app = $this->app();
	}

	public function testAScheduleStoresTheWriteWithoutPublishingIt(): void
	{
		$response = $this->create('Later hello', $this->in(600));

		self::assertSame(201, $response->status, (string) json_encode($response->decodedBody()));
		$body = $response->decodedBody()['scheduled_post'];
		self::assertSame('scheduled', $body['state']);
		self::assertSame('2026-11-11T10:10:00Z', $body['scheduled_at']);
		self::assertSame('Later hello', $body['content']);
		self::assertArrayNotHasKey('post_id', $body);

		self::assertSame([], $this->publicTimeline());
		self::assertSame(0, $this->postCount());
	}

	public function testTheScheduledLanguageSurvivesPublication(): void
	{
		$response = $this->create('Later hello', $this->in(600), ['language' => 'de']);
		self::assertSame('de', $response->decodedBody()['scheduled_post']['language']);

		$this->clock->advance(600);
		self::assertSame(1, $this->app->worker->runOnce());

		self::assertSame('de', $this->app->posts->get(
			$this->publicTimeline()[0]['id'],
		)?->language);
	}

	public function testTheWorkerPublishesOnlyAtTheDueTimeAndOnlyOnce(): void
	{
		$id = $this->create('Later hello', $this->in(600))->decodedBody()['scheduled_post']['id'];

		self::assertSame(0, $this->app->worker->runOnce());
		self::assertSame(0, $this->postCount());

		$this->clock->advance(600);
		self::assertSame(1, $this->app->worker->runOnce());

		$posts = $this->publicTimeline();
		self::assertCount(1, $posts);
		self::assertSame('Later hello', $posts[0]['content']);

		// A duplicate job (the queue may replay one) must not publish twice:
		// the row is no longer pending, so the handler returns early.
		$this->app->queue->push(JobType::SCHEDULED_POST_PUBLISH, ['scheduled_post_id' => $id]);
		$this->app->worker->runOnce();
		self::assertSame(1, $this->postCount());

		$stored = $this->show($id)->decodedBody()['scheduled_post'];
		self::assertSame('published', $stored['state']);
		self::assertSame('2026-11-11T10:10:00Z', $stored['published_at']);
	}

	public function testCancelPreventsThePublication(): void
	{
		$id = $this->create('Never', $this->in(600))->decodedBody()['scheduled_post']['id'];

		$cancelled = $this->app->handle(new Request(
			method: 'DELETE',
			path: '/api/v1/scheduled-posts/' . $id,
			userId: 'alice',
		));

		self::assertSame(200, $cancelled->status);
		self::assertSame('cancelled', $cancelled->decodedBody()['scheduled_post']['state']);

		$this->clock->advance(900);
		$this->app->worker->runOnce();

		self::assertSame(0, $this->postCount());
	}

	public function testRescheduleMovesThePublication(): void
	{
		$id = $this->create('Later', $this->in(600))->decodedBody()['scheduled_post']['id'];

		$rescheduled = $this->app->handle(new Request(
			method: 'PATCH',
			path: '/api/v1/scheduled-posts/' . $id,
			body: json_encode(['scheduled_at' => $this->in(1800)], JSON_THROW_ON_ERROR),
			userId: 'alice',
		));

		self::assertSame(200, $rescheduled->status);
		self::assertSame('2026-11-11T10:30:00Z', $rescheduled->decodedBody()['scheduled_post']['scheduled_at']);

		$this->clock->advance(600);
		$this->app->worker->runOnce();
		self::assertSame(0, $this->postCount());

		$this->clock->advance(1200);
		$this->app->worker->runOnce();
		self::assertSame(1, $this->postCount());
	}

	public function testAnEditReplacesThePayloadAndStillPublishesOnce(): void
	{
		$id = $this->create('Before', $this->in(600))->decodedBody()['scheduled_post']['id'];

		$edited = $this->app->handle(new Request(
			method: 'PATCH',
			path: '/api/v1/scheduled-posts/' . $id,
			body: json_encode([
				'content' => 'After',
				'visibility' => 'unlisted',
				'poll' => [
					'options' => ['Yes', 'No'],
					'expires_in' => 3600,
				],
			], JSON_THROW_ON_ERROR),
			userId: 'alice',
		));

		self::assertSame(200, $edited->status, (string) json_encode($edited->decodedBody()));
		$stored = $edited->decodedBody()['scheduled_post'];
		self::assertSame('After', $stored['content']);
		self::assertSame('unlisted', $stored['visibility']);
		self::assertSame(['Yes', 'No'], $stored['poll']['options']);
		self::assertSame(3600, $stored['poll']['expires_in']);

		$this->clock->advance(600);
		$this->app->worker->runOnce();

		$postId = $this->scheduled->find($id)?->postId;
		self::assertNotNull($postId);
		$post = $this->app->posts->get($postId);
		self::assertSame('After', $post->content);
		self::assertSame(Visibility::Unlisted, $post->visibility);
		self::assertNotNull($post->poll);
	}

	public function testAnEditCanClearThePollAndKeepTheRest(): void
	{
		$id = $this->create('With poll', $this->in(600), [
			'poll' => ['options' => ['A', 'B'], 'expires_in' => 3600],
		])->decodedBody()['scheduled_post']['id'];

		$edited = $this->app->handle(new Request(
			method: 'PATCH',
			path: '/api/v1/scheduled-posts/' . $id,
			body: json_encode(['poll' => null], JSON_THROW_ON_ERROR),
			userId: 'alice',
		));

		self::assertSame(200, $edited->status);
		$stored = $edited->decodedBody()['scheduled_post'];
		self::assertSame('With poll', $stored['content']);
		self::assertNull($stored['poll']);
	}

	public function testAnEditAfterPublicationStartedIsRefused(): void
	{
		$id = $this->create('Done', $this->in(600))->decodedBody()['scheduled_post']['id'];

		$this->clock->advance(600);
		$this->app->worker->runOnce();

		$edited = $this->app->handle(new Request(
			method: 'PATCH',
			path: '/api/v1/scheduled-posts/' . $id,
			body: json_encode(['content' => 'Too late'], JSON_THROW_ON_ERROR),
			userId: 'alice',
		));

		self::assertSame(409, $edited->status);
		self::assertSame('Done', $this->publicTimeline()[0]['content']);
	}

	public function testAnEditIsValidatedLikeACreate(): void
	{
		$id = $this->create('Valid', $this->in(600))->decodedBody()['scheduled_post']['id'];

		$edited = $this->app->handle(new Request(
			method: 'PATCH',
			path: '/api/v1/scheduled-posts/' . $id,
			body: json_encode(['content' => '   '], JSON_THROW_ON_ERROR),
			userId: 'alice',
		));

		self::assertSame(422, $edited->status);
		self::assertSame('Valid', $this->show($id)->decodedBody()['scheduled_post']['content']);
	}

	public function testALeadTimeBelowTheMinimumIsRefused(): void
	{
		$response = $this->create('Too soon', $this->in(60));

		self::assertSame(422, $response->status);
		self::assertArrayHasKey('scheduled_at', $response->decodedBody()['error']['fields']);
	}

	public function testAnotherAccountCannotReadOrCancelTheSchedule(): void
	{
		$id = $this->create('Mine', $this->in(600))->decodedBody()['scheduled_post']['id'];

		self::assertSame(404, $this->app->handle(new Request(
			method: 'GET',
			path: '/api/v1/scheduled-posts/' . $id,
			userId: 'bob',
		))->status);

		self::assertSame(404, $this->app->handle(new Request(
			method: 'DELETE',
			path: '/api/v1/scheduled-posts/' . $id,
			userId: 'bob',
		))->status);

		$list = $this->app->handle(new Request(
			method: 'GET',
			path: '/api/v1/scheduled-posts',
			userId: 'bob',
		));

		self::assertSame([], $list->decodedBody()['scheduled_posts']);
	}

	public function testTheDailyQuotaIsEnforced(): void
	{
		$repository = new InMemoryScheduledPostRepository();
		$app = Application::inMemory(
			clock: $this->clock,
			config: new ArrayConfig([
				InstanceInfo::CONFIG_APP => [
					'instance.url' => 'https://localhost:8080',
					'mastodon.api' => 'true',
					'scheduling.daily_limit' => '1',
				],
			]),
			localActors: new InMemoryActorDirectory(['alice', 'bob']),
			scheduledPostRepository: $repository,
		);

		$first = $app->handle($this->createRequest('One'));
		self::assertSame(201, $first->status);

		$second = $app->handle($this->createRequest('Two'));
		self::assertSame(422, $second->status);
		self::assertArrayHasKey('scheduled_at', $second->decodedBody()['error']['fields']);
	}

	public function testMastodonClientsCanScheduleAndList(): void
	{
		$response = $this->app->handle(new Request(
			method: 'POST',
			path: '/api/mastodon/v1/statuses',
			body: json_encode([
				'status' => 'From a Mastodon client',
				'spoiler_text' => 'Later',
				'visibility' => 'unlisted',
				'scheduled_at' => $this->in(900),
			], JSON_THROW_ON_ERROR),
			userId: 'alice',
			scopes: ['write:statuses'],
		));

		self::assertSame(200, $response->status, (string) json_encode($response->decodedBody()));
		$scheduled = $response->decodedBody();
		self::assertSame('2026-11-11T10:15:00Z', $scheduled['scheduled_at']);
		self::assertSame('From a Mastodon client', $scheduled['params']['text']);
		self::assertSame('unlisted', $scheduled['params']['visibility']);
		self::assertTrue($scheduled['params']['sensitive']);

		$list = $this->app->handle(new Request(
			method: 'GET',
			path: '/api/mastodon/v1/scheduled_statuses',
			userId: 'alice',
			scopes: ['read:statuses'],
		));

		self::assertSame(200, $list->status);
		self::assertCount(1, $list->decodedBody());
		self::assertSame($scheduled['id'], $list->decodedBody()[0]['id']);
	}

	public function testAMastodonScheduledPollIsStoredAndPublished(): void
	{
		$response = $this->app->handle(new Request(
			method: 'POST',
			path: '/api/mastodon/v1/statuses',
			body: json_encode([
				'status' => 'Scheduled poll',
				'visibility' => 'public',
				'scheduled_at' => $this->in(900),
				'poll' => [
					'options' => ['Yes', 'No'],
					'expires_in' => 3600,
					'multiple' => false,
					'hide_totals' => true,
				],
			], JSON_THROW_ON_ERROR),
			userId: 'alice',
			scopes: ['write:statuses'],
		));

		self::assertSame(200, $response->status, (string) json_encode($response->decodedBody()));
		$poll = $response->decodedBody()['params']['poll'];
		self::assertSame(['Yes', 'No'], $poll['options']);
		self::assertSame(3600, $poll['expires_in']);
		self::assertTrue($poll['hide_totals']);

		$this->clock->advance(900);
		self::assertSame(1, $this->app->worker->runOnce());

		$post = $this->app->posts->get($this->publicTimeline()[0]['id']);
		self::assertNotNull($post?->poll);
		self::assertCount(2, $post->poll->options);
		self::assertTrue($post->poll->hideTotals);
		// expires_in counts from publication (10:15), not from scheduling (10:00).
		self::assertSame('2026-11-11T11:15:00Z', Time::format($post->poll->expiresAt));
	}

	public function testAScheduledPollCannotExpireBeforeItsPublication(): void
	{
		$response = $this->app->handle(new Request(
			method: 'POST',
			path: '/api/mastodon/v1/statuses',
			body: json_encode([
				'status' => 'Too early poll',
				'visibility' => 'public',
				'scheduled_at' => $this->in(900),
				'poll' => [
					'options' => ['Yes', 'No'],
					'expires_at' => $this->in(600),
				],
			], JSON_THROW_ON_ERROR),
			userId: 'alice',
			scopes: ['write:statuses'],
		));

		self::assertSame(422, $response->status);
		self::assertSame(0, $this->postCount());
	}

	public function testAScheduledPollIdempotencyKeyRejectsADifferentPoll(): void
	{
		$body = [
			'status' => 'Keyed poll',
			'visibility' => 'public',
			'scheduled_at' => $this->in(900),
			'poll' => [
				'options' => ['Yes', 'No'],
				'expires_in' => 3600,
			],
		];
		$send = function (array $payload) {
			return $this->app->handle(new Request(
				method: 'POST',
				path: '/api/mastodon/v1/statuses',
				body: json_encode($payload, JSON_THROW_ON_ERROR),
				headers: ['idempotency-key' => 'poll-key-1'],
				userId: 'alice',
				scopes: ['write:statuses'],
			));
		};

		self::assertSame(200, $send($body)->status);
		self::assertSame(200, $send($body)->status);

		$changed = $body;
		$changed['poll']['options'] = ['Yes', 'Maybe', 'No'];
		$conflict = $send($changed);
		self::assertSame(409, $conflict->status);
		self::assertSame('idempotency_conflict', $conflict->decodedBody()['error']['code']);
	}

	public function testAFailedPublicationIsRecordedAndDeadLettered(): void
	{
		$parent = $this->parentPost();
		$this->app->posts->delete('alice', $parent->id);

		$id = $this->app->handle($this->createRequest(
			'Reply to a deleted post',
			['in_reply_to_id' => $parent->id],
		))->decodedBody()['scheduled_post']['id'];

		$this->clock->advance(600);
		$this->app->worker->runOnce();

		self::assertSame(0, $this->postCount());
		self::assertSame(1, $this->app->queue->deadLetterCount());

		$stored = $this->show($id)->decodedBody()['scheduled_post'];
		self::assertSame('failed', $stored['state']);
		self::assertNotNull($stored['failure_reason']);
	}

	/**
	 * @param array<string, mixed> $extraConfig
	 */
	private function app(array $extraConfig = []): Application
	{
		return Application::inMemory(
			clock: $this->clock,
			config: new ArrayConfig([InstanceInfo::CONFIG_APP => array_merge([
				'instance.url' => 'https://localhost:8080',
				'mastodon.api' => 'true',
			], $extraConfig)]),
			localActors: new InMemoryActorDirectory(['alice', 'bob']),
			activityLog: new InMemoryActivityLog(),
			scheduledPostRepository: $this->scheduled,
		);
	}

	/**
	 * @param array<string, mixed> $extra
	 */
	private function createRequest(string $content, array $extra = []): Request
	{
		return new Request(
			method: 'POST',
			path: '/api/v1/scheduled-posts',
			body: json_encode(array_merge([
				'content' => $content,
				'scheduled_at' => $this->in(600),
			], $extra), JSON_THROW_ON_ERROR),
			userId: 'alice',
		);
	}

	private function create(string $content, string $scheduledAt, array $extra = []): Response
	{
		return $this->app->handle(new Request(
			method: 'POST',
			path: '/api/v1/scheduled-posts',
			body: json_encode(array_merge([
				'content' => $content,
				'scheduled_at' => $scheduledAt,
			], $extra), JSON_THROW_ON_ERROR),
			userId: 'alice',
		));
	}

	private function show(string $id): Response
	{
		return $this->app->handle(new Request(
			method: 'GET',
			path: '/api/v1/scheduled-posts/' . $id,
			userId: 'alice',
		));
	}

	private function parentPost(): Post
	{
		return $this->app->posts->create(new CreatePost(authorId: 'alice', content: 'Parent'));
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function publicTimeline(): array
	{
		return $this->app->handle(new Request('GET', '/api/v1/timelines/public'))
			->decodedBody()['posts'];
	}

	private function postCount(): int
	{
		$timeline = $this->app->handle(new Request('GET', '/api/v1/timelines/public'));

		return count($timeline->decodedBody()['posts']);
	}

	private function in(int $seconds): string
	{
		return Time::format($this->clock->now()->modify('+' . $seconds . ' seconds'));
	}
}
