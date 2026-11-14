<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Service;

use AlohaSocial\Core\Domain\Federation\ActorInboxResolver;
use AlohaSocial\Core\Domain\Federation\InMemoryRemotePollRepository;
use AlohaSocial\Core\Domain\Federation\InMemoryRemotePollVoteRepository;
use AlohaSocial\Core\Domain\Federation\InboxRejectedException;
use AlohaSocial\Core\Domain\Federation\RemoteActor;
use AlohaSocial\Core\Domain\Federation\RemotePollRepository;
use AlohaSocial\Core\Domain\Federation\RemotePollVoteRepository;
use AlohaSocial\Core\Domain\Post\InMemoryPollVoteRepository;
use AlohaSocial\Core\Domain\Post\InMemoryPostRepository;
use AlohaSocial\Core\Domain\Post\Poll;
use AlohaSocial\Core\Domain\Post\PollOption;
use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Jobs\Job;
use AlohaSocial\Core\Jobs\JobType;
use AlohaSocial\Core\Jobs\InMemoryJobQueue;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;
use AlohaSocial\Core\Service\Federation\RemotePollException;
use AlohaSocial\Core\Service\Federation\RemotePollService;
use AlohaSocial\Core\Service\Federation\RemotePostFactory;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Core\Tests\Support\SequenceIdGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Remote Question import, optimistic voting, signed delivery and inbound
 * votes on local polls.
 */
final class RemotePollServiceTest extends TestCase
{
	private const ACTOR = 'https://remote.example/users/bob';

	private FrozenClock $clock;

	private InMemoryJobQueue $queue;

	private InMemoryPostRepository $posts;

	private InMemoryPollVoteRepository $localVotes;

	private RemotePollRepository $polls;

	private RemotePollVoteRepository $votes;

	private RemotePollService $service;

	protected function setUp(): void
	{
		$this->clock = new FrozenClock(Time::parse('2026-11-11T10:00:00Z'));
		$this->queue = new InMemoryJobQueue($this->clock, new SequenceIdGenerator('job-'));
		$this->posts = new InMemoryPostRepository();
		$this->localVotes = new InMemoryPollVoteRepository($this->posts);
		$this->polls = new InMemoryRemotePollRepository();
		$this->votes = new InMemoryRemotePollVoteRepository();

		$this->service = new RemotePollService(
			$this->polls,
			$this->votes,
			$this->localVotes,
			$this->posts,
			new ActivityFactory(new ArrayConfig(['social' => ['instance.url' => 'https://localhost:8080']])),
			$this->queue,
			new class () implements ActorInboxResolver {
				public function inboxFor(string $actorId): ?string
				{
					return 'https://remote.example/users/bob/inbox';
				}
			},
			$this->clock,
		);
	}

	public function testAQuestionIsImportedWithOptionsAndACloseJob(): void
	{
		$poll = $this->import($this->question());

		self::assertSame('Lunch?', $poll->title);
		self::assertFalse($poll->multiple);
		self::assertSame(['Pizza', 'Sushi'], array_map(
			static fn ($option): string => $option->title,
			$poll->options,
		));
		self::assertSame(4, $poll->votesCount());
		self::assertSame('2026-12-01T12:00:00Z', Time::format($poll->endsAt));
		self::assertSame(1, $this->queue->pendingCount());
	}

	public function testAnAnyOfQuestionIsMultipleChoice(): void
	{
		$question = $this->question();
		$question['anyOf'] = $question['oneOf'];
		unset($question['oneOf']);

		self::assertTrue($this->import($question)->multiple);
	}

	public function testAMalformedQuestionIsRefused(): void
	{
		$question = $this->question();
		$question['oneOf'] = [['name' => 'Only one']];

		$this->expectException(InboxRejectedException::class);

		$this->import($question);
	}

	public function testAnInvalidEndTimeIsRefused(): void
	{
		$question = $this->question();
		$question['endTime'] = 'soon';

		$this->expectException(InboxRejectedException::class);

		$this->import($question);
	}

