<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Federation;

use AlohaSocial\Core\Domain\Federation\ActorInboxResolver;
use AlohaSocial\Core\Domain\Federation\FollowerRepository;
use AlohaSocial\Core\Domain\Federation\InboxRejectedException;
use AlohaSocial\Core\Domain\Federation\RemoteActor;
use AlohaSocial\Core\Domain\Federation\InboundBlockRepository;
use AlohaSocial\Core\Domain\Federation\RemotePoll;
use AlohaSocial\Core\Domain\Federation\RemotePollOption;
use AlohaSocial\Core\Domain\Federation\RemotePollRepository;
use AlohaSocial\Core\Domain\Federation\RemotePollVote;
use AlohaSocial\Core\Domain\Federation\RemotePollVoteRepository;
use AlohaSocial\Core\Domain\Federation\RemotePost;
use AlohaSocial\Core\Domain\Federation\RemoteObjectType;
use AlohaSocial\Core\Domain\Post\PollVote;
use AlohaSocial\Core\Domain\Post\PollVoteRepository;
use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Post\PostRepository;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Jobs\JobQueue;
use AlohaSocial\Core\Jobs\JobSubject;
use AlohaSocial\Core\Jobs\JobType;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use Exception;
use InvalidArgumentException;
use Throwable;

use function array_is_list;
use function array_map;
use function array_unique;
use function array_values;
use function count;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function mb_strlen;
use function preg_match;
use function rawurldecode;
use function sort;
use function strtolower;
use function trim;

/**
 * Remote Question lifecycle: import, read, local voting and inbound votes.
 *
 * The peer owns the Question and its counts. A local vote is stored
 * optimistically, then delivered as one signed `Create(Note)` per selected
 * choice to the question author's inbox. Inbound votes on a local poll are the
 * mirror image: a signed Note whose `inReplyTo` names a local post with a
 * poll and whose `name` is one of its option titles.
 */
final class RemotePollService
{
	public function __construct(
		private readonly RemotePollRepository $polls,
		private readonly RemotePollVoteRepository $votes,
		private readonly PollVoteRepository $localVotes,
		private readonly PostRepository $posts,
		private readonly ActivityFactory $activities,
		private readonly ?JobQueue $queue,
		private readonly ?ActorInboxResolver $inboxes,
		private readonly Clock $clock,
		private readonly ?InboundBlockRepository $inboundBlocks = null,
		private readonly ?FollowerRepository $followers = null,
		private readonly ?RemotePostAccess $access = null,
	) {
	}

	/**
	 * Parses and stores a Question from a Create or Update.
	 *
	 * A malformed option list or timestamp refuses the activity through the
	 * same stable reasons the rest of the inbox uses.
	 *
	 * @param array<string, mixed> $object
	 * @param DateTimeImmutable $observedAt when the activity reached this instance
	 * @param DateTimeImmutable|null $revisedAt the peer's revision marker; receive time when the peer reports none
	 */
	public function importQuestion(
		RemotePost $post,
		array $object,
		string $activityId,
		DateTimeImmutable $observedAt,
		?DateTimeImmutable $revisedAt = null,
	): RemotePoll {
		$revisedAt ??= $observedAt;
		$poll = $this->parse($object, $post->actorId, $activityId, $revisedAt, $post->audience);
		$existing = $this->polls->find($poll->objectId);

		// A retransmitted older revision must not roll back a newer one or a
		// locally computed close, no matter when each delivery arrived.
		if ($existing !== null && $poll->updatedAt < $existing->updatedAt) {
			return $existing;
		}

		// Local ballots are positional. Once any exist, a revision that
		// reorders, removes or adds option titles would silently
		// reinterpret them, so the option list is frozen: the revision is
		// refused and the stored question stays.
		if ($existing !== null
			&& $this->optionTitles($existing) !== $this->optionTitles($poll)
			&& $this->votes->countsFor($existing->objectId) !== []
		) {
			return $existing;
		}

		// A revision without an addressing signal must not widen or narrow
		// the stored audience: keep the last verified claim.
		if ($poll->audience === null && $existing?->audience !== null) {
			$poll = $poll->withAudience($existing->audience);
		}

		$this->polls->upsert($poll);

		if ($poll->closedAt === null && $poll->endsAt !== null && $poll->endsAt > $observedAt) {
			try {
				// Every revision schedules its own close; coalesce them into
				// the latest deadline so repeated Updates cannot pile up jobs.
				$this->queue?->cancelBySubject(JobSubject::poll($poll->objectId));
				$this->queue?->push(
					JobType::POLL_CLOSE,
					['object_id' => $poll->objectId],
					$poll->endsAt,
					JobSubject::poll($poll->objectId),
				);
			} catch (Throwable) {
				// The poll is stored; a missing close job only delays the
				// stored marker, and `isClosed()` still uses the end time.
			}
		}

		return $poll;
	}

