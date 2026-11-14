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
use AlohaSocial\Core\Domain\Federation\RemoteActor;
use AlohaSocial\Core\Domain\Moderation\HeldPostRepository;
use AlohaSocial\Core\Domain\Moderation\HeldPostStatus;
use AlohaSocial\Core\Domain\Moderation\InMemoryHeldPostRepository;
use AlohaSocial\Core\Domain\Moderation\InMemoryModerationActionRepository;
use AlohaSocial\Core\Domain\Moderation\InMemoryReportRepository;
use AlohaSocial\Core\Domain\Moderation\ModerationActionRepository;
use AlohaSocial\Core\Domain\Moderation\ModerationDecision;
use AlohaSocial\Core\Domain\Moderation\PostHoldPolicy;
use AlohaSocial\Core\Domain\Moderation\ReportRepository;
use AlohaSocial\Core\Domain\Moderation\ReportStatus;
use AlohaSocial\Core\Domain\Moderation\StoredModerationPolicy;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Platform\Moderation\AdminAuthorization;
use AlohaSocial\Core\Protocol\ActivityPub\HttpSignature;
use AlohaSocial\Core\Protocol\ActivityPub\RsaKeyPair;
use AlohaSocial\Core\Service\Federation\FederationTargetGuard;
use AlohaSocial\Core\Service\Federation\RemoteFetchException;
use AlohaSocial\Core\Service\InstanceInfo;
use AlohaSocial\Core\Service\Moderation\ModerationForbiddenException;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Core\Tests\Support\FakeHttpClient;
use AlohaSocial\Core\Tests\Support\InMemoryActorResolver;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function json_encode;
use function str_contains;

/**
 * End-to-end moderation: local reports, the moderator queue, actor/domain
 * actions, a signed remote Flag, timeline policy and first-post holds.
 */
final class ModerationTest extends TestCase
{
	private const REMOTE_ACTOR = 'https://remote.example/users/bob';

	private const REMOTE_KEY = 'https://remote.example/users/bob#main-key';

	private static RsaKeyPair $remoteKey;

	private static RsaKeyPair $localKey;

	private FrozenClock $clock;

	private FakeHttpClient $http;

	private ReportRepository $reports;

	private ModerationActionRepository $actions;

	private HeldPostRepository $heldPosts;

	private Application $app;

	public static function setUpBeforeClass(): void
	{
		self::$remoteKey = RsaKeyPair::generate();
		self::$localKey = RsaKeyPair::generate();
	}

	protected function setUp(): void
	{
		$this->clock = new FrozenClock(Time::parse('2026-11-14T10:00:00Z'));
		$this->http = new FakeHttpClient();
		$this->reports = new InMemoryReportRepository();
		$this->actions = new InMemoryModerationActionRepository();
		$this->heldPosts = new InMemoryHeldPostRepository();

		$admins = new class implements AdminAuthorization {
			public function isModerator(string $userId): bool
			{
				return $userId === 'alice';
			}
		};
		$holds = new class implements PostHoldPolicy {
			public function holdReason(string $authorId, string $content): ?string
			{
				return str_contains($content, 'review me') ? 'first_post' : null;
			}
		};

		$this->app = Application::inMemory(
			clock: $this->clock,
			config: new ArrayConfig([InstanceInfo::CONFIG_APP => [
				'instance.url' => 'https://localhost:8080',
				'mastodon.api' => 'true',
			]]),
			localActors: new InMemoryActorDirectory(['alice', 'bob']),
			httpClient: $this->http,
			signingKey: self::$localKey,
			actorResolver: new InMemoryActorResolver([
				self::REMOTE_KEY => new RemoteActor(
					self::REMOTE_ACTOR,
					self::REMOTE_KEY,
					'https://remote.example/users/bob/inbox',
					self::$remoteKey->publicKeyPem(),
				),
			]),
			activityLog: new InMemoryActivityLog(),
			reportRepository: $this->reports,
			moderationActions: $this->actions,
			heldPostRepository: $this->heldPosts,
			adminAuthorization: $admins,
			postHoldPolicy: $holds,
		);
	}

	public function testReportSubmissionDeduplicatesAndRejectsForgedTargets(): void
	{
		$post = $this->post('bob', 'reportable post');

		$response = $this->report('alice', 'post', $post['id'], 'spam', 'Looks like spam.', ['one']);
		self::assertSame(201, $response->status, $response->body);
		$report = $response->decodedBody()['report'];
		self::assertSame('pending', $report['status']);
		self::assertSame('alice', $report['reporter_id']);
		self::assertSame('post', $report['target_type']);
		self::assertSame($post['id'], $report['target_id']);

		$repeat = $this->report('alice', 'post', $post['id'], 'spam');
		self::assertSame(201, $repeat->status);
		self::assertSame($report['id'], $repeat->decodedBody()['report']['id'], 'A repeated open report is idempotent.');
		self::assertSame(1, $this->reports->countOpen());

		self::assertSame(422, $this->report('bob', 'post', $post['id'], 'spam')->status);
		self::assertSame(422, $this->report('alice', 'actor', 'not a url', 'other')->status);
		self::assertSame(422, $this->report('alice', 'post', 'missing-post', 'spam')->status);
		self::assertSame(422, $this->report('alice', 'actor', self::REMOTE_ACTOR, 'unknown-reason')->status);
		self::assertSame(422, $this->report('alice', 'domain', 'not a domain', 'spam')->status);
	}

