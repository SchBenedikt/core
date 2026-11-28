<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Recommendation;

use AlohaSocial\Core\Domain\Recommendation\PdoRecommendationRepository;
use AlohaSocial\Core\Domain\Recommendation\RecommendationFeedback;
use AlohaSocial\Core\Domain\Recommendation\RecommendationFeedbackKind;
use AlohaSocial\Core\Domain\Recommendation\RecommendationPreference;
use AlohaSocial\Core\Domain\Recommendation\RecommendationSchema;
use AlohaSocial\Core\Domain\Recommendation\RecommendationSignal;
use AlohaSocial\Core\Domain\Recommendation\RecommendationSignalKind;
use AlohaSocial\Core\Platform\Db\PdoConnection;
use AlohaSocial\Core\Support\Time;
use PDO;
use PHPUnit\Framework\TestCase;

final class PdoRecommendationRepositoryTest extends TestCase
{
	private PdoRecommendationRepository $repository;

	protected function setUp(): void
	{
		$connection = new PdoConnection(new PDO('sqlite::memory:'));
		RecommendationSchema::create($connection);
		$this->repository = new PdoRecommendationRepository($connection);
	}

	public function testPreferencesRoundTripAndUpdateInPlace(): void
	{
		$at = Time::parse('2026-11-15T12:00:00Z');
		$preference = new RecommendationPreference('alice', true, false, ['php'], ['de'], $at);
		$this->repository->savePreference($preference);

		self::assertSame($preference->userId, $this->repository->preference('alice')?->userId);
		self::assertSame(['php'], $this->repository->preference('alice')?->interests);

		$this->repository->savePreference($preference->with(true, true, ['php', 'sql'], [], $at->modify('+10 seconds')));
		self::assertTrue($this->repository->preference('alice')?->behavioralConsent);
		self::assertSame(['php', 'sql'], $this->repository->preference('alice')?->interests);
		self::assertNull($this->repository->preference('bob'));
	}

	public function testFeedbackIsUpsertedPerOwnerAndCandidate(): void
	{
		$at = Time::parse('2026-11-15T12:00:00Z');
		$this->repository->saveFeedback(new RecommendationFeedback('alice', 'local:1', RecommendationFeedbackKind::More, $at));
		$this->repository->saveFeedback(new RecommendationFeedback('alice', 'local:1', RecommendationFeedbackKind::Hide, $at));
		$this->repository->saveFeedback(new RecommendationFeedback('alice', 'local:2', RecommendationFeedbackKind::Less, $at));
		$this->repository->saveFeedback(new RecommendationFeedback('bob', 'local:1', RecommendationFeedbackKind::More, $at));

		self::assertSame(RecommendationFeedbackKind::Hide, $this->repository->feedback('alice', 'local:1')?->kind);
		self::assertCount(2, $this->repository->feedbackFor('alice'));
		self::assertCount(1, $this->repository->feedbackFor('bob'));
	}

	public function testSignalsAreOrderedNewestFirstAndOwnerScoped(): void
	{
		$at = Time::parse('2026-11-15T12:00:00Z');
		$this->repository->appendSignal(new RecommendationSignal('a', 'alice', RecommendationSignalKind::Click, 'local:1', $at));
		$this->repository->appendSignal(new RecommendationSignal('b', 'alice', RecommendationSignalKind::Like, 'local:1', $at->modify('+1 second')));
		$this->repository->appendSignal(new RecommendationSignal('c', 'bob', RecommendationSignalKind::Like, 'local:1', $at));

		self::assertSame(['b', 'a'], array_map(static fn ($signal): string => $signal->id, $this->repository->signalsFor('alice')));
		self::assertSame(2, $this->repository->signalCount('alice'));
		self::assertSame(1, $this->repository->signalCount('bob'));
	}

	public function testRecentSignalFindsOnlyTheNewestMatchingRowInsideTheWindow(): void
	{
		$at = Time::parse('2026-11-15T12:00:00Z');
		$this->repository->appendSignal(new RecommendationSignal('old', 'alice', RecommendationSignalKind::Click, 'local:1', $at->modify('-2 days')));
		$this->repository->appendSignal(new RecommendationSignal('mid', 'alice', RecommendationSignalKind::Click, 'local:1', $at->modify('-1 day')));
		$this->repository->appendSignal(new RecommendationSignal('other-kind', 'alice', RecommendationSignalKind::Like, 'local:1', $at));
		$this->repository->appendSignal(new RecommendationSignal('other-user', 'bob', RecommendationSignalKind::Click, 'local:1', $at));
		$this->repository->appendSignal(new RecommendationSignal('other-candidate', 'alice', RecommendationSignalKind::Click, 'local:2', $at));

		$recent = $this->repository->recentSignal(
			'alice',
			RecommendationSignalKind::Click,
			'local:1',
			$at->modify('-36 hours'),
		);

		self::assertSame('mid', $recent?->id);
		self::assertNull($this->repository->recentSignal(
			'alice',
			RecommendationSignalKind::Click,
			'local:1',
			$at,
		));
	}

	public function testResetOnlyRemovesTheOwnersRows(): void
	{
		$at = Time::parse('2026-11-15T12:00:00Z');
		foreach (['alice', 'bob'] as $user) {
			$this->repository->savePreference(new RecommendationPreference($user, true, true, [], [], $at));
			$this->repository->saveFeedback(new RecommendationFeedback($user, 'local:1', RecommendationFeedbackKind::More, $at));
			$this->repository->appendSignal(new RecommendationSignal('signal-' . $user, $user, RecommendationSignalKind::Like, 'local:1', $at));
		}

		$this->repository->deleteForUser('alice');

		self::assertNull($this->repository->preference('alice'));
		self::assertSame([], $this->repository->feedbackFor('alice'));
		self::assertSame(0, $this->repository->signalCount('alice'));
		self::assertNotNull($this->repository->preference('bob'));
		self::assertSame(1, $this->repository->signalCount('bob'));
	}
}