	public function testAVoteIsStoredOptimisticallyAndDelivered(): void
	{
		$this->import($this->question());

		$poll = $this->service->vote('alice', 'https://remote.example/questions/1', [0], $this->clock->now());

		self::assertTrue($poll['voted']);
		self::assertSame([0], $poll['own_votes']);
		self::assertSame('queued', $poll['delivery']);
		self::assertSame(4, $poll['options'][0]['votes_count']);
		self::assertSame(5, $poll['votes_count']);

		$jobs = $this->queue->reserve(10, $this->clock->now());
		$voteJob = null;
		foreach ($jobs as $job) {
			if ($job->type === JobType::FEDERATION_DELIVER) {
				$voteJob = $job;
			}
		}

		self::assertNotNull($voteJob);
		self::assertSame('Pizza', $voteJob->payload['activity']['object']['name']);
		self::assertSame('https://remote.example/questions/1', $voteJob->payload['activity']['object']['inReplyTo']);
		self::assertSame('https://remote.example/users/bob/inbox', $voteJob->payload['inbox']);
	}

	public function testASingleChoiceVoteRefusesTwoOptions(): void
	{
		$this->import($this->question());

		$this->expectException(RemotePollException::class);
		$this->expectExceptionMessage('one choice');

		$this->service->vote('alice', 'https://remote.example/questions/1', [0, 1], $this->clock->now());
	}

	public function testAMultipleChoiceVoteAcceptsTwoOptionsAndOneNotePerChoice(): void
	{
		$question = $this->question();
		$question['anyOf'] = $question['oneOf'];
		unset($question['oneOf']);
		$this->import($question);

		$poll = $this->service->vote('alice', 'https://remote.example/questions/1', [0, 1], $this->clock->now());

		self::assertSame([0, 1], $poll['own_votes']);
		self::assertSame(2, $this->countDeliveryJobs());
	}

	public function testAnIdenticalRetryDoesNotQueueAgainAndADifferentVoteIsRefused(): void
	{
		$this->import($this->question());
		$this->service->vote('alice', 'https://remote.example/questions/1', [0], $this->clock->now());

		$this->service->vote('alice', 'https://remote.example/questions/1', [0], $this->clock->now());
		self::assertSame(1, $this->countDeliveryJobs());

		$this->expectException(RemotePollException::class);
		$this->expectExceptionMessage('already voted');

		$this->service->vote('alice', 'https://remote.example/questions/1', [1], $this->clock->now());
	}

	public function testVotingAfterTheEndIsRefused(): void
	{
		$this->import($this->question());
		$this->clock->set(Time::parse('2026-12-01T12:00:00Z'));

		$this->expectException(RemotePollException::class);
		$this->expectExceptionMessage('ended');

		$this->service->vote('alice', 'https://remote.example/questions/1', [0], $this->clock->now());
	}

	public function testClosingAPollIsIdempotent(): void
	{
		$this->import($this->question());

		self::assertTrue($this->service->close('https://remote.example/questions/1', $this->clock->now()));
		self::assertFalse($this->service->close('https://remote.example/questions/1', $this->clock->now()));
		self::assertTrue($this->service->present('https://remote.example/questions/1', null)['closed']);
	}

	public function testTheCloseJobMarksAnImportedPollClosed(): void
	{
		$this->import($this->question());

		$handler = new \AlohaSocial\Core\Service\Federation\RemotePollCloseHandler($this->service, $this->clock);
		$handler->handle(new Job('close-1', JobType::POLL_CLOSE, ['object_id' => 'https://remote.example/questions/1'], $this->clock->now()));

		self::assertTrue($this->service->present('https://remote.example/questions/1', null)['closed']);
		self::assertNotNull($this->polls->find('https://remote.example/questions/1')?->closedAt);
	}

	public function testASignedVoteOnALocalPollIsRecordedNotImported(): void
	{
		$post = $this->localPoll('post-1');
		$this->posts->insert($post);

		$recorded = $this->service->recordInboundVote(
			$this->remoteActor(),
			$this->voteNote($post->id, 'Yes'),
			$this->clock->now(),
		);

		self::assertTrue($recorded);
		$vote = $this->localVotes->find('post-1', self::ACTOR);
		self::assertNotNull($vote);
		self::assertSame([0], $vote->positions);
	}