	public function testModeratorSuspendsAnActorAndTimelinePolicyHidesItUntilReversal(): void
	{
		$post = $this->post('bob', 'bob is here');
		$report = $this->report('alice', 'post', $post['id'], 'harassment', 'abusive')->decodedBody()['report'];

		$resolved = $this->app->moderation?->resolve('alice', $report['id'], 'suspend_actor', 'confirmed');
		self::assertNotNull($resolved);
		self::assertSame(ReportStatus::Resolved, $resolved->status);
		self::assertSame(ModerationDecision::SuspendActor, $resolved->decision);
		self::assertSame('alice', $resolved->moderatorId);

		$actions = $this->actions->active();
		self::assertCount(1, $actions);
		self::assertSame('bob', $actions[0]->target);
		self::assertSame(0, $this->reports->countOpen());

		self::assertFalse((new StoredModerationPolicy($this->actions))->allowsActor('bob'));
		self::assertSame([], $this->app->handle(new Request('GET', '/api/v1/timelines/public'))->decodedBody()['posts']);

		$again = $this->app->moderation?->resolve('alice', $report['id'], 'suspend_actor');
		self::assertSame(ReportStatus::Resolved, $again?->status, 'Resolving twice is idempotent.');
		self::assertSame(1, count($this->actions->active()));

		$reversed = $this->app->moderation?->reverseAction('alice', $actions[0]->id, 'appeal accepted');
		self::assertNotNull($reversed);
		self::assertFalse($reversed->isActive());
		self::assertTrue((new StoredModerationPolicy($this->actions))->allowsActor('bob'));
		self::assertCount(1, $this->app->handle(new Request('GET', '/api/v1/timelines/public'))->decodedBody()['posts']);
	}

	public function testDomainBlockStopsDeliveryAndRemoteInboxIngest(): void
	{
		$report = $this->report('alice', 'domain', self::REMOTE_ACTOR, 'spam');
		$id = $report->decodedBody()['report']['id'];

		$this->app->moderation?->resolve('alice', $id, 'block_domain', 'blocked server');
		self::assertFalse((new StoredModerationPolicy($this->actions))->allowsDomain('remote.example'));

		$guard = new FederationTargetGuard(null, new StoredModerationPolicy($this->actions));
		try {
			$guard->addressesFor(self::REMOTE_ACTOR . '/inbox');
			self::fail('A blocked destination must not reach DNS or delivery.');
		} catch (RemoteFetchException $exception) {
			self::assertSame('moderation_blocked', $exception->reason);
		}

		$response = $this->deliver([
			'id' => 'https://remote.example/activities/flag-blocked',
			'type' => 'Flag',
			'actor' => self::REMOTE_ACTOR,
			'object' => 'https://remote.example/users/bob',
			'content' => 'please review',
		]);
		// The actor guard refuses to resolve a blocked domain, so the inbox
		// answers the same 404 as an unknown actor instead of disclosing policy.
		self::assertSame(404, $response->status, $response->body);
		self::assertSame('unknown_actor', $response->decodedBody()['error']['code']);
	}

	public function testASuspendedRemoteActorIsRefusedAtTheInbox(): void
	{
		$report = $this->report('alice', 'actor', self::REMOTE_ACTOR, 'spam');
		$this->app->moderation?->resolve(
			'alice',
			$report->decodedBody()['report']['id'],
			'suspend_actor',
			'confirmed',
		);

		$response = $this->deliver([
			'id' => 'https://remote.example/activities/flag-suspended',
			'type' => 'Flag',
			'actor' => self::REMOTE_ACTOR,
			'object' => 'https://remote.example/users/bob',
			'content' => 'please review',
		]);
		self::assertSame(403, $response->status, $response->body);
		self::assertSame('moderation_blocked', $response->decodedBody()['error']['code']);
	}

	public function testASignedFlagFilesOneReportWithBoundedEvidence(): void
	{
		$flag = [
			'id' => 'https://remote.example/activities/flag-1',
			'type' => 'Flag',
			'actor' => self::REMOTE_ACTOR,
			'object' => 'https://remote.example/users/bob',
			'content' => 'This account is spamming every local post.',
		];

		self::assertSame(202, $this->deliver($flag)->status);
		self::assertSame(1, $this->reports->countOpen());

		$queue = $this->app->moderation?->queue('alice');
		self::assertNotNull($queue);
		self::assertSame(self::REMOTE_ACTOR, $queue[0]['target_id']);
		self::assertSame('actor', $queue[0]['target_type']);
		self::assertSame('other', $queue[0]['reason']);
		self::assertSame([$flag['content']], $queue[0]['evidence']);

		self::assertSame(202, $this->deliver($flag)->status, 'A replayed Flag is accepted without a second report.');
		self::assertSame(1, $this->reports->countOpen());
	}

