<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Api;

use AlohaSocial\Core\Application;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Domain\Recommendation\InMemoryRecommendationCandidateSource;
use AlohaSocial\Core\Domain\Recommendation\RecommendationCandidate;
use AlohaSocial\Core\Domain\Recommendation\RecommendationCandidateKind;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

final class RecommendationsTest extends TestCase
{
	private const ALICE = 'alice';

	public function testTheFeedPreferencesSignalsExportAndResetWorkForTheOwner(): void
	{
		$app = $this->app(true);
		$feed = $app->handle($this->request('GET', '/api/v1/recommendations'));
		self::assertSame(200, $feed->status);
		self::assertSame('local:post-1', $feed->decodedBody()['items'][0]['candidate']['candidate_id']);

		$preferences = $app->handle($this->request('PATCH', '/api/v1/recommendations/preferences', [
			'behavioral_consent' => true,
			'interests' => ['#PHP'],
		]));
		self::assertSame(200, $preferences->status);
		self::assertTrue($preferences->decodedBody()['preference']['behavioral_consent']);
		self::assertSame(['php'], $preferences->decodedBody()['preference']['interests']);

		$signal = $app->handle($this->request('POST', '/api/v1/recommendations/signals', [
			'candidate_id' => 'local:post-1',
			'kind' => 'like',
		]));
		self::assertSame(201, $signal->status);
		self::assertSame('like', $signal->decodedBody()['signal']['kind']);

		$feedback = $app->handle($this->request('POST', '/api/v1/recommendations/feedback', [
			'candidate_id' => 'local:post-1',
			'action' => 'more',
		]));
		self::assertSame(200, $feedback->status);

		$export = $app->handle($this->request('GET', '/api/v1/recommendations/export'));
		self::assertSame(200, $export->status);
		self::assertCount(1, $export->decodedBody()['signals']);
		self::assertCount(1, $export->decodedBody()['feedback']);

		$reset = $app->handle($this->request('DELETE', '/api/v1/recommendations/data'));
		self::assertSame(204, $reset->status);
		$after = $app->handle($this->request('GET', '/api/v1/recommendations/preferences'));
		self::assertFalse($after->decodedBody()['preference']['behavioral_consent']);
		self::assertSame([], $after->decodedBody()['preference']['interests']);
	}

	public function testSignalsWithoutConsentAreForbidden(): void
	{
		$app = $this->app(true);

		$response = $app->handle($this->request('POST', '/api/v1/recommendations/signals', [
			'candidate_id' => 'local:post-1',
			'kind' => 'click',
		]));

		self::assertSame(403, $response->status);
		self::assertSame('recommendation_consent_required', $response->decodedBody()['error']['code']);
	}

	public function testDisabledFeatureAnswers503OnEveryRoute(): void
	{
		$app = $this->app(false);

		$feed = $app->handle($this->request('GET', '/api/v1/recommendations'));
		$preferences = $app->handle($this->request('GET', '/api/v1/recommendations/preferences'));

		self::assertSame(503, $feed->status);
		self::assertSame(503, $preferences->status);
		self::assertSame('recommendations_disabled', $feed->decodedBody()['error']['code']);
	}

	public function testAnonymousAndMalformedCursorRequestsAreRefused(): void
	{
		$app = $this->app(true);

		$anonymous = $app->handle(new Request('GET', '/api/v1/recommendations'));
		$invalid = $app->handle(new Request(
			method: 'GET',
			path: '/api/v1/recommendations',
			query: ['cursor' => 'not-a-cursor'],
			userId: self::ALICE,
		));

		self::assertSame(401, $anonymous->status);
		self::assertSame(400, $invalid->status);
		self::assertSame('invalid_cursor', $invalid->decodedBody()['error']['code']);
	}

	private function app(bool $enabled): Application
	{
		$clock = new FrozenClock(Time::parse('2026-11-15T12:00:00Z'));
		$candidate = new RecommendationCandidate(
			'local:post-1',
			'post-1',
			RecommendationCandidateKind::Local,
			'bob',
			null,
			'A #php post',
			null,
			$clock->now()->modify('-60 seconds'),
			Visibility::Public,
		);

		return Application::inMemory(
			clock: $clock,
			config: new ArrayConfig([
				'social' => ['recommendations.enabled' => $enabled ? 'true' : 'false'],
			]),
			recommendationCandidates: new InMemoryRecommendationCandidateSource([$candidate]),
		);
	}

	/**
	 * @param array<string, mixed>|null $body
	 */
	private function request(string $method, string $path, ?array $body = null): Request
	{
		return new Request(
			method: $method,
			path: $path,
			headers: $body === null ? [] : ['Content-Type' => 'application/json'],
			body: $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR),
			userId: self::ALICE,
		);
	}
}