	public function testAReplayedLocalVoteIsIdempotentAndADifferentChoiceIsRefused(): void
	{
		$post = $this->localPoll('post-1');
		$this->posts->insert($post);

		self::assertTrue($this->service->recordInboundVote($this->remoteActor(), $this->voteNote('post-1', 'Yes'), $this->clock->now()));
		self::assertTrue($this->service->recordInboundVote($this->remoteActor(), $this->voteNote('post-1', 'Yes'), $this->clock->now()));

		$this->expectException(InboxRejectedException::class);
		$this->expectExceptionMessage('already voted');

		$this->service->recordInboundVote($this->remoteActor(), $this->voteNote('post-1', 'No'), $this->clock->now());
	}

	public function testAReplyToAPostWithoutAPollIsNotAVote(): void
	{
		$this->posts->insert(new Post(
			id: 'post-2',
			authorId: 'alice',
			content: 'No poll here',
			visibility: Visibility::Public,
			publishedAt: Time::parse('2026-11-11T09:00:00Z'),
		));

		self::assertFalse($this->service->recordInboundVote(
			$this->remoteActor(),
			$this->voteNote('post-2', 'Yes'),
			$this->clock->now(),
		));
	}

	public function testAVoteOnAnExpiredLocalPollIsRefused(): void
	{
		$this->posts->insert($this->localPoll('post-1', Time::parse('2026-11-10T10:00:00Z')));

		$this->expectException(InboxRejectedException::class);
		$this->expectExceptionMessage('ended');

		$this->service->recordInboundVote($this->remoteActor(), $this->voteNote('post-1', 'Yes'), $this->clock->now());
	}

	/**
	 * @param array<string, mixed> $question
	 */
	private function import(array $question): \AlohaSocial\Core\Domain\Federation\RemotePoll
	{
		$factory = new RemotePostFactory($this->clock);
		$post = $factory->fromCreate(self::ACTOR, [
			'id' => 'https://remote.example/activities/1',
			'type' => 'Create',
			'actor' => self::ACTOR,
			'object' => $question,
		]);

		return $this->service->importQuestion(
			$post,
			$question,
			'https://remote.example/activities/1',
			$this->clock->now(),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function question(): array
	{
		return [
			'id' => 'https://remote.example/questions/1',
			'type' => 'Question',
			'name' => 'Lunch?',
			'oneOf' => [
				['type' => 'Note', 'name' => 'Pizza', 'replies' => ['type' => 'Collection', 'totalItems' => 3]],
				['type' => 'Note', 'name' => 'Sushi', 'replies' => ['type' => 'Collection', 'totalItems' => 1]],
			],
			'endTime' => '2026-12-01T12:00:00Z',
			'attributedTo' => self::ACTOR,
		];
	}

	private function localPoll(string $id, ?\DateTimeImmutable $expiresAt = null): Post
	{
		return new Post(
			id: $id,
			authorId: 'alice',
			content: 'Vote',
			visibility: Visibility::Public,
			publishedAt: Time::parse('2026-11-11T09:00:00Z'),
			poll: new Poll(
				options: [new PollOption('Yes'), new PollOption('No')],
				multiple: false,
				hideTotals: false,
				expiresAt: $expiresAt ?? Time::parse('2026-12-01T12:00:00Z'),
			),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function voteNote(string $postId, string $choice): array
	{
		return [
			'id' => 'https://remote.example/notes/vote-' . $choice,
			'type' => 'Note',
			'attributedTo' => self::ACTOR,
			'inReplyTo' => 'https://localhost:8080/api/v1/posts/' . $postId,
			'name' => $choice,
		];
	}

	private function remoteActor(): RemoteActor
	{
		return new RemoteActor(self::ACTOR, self::ACTOR . '#main-key', 'https://remote.example/users/bob/inbox', 'pem');
	}

	private function countDeliveryJobs(): int
	{
		$count = 0;
		foreach ($this->queue->reserve(20, $this->clock->now()) as $job) {
			if ($job->type === JobType::FEDERATION_DELIVER) {
				$count++;
			}
		}

		return $count;
	}
}