	/**
	 * @param array<string, mixed> $object
	 *
	 * @throws InboxRejectedException
	 */
	private function parse(
		array $object,
		string $actorId,
		string $activityId,
		DateTimeImmutable $revisedAt,
		?Visibility $audience = null,
	): RemotePoll
	{
		$objectId = $object['id'] ?? null;
		if (!is_string($objectId) || trim($objectId) === '' || mb_strlen($objectId) > RemotePost::MAX_ID_LENGTH) {
			throw new InboxRejectedException('invalid_activity', 'A Question needs an object id.');
		}

		$anyOf = $object['anyOf'] ?? null;
		$oneOf = $object['oneOf'] ?? null;
		$multiple = is_array($anyOf) && $anyOf !== [];
		$rawOptions = $multiple ? $anyOf : $oneOf;

		if (!is_array($rawOptions) || !array_is_list($rawOptions)) {
			throw new InboxRejectedException('invalid_activity', 'A Question needs oneOf or anyOf options.');
		}

		$options = [];
		$titles = [];
		foreach ($rawOptions as $rawOption) {
			if (!is_array($rawOption) || !is_string($rawOption['name'] ?? null)) {
				throw new InboxRejectedException('invalid_activity', 'Every poll option needs a name.');
			}

			$votesCount = 0;
			$replies = $rawOption['replies'] ?? null;
			if (is_array($replies) && is_int($replies['totalItems'] ?? null)) {
				$votesCount = $replies['totalItems'];
			}

			try {
				$option = new RemotePollOption($rawOption['name'], $votesCount);
			} catch (InvalidArgumentException $exception) {
				throw new InboxRejectedException('invalid_activity', $exception->getMessage());
			}

			if (in_array($option->title, $titles, true)) {
				throw new InboxRejectedException('invalid_activity', 'A Question cannot repeat an option name.');
			}

			$titles[] = $option->title;
			$options[] = $option;
		}

		$title = $object['name'] ?? null;
		if ($title !== null && !is_string($title)) {
			throw new InboxRejectedException('invalid_activity', 'A Question title must be a string.');
		}

		$votersCount = 0;
		if (is_int($object['votersCount'] ?? null)) {
			$votersCount = $object['votersCount'];
		}

		try {
			return new RemotePoll(
				objectId: trim($objectId),
				actorId: $actorId,
				activityId: $activityId,
				title: $title === null ? null : trim($title),
				options: $options,
				multiple: $multiple,
				endsAt: $this->timestamp($object['endTime'] ?? null, 'endTime'),
				closedAt: $this->timestamp($object['closed'] ?? null, 'closed'),
				votersCount: $votersCount,
				updatedAt: Time::toSecondPrecision($revisedAt),
				audience: $audience,
			);
		} catch (InvalidArgumentException $exception) {
			throw new InboxRejectedException('invalid_activity', $exception->getMessage());
		}
	}

	/**
	 * @return list<string>
	 */
	private function optionTitles(RemotePoll $poll): array
	{
		return array_map(
			static fn (RemotePollOption $option): string => $option->title,
			$poll->options,
		);
	}

