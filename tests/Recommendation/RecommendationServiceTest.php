<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Recommendation;

use AlohaSocial\Core\Domain\Federation\Block;
use AlohaSocial\Core\Domain\Federation\InMemoryBlockRepository;
use AlohaSocial\Core\Domain\Federation\InMemoryMuteRepository;
use AlohaSocial\Core\Domain\Federation\Mute;
use AlohaSocial\Core\Domain\Moderation\AllowsAllModeration;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Domain\Recommendation\InMemoryRecommendationCandidateSource;
use AlohaSocial\Core\Domain\Recommendation\InMemoryRecommendationRepository;
use AlohaSocial\Core\Domain\Recommendation\InMemoryRecommendationSnapshotCache;
use AlohaSocial\Core\Domain\Recommendation\RecommendationCandidate;
use AlohaSocial\Core\Domain\Recommendation\RecommendationCandidateKind;
use AlohaSocial\Core\Domain\Recommendation\RecommendationFeedbackKind;
use AlohaSocial\Core\Domain\Recommendation\RecommendationSignalKind;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Service\Recommendation\DefaultRecommendationRanker;
use AlohaSocial\Core\Service\Recommendation\InvalidRecommendationCursorException;
use AlohaSocial\Core\Service\Recommendation\RecommendationConsentRequiredException;
use AlohaSocial\Core\Service\Recommendation\RecommendationDisabledException;
use AlohaSocial\Core\Service\Recommendation\RecommendationService;
use AlohaSocial\Core\Service\Recommendation\StoredRecommendationEligibility;
use AlohaSocial\Core\Service\ValidationException;
use AlohaSocial\Core\Support\RandomIdGenerator;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class RecommendationServiceTest extends TestCase
{
	private const ALICE = 'alice';

	private FrozenClock $clock;

	private InMemoryRecommendationRepository $store;

	private InMemoryBlockRepository $blocks;

	private InMemoryMuteRepository $mutes;

	protected function setUp(): void
	{
		$this->clock = new FrozenClock(Time::parse('2026-11-15T12:00:00Z'));
		$this->store = new InMemoryRecommendationRepository();
		$this->blocks = new InMemoryBlockRepository();
		$this->mutes = new InMemoryMuteRepository();
	}

	public function testDefaultPreferencesEnableTheFeedButNeverConsentToSignals(): void
	{
		$service = $this->service();

		$preference = $service->preferences(self::ALICE);

		self::assertTrue($preference->enabled);
		self::assertFalse($preference->behavioralConsent);
		self::assertSame([], $preference->interests);
	}

	public function testRankingPrefersAnInterestedFreshPostAndExplainsWhy(): void
	{
		$service = $this->service([
			$this->candidate('local:php', 'bob', 'A useful #php note', $this->ago(3600)),
			$this->candidate('local:other', 'carol', 'A note about gardening', $this->ago(60)),
		]);
		$service->updatePreferences(self::ALICE, ['interests' => ['#PHP']]);

		$page = $service->feed(self::ALICE, 10);

		self::assertTrue($page->enabled);
		self::assertCount(2, $page->items);
		self::assertSame('local:php', $page->items[0]->candidate->id);
		self::assertContains('interest:php', $page->items[0]->reasons);
		self::assertContains('fresh', $page->items[0]->reasons);
		self::assertSame('for-you-v1', $page->algorithmVersion);
	}

	public function testPrivateOwnSensitiveBlockedAndMutedCandidatesNeverEnterTheFeed(): void
	{
		$service = $this->service([
			$this->candidate('local:public', 'bob', 'public', $this->ago(10)),
			$this->candidate('local:private', 'carol', 'private', $this->ago(10), Visibility::Followers),
			$this->candidate('local:own', self::ALICE, 'mine', $this->ago(10)),
			$this->candidate('local:sensitive', 'dave', 'sensitive', $this->ago(10), Visibility::Public, 'content warning'),
			$this->candidate('local:blocked', 'mallory', 'blocked', $this->ago(10)),
			$this->candidate('local:muted', 'trent', 'muted', $this->ago(10)),
		]);
		$this->blocks->insert(new Block('block-1', self::ALICE, 'mallory', $this->clock->now()));
		$this->mutes->save(new Mute(self::ALICE, 'trent', false, $this->clock->now()));

		$ids = array_map(static fn ($item): string => $item->candidate->id, $service->feed(self::ALICE, 20)->items);

		self::assertSame(['local:public'], $ids);
	}

	public function testDiversityCapsConsecutiveAuthorRunsAndPerAuthorCounts(): void
	{
		$service = $this->service([
			$this->candidate('local:a1', 'bob', 'a1', $this->ago(10)),
			$this->candidate('local:a2', 'bob', 'a2', $this->ago(20)),
			$this->candidate('local:a3', 'bob', 'a3', $this->ago(30)),
			$this->candidate('local:a4', 'bob', 'a4', $this->ago(40)),
			$this->candidate('local:b1', 'carol', 'b1', $this->ago(50)),
			$this->candidate('local:c1', 'dave', 'c1', $this->ago(60)),
			$this->candidate('local:d1', 'erin', 'd1', $this->ago(70)),
			$this->candidate('local:e1', 'frank', 'e1', $this->ago(80)),
		]);

		$items = $service->feed(self::ALICE, 6)->items;
		$authors = array_map(static fn ($item): string => $item->candidate->authorId, $items);

		self::assertCount(6, $items);
		self::assertLessThanOrEqual(3, count(array_keys($authors, 'bob', true)));
		$longestRun = 1;
		$run = 1;
		for ($i = 1; $i < count($authors); ++$i) {
			if ($authors[$i] === $authors[$i - 1]) {
				$run++;
				$longestRun = max($longestRun, $run);
			} else {
				$run = 1;
			}
		}
		self::assertLessThanOrEqual(2, $longestRun);
	}

	public function testAnUnderfilledPageRelaxesTheDiversityCapOnlyForTheFallbackTail(): void
	{
		$service = $this->service([
			$this->candidate('local:a1', 'bob', 'a1', $this->ago(10)),
			$this->candidate('local:a2', 'bob', 'a2', $this->ago(20)),
			$this->candidate('local:a3', 'bob', 'a3', $this->ago(30)),
			$this->candidate('local:a4', 'bob', 'a4', $this->ago(40)),
		]);

		$page = $service->feed(self::ALICE, 10);

		self::assertCount(4, $page->items);
		self::assertTrue($page->underfilled);
		self::assertSame('local:a1', $page->items[0]->candidate->id);
	}

	public function testLanguagePreferenceExcludesUnknownLanguages(): void
	{
		$service = $this->service([
			$this->candidate('local:de', 'bob', 'Hallo', $this->ago(10), languages: ['de']),
			$this->candidate('local:unknown', 'carol', 'Hello', $this->ago(10)),
		]);
		$service->updatePreferences(self::ALICE, ['languages' => ['DE']]);

		$page = $service->feed(self::ALICE, 20);

		self::assertSame(['local:de'], array_map(static fn ($item): string => $item->candidate->id, $page->items));
	}

	public function testACursorContinuesTheSameSnapshotWithoutRepeatingItems(): void
	{
		$source = [];
		for ($i = 1; $i <= 5; ++$i) {
			$source[] = $this->candidate('local:' . $i, 'bob', 'post ' . $i, $this->ago($i * 60));
		}
		$service = $this->service($source);

		$first = $service->feed(self::ALICE, 2);
		self::assertCount(2, $first->items);
		self::assertNotNull($first->nextCursor);

		$second = $service->feed(self::ALICE, 2, $first->nextCursor);
		self::assertCount(2, $second->items);
		self::assertSame($first->snapshotId, $second->snapshotId);
		self::assertEmpty(array_intersect(
			array_map(static fn ($item): string => $item->candidate->id, $first->items),
			array_map(static fn ($item): string => $item->candidate->id, $second->items),
		));
	}

	public function testAnExpiredSnapshotRejectsTheCursorInsteadOfRestartingIt(): void
	{
		$service = $this->service([
			$this->candidate('local:1', 'bob', 'one', $this->ago(10)),
			$this->candidate('local:2', 'carol', 'two', $this->ago(20)),
			$this->candidate('local:3', 'dave', 'three', $this->ago(30)),
		]);
		$first = $service->feed(self::ALICE, 1);
		self::assertNotNull($first->nextCursor);

		$this->clock->advance(RecommendationService::SNAPSHOT_TTL_SECONDS + 1);

		$this->expectException(InvalidRecommendationCursorException::class);
		$service->feed(self::ALICE, 1, $first->nextCursor);
	}

	public function testMalformedCursorIsRejected(): void
	{
		$service = $this->service([$this->candidate('local:1', 'bob', 'one', $this->ago(10))]);

		$this->expectException(InvalidRecommendationCursorException::class);
		$service->feed(self::ALICE, 10, 'not a cursor');
	}

	public function testHideFeedbackRemovesACandidateFromTheNextFeed(): void
	{
		$service = $this->service([
			$this->candidate('local:hide', 'bob', 'hide me', $this->ago(10)),
			$this->candidate('local:keep', 'carol', 'keep me', $this->ago(20)),
		]);

		$service->recordFeedback(self::ALICE, 'local:hide', RecommendationFeedbackKind::Hide);
		$page = $service->feed(self::ALICE, 20);

		self::assertSame(['local:keep'], array_map(static fn ($item): string => $item->candidate->id, $page->items));
	}

	public function testSignalsRequireConsentAndExportAndResetStayOwnerScoped(): void
	{
		$service = $this->service([$this->candidate('local:1', 'bob', 'one', $this->ago(10))]);

		try {
			$service->recordSignal(self::ALICE, RecommendationSignalKind::Like, 'local:1');
			self::fail('A signal without consent must be refused.');
		} catch (RecommendationConsentRequiredException) {
			self::assertSame(0, $this->store->signalCount(self::ALICE));
		}

		$service->updatePreferences(self::ALICE, ['behavioral_consent' => true]);
		$service->recordSignal(self::ALICE, RecommendationSignalKind::Like, 'local:1');
		$service->recordFeedback(self::ALICE, 'local:1', RecommendationFeedbackKind::More);
		$this->store->savePreference($service->preferences('bob'));

		$export = $service->export(self::ALICE);
		self::assertCount(1, $export['signals']);
		self::assertCount(1, $export['feedback']);
		self::assertTrue($export['preferences']['behavioral_consent']);
		self::assertCount(0, $service->export('bob')['signals']);

		$service->reset(self::ALICE);
		self::assertSame(0, $this->store->signalCount(self::ALICE));
		self::assertSame([], $this->store->feedbackFor(self::ALICE));
		self::assertFalse($service->preferences(self::ALICE)->behavioralConsent);
	}

	public function testInvalidPreferencesAreRejected(): void
	{
		$service = $this->service([]);

		$this->expectException(ValidationException::class);
		$service->updatePreferences(self::ALICE, ['languages' => ['not a language!']]);
	}

	public function testDisabledFeatureRefusesEveryReadAndWrite(): void
	{
		$service = $this->service([], enabled: false);

		$this->expectException(RecommendationDisabledException::class);
		$service->feed(self::ALICE);
	}

	/**
	 * @param list<RecommendationCandidate> $candidates
	 */
	private function service(array $candidates = [], bool $enabled = true): RecommendationService
	{
		$config = new ArrayConfig([
			'social' => ['recommendations.enabled' => $enabled ? 'true' : 'false'],
		]);

		return new RecommendationService(
			$config,
			new InMemoryRecommendationCandidateSource($candidates),
			$this->store,
			new StoredRecommendationEligibility($this->blocks, $this->mutes, new AllowsAllModeration(), $this->clock),
			new DefaultRecommendationRanker(),
			$this->clock,
			new RandomIdGenerator(),
			new InMemoryRecommendationSnapshotCache(),
		);
	}

	/**
	 * @param list<string> $languages
	 */
	private function candidate(
		string $id,
		string $author,
		string $content,
		DateTimeImmutable $publishedAt,
		Visibility $visibility = Visibility::Public,
		?string $warning = null,
		array $languages = [],
	): RecommendationCandidate {
		return new RecommendationCandidate(
			$id,
			substr($id, 6),
			RecommendationCandidateKind::Local,
			$author,
			null,
			$content,
			$warning,
			$publishedAt,
			$visibility,
			$languages,
		);
	}

	private function ago(int $seconds): DateTimeImmutable
	{
		return $this->clock->now()->modify('-' . $seconds . ' seconds');
	}
}