	public function testAFirstPostHoldIsInvisibleUntilModeratorApproval(): void
	{
		$heldResponse = $this->postRaw('alice', 'please review me before this is public');
		self::assertSame(202, $heldResponse->status, $heldResponse->body);
		$held = $heldResponse->decodedBody();
		self::assertTrue($held['held']);
		self::assertSame('first_post', $held['held_post']['reason']);

		self::assertSame([], $this->app->handle(new Request('GET', '/api/v1/timelines/public'))->decodedBody()['posts']);
		self::assertSame(1, count($this->heldPosts->queue(10)));

		$approved = $this->app->heldPosts?->approve('alice', $held['held_post']['id'], 'welcome');
		self::assertNotNull($approved);
		self::assertSame(HeldPostStatus::Approved, $approved->status);
		self::assertNotNull($approved->postId);

		$timeline = $this->app->handle(new Request('GET', '/api/v1/timelines/public'))->decodedBody()['posts'];
		self::assertCount(1, $timeline);
		self::assertSame($approved->postId, $timeline[0]['id']);

		$again = $this->app->heldPosts?->approve('alice', $held['held_post']['id']);
		self::assertSame($approved->postId, $again?->postId, 'Approving twice publishes once.');
		self::assertCount(1, $this->app->handle(new Request('GET', '/api/v1/timelines/public'))->decodedBody()['posts']);

		$second = $this->postRaw('alice', 'please review me too')->decodedBody();
		$rejected = $this->app->heldPosts?->reject('alice', $second['held_post']['id'], 'not welcome');
		self::assertNotNull($rejected);
		self::assertSame(HeldPostStatus::Rejected, $rejected->status);
		self::assertNull($rejected->postId);
		self::assertCount(1, $this->app->handle(new Request('GET', '/api/v1/timelines/public'))->decodedBody()['posts']);
	}

	public function testOnlyTheHostsModeratorPortCanReview(): void
	{
		$post = $this->post('bob', 'reported');
		$report = $this->report('alice', 'post', $post['id'], 'spam')->decodedBody()['report'];

		$this->expectException(ModerationForbiddenException::class);
		$this->app->moderation?->queue('bob');
	}

	public function testReportQueueIsModeratorOnlyAndEvidenceIsBounded(): void
	{
		$post = $this->post('bob', 'reported');
		$evidence = [];
		for ($i = 0; $i < 21; ++$i) {
			$evidence[] = 'entry ' . $i;
		}

		self::assertSame(
			422,
			$this->report('alice', 'post', $post['id'], 'spam', null, $evidence)->status,
		);

		self::assertSame(201, $this->report('alice', 'post', $post['id'], 'spam')->status);
		self::assertCount(1, $this->app->moderation?->queue('alice') ?? []);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function post(string $userId, string $content): array
	{
		$response = $this->postRaw($userId, $content);
		self::assertSame(201, $response->status, $response->body);

		return $response->decodedBody()['post'];
	}

	private function postRaw(string $userId, string $content): Response
	{
		return $this->app->handle(new Request(
			method: 'POST',
			path: '/api/v1/posts',
			headers: ['Content-Type' => 'application/json'],
			body: json_encode(['content' => $content], JSON_THROW_ON_ERROR),
			userId: $userId,
		));
	}

	/**
	 * @param list<string> $evidence
	 */
	private function report(
		string $userId,
		string $targetType,
		string $targetId,
		string $reason,
		?string $comment = null,
		array $evidence = [],
	): Response {
		return $this->app->handle(new Request(
			method: 'POST',
			path: '/api/v1/reports',
			headers: ['Content-Type' => 'application/json'],
			body: json_encode([
				'target_type' => $targetType,
				'target_id' => $targetId,
				'reason' => $reason,
				'comment' => $comment,
				'evidence' => $evidence,
			], JSON_THROW_ON_ERROR),
			userId: $userId,
		));
	}

	/**
	 * @param array<string, mixed> $activity
	 */
	private function deliver(array $activity): Response
	{
		$body = json_encode($activity, JSON_THROW_ON_ERROR);
		$headers = HttpSignature::sign(
			self::$remoteKey,
			self::REMOTE_KEY,
			'POST',
			'/inbox',
			'localhost',
			$body,
			$this->clock->now(),
		);

		return $this->app->handle(new Request(
			method: 'POST',
			path: '/inbox',
			headers: $headers,
			body: $body,
		));
	}
}