	private function timestamp(mixed $value, string $field): ?DateTimeImmutable
	{
		if ($value === null) {
			return null;
		}

		if (!is_string($value) || trim($value) === '') {
			throw new InboxRejectedException('invalid_activity', 'The Question ' . $field . ' must be a timestamp.');
		}

		$value = trim($value);
		if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value) !== 1) {
			throw new InboxRejectedException('invalid_activity', 'The Question ' . $field . ' is not RFC 3339.');
		}

		try {
			return Time::toSecondPrecision(new DateTimeImmutable($value));
		} catch (Exception) {
			throw new InboxRejectedException('invalid_activity', 'The Question ' . $field . ' is invalid.');
		}
	}

	/**
	 * @throws RemotePollException
	 */
	public function find(string $objectId): RemotePoll
	{
		return $this->polls->find($objectId) ?? throw RemotePollException::notFound();
	}

	/**
	 * @return array<string, mixed>
	 *
	 * @throws RemotePollException
	 */
	public function present(string $objectId, ?string $viewerId): array
	{
		$poll = $this->find($objectId);
		if (!$this->viewerMayRead($poll, $viewerId)) {
			throw RemotePollException::notFound();
		}
		$now = $this->clock->now();
		$localCounts = $this->votes->pendingCountsFor($objectId, $poll->updatedAt);
		$own = $viewerId === null ? null : $this->votes->find($objectId, $viewerId);

		$options = [];
		$votesCount = 0;
		foreach ($poll->options as $position => $option) {
			$count = $option->votesCount + ($localCounts[$position] ?? 0);
			$votesCount += $count;
			$options[] = ['title' => $option->title, 'votes_count' => $count];
		}

		return [
			'object_id' => $poll->objectId,
			'actor_id' => $poll->actorId,
			'title' => $poll->title,
			'options' => $options,
			'multiple' => $poll->multiple,
			'ends_at' => $poll->endsAt === null ? null : Time::format($poll->endsAt),
			'closed_at' => $poll->closedAt === null ? null : Time::format($poll->closedAt),
			'closed' => $poll->isClosed($now),
			'votes_count' => $votesCount,
			'voters_count' => $poll->votersCount,
			'voted' => $own !== null,
			'own_votes' => $own?->positions ?? [],
			'delivery' => $own === null ? null : ($own->queued ? 'queued' : 'failed'),
		];
	}

	/**
	 * Stores an optimistic local vote, then queues one signed Note per choice.
	 *
	 * An identical retry is idempotent; an identical retry whose delivery
	 * never reached the queue retries the queueing instead of refusing.
	 *
	 * @param list<int> $positions
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RemotePollException
	 */
	public function vote(string $userId, string $objectId, array $positions, DateTimeImmutable $now): array
	{
		$poll = $this->find($objectId);
		if ($this->inboundBlocks?->isBlocked($userId, $poll->actorId) === true) {
			throw RemotePollException::blockedActor();
		}
		if (!$this->viewerMayRead($poll, $userId)) {
			throw RemotePollException::notFound();
		}
		if ($poll->isClosed($now)) {
			throw RemotePollException::ended();
		}

		$positions = $this->validatePositions($poll, $positions);
		$existing = $this->votes->find($objectId, $userId);
		if ($existing !== null && $existing->positions !== $positions) {
			throw RemotePollException::alreadyVoted();
		}

		if ($existing === null) {
			$existing = new RemotePollVote($objectId, $userId, $positions, $now);
			try {
				$this->votes->insert($existing);
			} catch (Throwable) {
				// A concurrent duplicate became the first row; read it back.
				$existing = $this->votes->find($objectId, $userId);
				if ($existing === null || $existing->positions !== $positions) {
					throw RemotePollException::alreadyVoted();
				}
			}
		}

		if (!$existing->queued) {
			$queued = $this->queueDeliveries($poll, $userId, $positions, $now);
			if ($queued) {
				$this->votes->markQueued($objectId, $userId);
			}
		}

		return $this->present($objectId, $userId);
	}

	/**
	 * The per-viewer read rule shared with remote posts. Without a wired
	 * access policy only verified public audiences stay readable — the
	 * fail-closed direction for a host that did not configure one.
	 */
	private function viewerMayRead(RemotePoll $poll, ?string $viewerId): bool
	{
		if ($this->access === null) {
			return $poll->audience === Visibility::Public || $poll->audience === Visibility::Unlisted;
		}

		return $this->access->audienceAllows($poll->audience, $poll->actorId, $viewerId);
	}

	/**
	 * @param list<int> $positions
	 *
	 * @return list<int>
	 *
	 * @throws RemotePollException
	 */
	private function validatePositions(RemotePoll $poll, array $positions): array
	{
		if (!array_is_list($positions) || $positions === []) {
			throw RemotePollException::invalidChoice('A vote needs at least one choice.');
		}

		if (!$poll->multiple && count($positions) !== 1) {
			throw RemotePollException::invalidChoice('This poll allows one choice.');
		}

		$unique = array_values(array_unique($positions));
		if (count($unique) !== count($positions)) {
			throw RemotePollException::invalidChoice('A vote cannot select one choice twice.');
		}

		foreach ($unique as $position) {
			if (!is_int($position) || $position < 0 || $position >= count($poll->options)) {
				throw RemotePollException::invalidChoice();
			}
		}

		sort($unique);

		return $unique;
	}

	/**
	 * @param list<int> $positions
	 */
	private function queueDeliveries(RemotePoll $poll, string $userId, array $positions, DateTimeImmutable $now): bool
	{
		$inbox = $this->inboxes?->inboxFor($poll->actorId);
		if ($inbox === null || $this->queue === null) {
			throw RemotePollException::deliveryUnavailable();
		}

		try {
			foreach ($positions as $position) {
				$this->queue->push(JobType::FEDERATION_DELIVER, [
					'inbox' => $inbox,
					'activity' => $this->activities->pollVote($userId, $poll, $position, $now),
					'key_id' => $this->activities->keyId($userId),
				], subject: JobSubject::account($userId));
			}
		} catch (Throwable) {
			return false;
		}

		return true;
	}

	/**
	 * Records a signed vote Note aimed at one of our local polls.
	 *
	 * Returns false when the object is an ordinary reply: the caller then
	 * imports it as a remote post. A vote must name an option of the target's
	 * poll, so a mention or a reply never counts as a vote.
	 *
	 * @param array<string, mixed> $object
	 *
	 * @throws InboxRejectedException
	 */
	public function recordInboundVote(RemoteActor $actor, array $object, DateTimeImmutable $now): bool
	{
		$type = RemoteObjectType::fromActivityType($object['type'] ?? null);
		$inReplyTo = $object['inReplyTo'] ?? null;
		$name = $object['name'] ?? null;
		if ($type !== RemoteObjectType::Note || !is_string($inReplyTo) || !is_string($name)) {
			return false;
		}

		$postId = $this->localPostId($inReplyTo);
		if ($postId === null) {
			return false;
		}

		$post = $this->posts->find($postId);
		if ($post === null || $post->isDeleted() || $post->poll === null) {
			return false;
		}

		$this->requireVoteAudience($post, $actor);

		$poll = $post->poll;
		if ($poll->isExpired($now)) {
			throw new InboxRejectedException('poll_ended', 'This poll has ended.');
		}

		$position = null;
		$title = trim($name);
		foreach ($poll->options as $index => $option) {
			if ($option->title === $title || strtolower($option->title) === strtolower($title)) {
				$position = $index;
				break;
			}
		}

		if ($position === null) {
			throw new InboxRejectedException('invalid_choice', 'The vote names no option of this poll.');
		}

		$existing = $this->localVotes->find($post->id, $actor->actorId);
		if ($existing !== null) {
			if (in_array($position, $existing->positions, true)) {
				return true;
			}

			if (!$poll->multiple) {
				throw new InboxRejectedException('already_voted', 'This actor already voted in this poll.');
			}

			// A multiple-choice poll takes one signed choice per activity,
			// so a second distinct choice extends the ballot instead of
			// conflicting with the first.
			try {
				$this->localVotes->addPosition($post->id, $actor->actorId, $position, $now);
			} catch (Throwable) {
				$existing = $this->localVotes->find($post->id, $actor->actorId);
				if ($existing === null || !in_array($position, $existing->positions, true)) {
					throw new InboxRejectedException('already_voted', 'This actor already voted in this poll.');
				}
			}

			return true;
		}

		try {
			$this->localVotes->insert(new PollVote($post->id, $actor->actorId, [$position], $now));
		} catch (Throwable) {
			$existing = $this->localVotes->find($post->id, $actor->actorId);
			if ($existing === null || $existing->positions !== [$position]) {
				throw new InboxRejectedException('already_voted', 'This actor already voted in this poll.');
			}
		}

		return true;
	}

	/**
	 * A remote ballot may only answer a poll its author would show the actor.
	 *
	 * Public and unlisted polls accept any signed choice. A followers-only
	 * poll needs an accepted follow edge from the actor to the author, and a
	 * direct poll never federates a ballot because direct recipients are
	 * local accounts only. Without a follower store the restricted cases
	 * fail closed.
	 */
	private function requireVoteAudience(Post $post, RemoteActor $actor): void
	{
		if ($post->visibility === Visibility::Public || $post->visibility === Visibility::Unlisted) {
			return;
		}

		$allowed = false;
		if ($post->visibility === Visibility::Followers && $this->followers !== null) {
			$edge = $this->followers->findByActor($post->authorId, $actor->actorId);
			$allowed = $edge !== null && $edge->state->isAccepted();
		}

		if (!$allowed) {
			throw new InboxRejectedException('not_authorized', 'This actor may not vote in this poll.');
		}
	}

	/**
	 * Marks a remote poll closed when its scheduled close job runs.
	 */
	public function close(string $objectId, DateTimeImmutable $now): bool
	{
		$poll = $this->polls->find($objectId);
		// A close job is bound to the deadline it was scheduled for: when a
		// newer revision extended `endsAt` past now, the job is stale and the
		// poll stays open. `isClosed` also covers a peer-reported `closed`.
		if ($poll === null || !$poll->isClosed($now)) {
			return false;
		}

		return $this->polls->markClosed($objectId, $now);
	}

	private function localPostId(string $url): ?string
	{
		if (preg_match('~/api/v1/posts/([^/?#]+)$~D', $url, $matches) !== 1) {
			return null;
		}

		return rawurldecode($matches[1]);
	}

}
