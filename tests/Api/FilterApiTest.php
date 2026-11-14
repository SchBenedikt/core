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
use AlohaSocial\Core\Domain\Notification\InMemoryNotificationRepository;
use AlohaSocial\Core\Domain\Notification\Notification;
use AlohaSocial\Core\Domain\Notification\NotificationType;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Service\InstanceInfo;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

use function count;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Keyword filters through the real kernel: neutral CRUD, the Mastodon v2
 * shape, and the reader-visible effects on timelines, detail and
 * notifications.
 */
final class FilterApiTest extends TestCase
{
	private FrozenClock $clock;

	private InMemoryNotificationRepository $notifications;

	private Application $app;

	protected function setUp(): void
	{
		$this->clock = new FrozenClock(Time::parse('2026-11-11T10:00:00Z'));
		$this->notifications = new InMemoryNotificationRepository();
		$this->app = Application::inMemory(
			clock: $this->clock,
			config: new ArrayConfig([InstanceInfo::CONFIG_APP => [
				'instance.url' => 'https://localhost:8080',
				'mastodon.api' => 'true',
			]]),
			localActors: new InMemoryActorDirectory(['alice', 'bob']),
			activityLog: new InMemoryActivityLog(),
			notificationRepository: $this->notifications,
		);
	}

	public function testNeutralCrudRoundTripsAndReplacesKeywords(): void
	{
		$created = $this->request('POST', '/api/v1/filters', [
			'name' => 'Spoilers',
			'action' => 'hide',
			'contexts' => ['home', 'thread'],
			'keywords' => [['text' => 'spoiler', 'whole_word' => true]],
			'expires_at' => '2026-11-11T11:00:00Z',
		]);
		self::assertSame(201, $created->status);
		$rule = $created->decodedBody()['filter'];
		self::assertSame('Spoilers', $rule['name']);
		self::assertSame('hide', $rule['action']);
		self::assertSame(['home', 'thread'], $rule['contexts']);
		self::assertSame('2026-11-11T11:00:00Z', $rule['expires_at']);
		self::assertTrue($rule['keywords'][0]['whole_word']);

		self::assertCount(1, $this->request('GET', '/api/v1/filters')->decodedBody()['filters']);
		self::assertSame('Spoilers', $this->request('GET', '/api/v1/filters/' . $rule['id'])->decodedBody()['filter']['name']);

		$updated = $this->request('PATCH', '/api/v1/filters/' . $rule['id'], [
			'name' => 'Renamed',
			'action' => 'warn',
			'contexts' => ['notifications'],
			'keywords' => [['text' => 'other']],
		])->decodedBody()['filter'];
		self::assertSame('Renamed', $updated['name']);
		self::assertSame('warn', $updated['action']);
		self::assertNull($updated['expires_at']);
		self::assertSame('other', $updated['keywords'][0]['text']);

		$deleted = $this->request('DELETE', '/api/v1/filters/' . $rule['id']);
		self::assertSame(204, $deleted->status);
		self::assertSame(404, $this->request('GET', '/api/v1/filters/' . $rule['id'])->status);
	}

	public function testAValidationErrorNamesTheField(): void
	{
		$response = $this->request('POST', '/api/v1/filters', [
			'name' => ' ',
			'action' => 'hide',
			'contexts' => ['home'],
			'keywords' => [['text' => 'x']],
		]);

		self::assertSame(422, $response->status, (string) json_encode($response->decodedBody()));
		self::assertArrayHasKey('name', $response->decodedBody()['error']['fields'], (string) json_encode($response->decodedBody()));
	}

	public function testAnotherReaderGets404ForShowUpdateAndDelete(): void
	{
		$id = $this->createFilter('hide', ['home'], 'secret');

		self::assertSame(404, $this->request('GET', '/api/v1/filters/' . $id, [], 'bob')->status);
		self::assertSame(404, $this->request('PATCH', '/api/v1/filters/' . $id, [
			'name' => 'Mine now',
			'action' => 'warn',
			'contexts' => ['home'],
			'keywords' => [['text' => 'x']],
		], 'bob')->status);
		self::assertSame(404, $this->request('DELETE', '/api/v1/filters/' . $id, [], 'bob')->status);
	}

	public function testAHiddenPostLeavesTheHomeTimelineAndItsDetailIs404(): void
	{
		$hidden = $this->createPost('Here is a spoiler');
		$visible = $this->createPost('An ordinary post');

		$this->createFilter('hide', ['home', 'thread'], 'spoiler');

		$ids = $this->postIds($this->request('GET', '/api/v1/timelines/home'));
		self::assertNotContains($hidden, $ids);
		self::assertContains($visible, $ids);

		self::assertSame(404, $this->request('GET', '/api/v1/posts/' . $hidden)->status);
		self::assertSame(200, $this->request('GET', '/api/v1/posts/' . $visible)->status);

		// The rule is reader-private: another account still sees the post.
		self::assertSame(200, $this->request('GET', '/api/v1/posts/' . $hidden, [], 'bob')->status);
	}

	public function testAWarnedPostIsRedactedUntilIncludeFiltered(): void
	{
		$hidden = $this->createPost('Here is a spoiler', 'A warning');
		$this->createFilter('warn', ['home', 'thread'], 'spoiler');

		$posts = $this->request('GET', '/api/v1/timelines/home')->decodedBody()['posts'];
		self::assertCount(1, $posts);
		self::assertSame('', $posts[0]['content']);
		self::assertNull($posts[0]['content_warning']);
		self::assertSame('spoiler', $posts[0]['filtered'][0]['keyword']);

		$revealed = $this->request('GET', '/api/v1/timelines/home', [], 'alice', [], ['include_filtered' => 'true'])->decodedBody()['posts'];
		self::assertSame('Here is a spoiler', $revealed[0]['content']);
		self::assertSame('A warning', $revealed[0]['content_warning']);
		self::assertCount(1, $revealed[0]['filtered']);

		$detail = $this->request('GET', '/api/v1/posts/' . $hidden)->decodedBody()['post'];
		self::assertSame('', $detail['content']);
		self::assertSame('warn', $detail['filtered'][0]['action']);

		$revealedDetail = $this->request('GET', '/api/v1/posts/' . $hidden, [], 'alice', [], ['include_filtered' => '1'])->decodedBody()['post'];
		self::assertSame('Here is a spoiler', $revealedDetail['content']);
	}

	public function testAPublicContextRuleHidesFromThePublicAndTagTimelines(): void
	{
		$hidden = $this->createPost('Spoiler in public');
		$this->createFilter('hide', ['public'], 'spoiler');

		self::assertNotContains($hidden, $this->postIds($this->request('GET', '/api/v1/timelines/public')));
		self::assertNotContains($hidden, $this->postIds($this->request('GET', '/api/v1/timelines/tag/tag')));
	}

	public function testTheMastodonV2ShapeAndLifecycle(): void
	{
		$created = $this->request('POST', '/api/mastodon/v1/filters', [
			'title' => 'Spoilers',
			'phrase' => 'spoiler',
			'context' => ['home'],
			'filter_action' => 'hide',
			'whole_word' => true,
			'expires_in' => 600,
		], 'alice', ['write:filters']);
		self::assertSame(200, $created->status, (string) json_encode($created->decodedBody()));
		$filter = $created->decodedBody();
		self::assertSame('Spoilers', $filter['title']);
		self::assertSame('spoiler', $filter['keywords'][0]['keyword'] ?? '');
		self::assertSame('hide', $filter['filter_action']);
		self::assertSame(['home'], $filter['context']);
		self::assertSame('spoiler', $filter['keywords'][0]['keyword']);
		self::assertSame($filter['id'] . ':0', $filter['keywords'][0]['id']);
		self::assertTrue($filter['keywords'][0]['whole_word']);

		$list = $this->request('GET', '/api/mastodon/v1/filters', [], 'alice', ['read:filters']);
		self::assertCount(1, $list->decodedBody());

		$shown = $this->request('GET', '/api/mastodon/v1/filters/' . $filter['id'], [], 'alice', ['read:filters']);
		self::assertSame($filter['id'], $shown->decodedBody()['id']);

		$updated = $this->request('PUT', '/api/mastodon/v1/filters/' . $filter['id'], [
			'title' => 'Warned',
			'filter_action' => 'warn',
			'context' => ['notifications'],
			'keywords_attributes' => [['keyword' => 'calm', 'whole_word' => false]],
		], 'alice', ['write:filters'])->decodedBody();
		self::assertSame('Warned', $updated['title']);
		self::assertSame('warn', $updated['filter_action']);
		self::assertSame(['notifications'], $updated['context']);
		self::assertSame('calm', $updated['keywords'][0]['keyword']);

		$deleted = $this->request('DELETE', '/api/mastodon/v1/filters/' . $filter['id'], [], 'alice', ['write:filters']);
		self::assertSame(200, $deleted->status);
		self::assertSame('{}', $deleted->body);

		self::assertSame(404, $this->request('GET', '/api/mastodon/v1/filters/' . $filter['id'], [], 'alice', ['read:filters'])->status);
	}

	public function testAHiddenNotificationIsRemovedAndTheCountFollows(): void
	{
		$post = $this->createPost('Spoiler mention');
		$this->notifications->insert(new Notification(
			id: 'notification-1',
			recipientId: 'alice',
			actorId: 'bob',
			type: NotificationType::Mention,
			postId: $post,
			createdAt: Time::parse('2026-11-11T09:59:00Z'),
		));

		self::assertSame(1, $this->request('GET', '/api/v1/notifications')->decodedBody()['unread_count']);

		$this->createFilter('hide', ['notifications'], 'spoiler');

		$page = $this->request('GET', '/api/v1/notifications')->decodedBody();
		self::assertSame([], $page['notifications']);
		self::assertSame(0, $page['unread_count']);
	}

	public function testAKeywordMatchIsCaseInsensitiveAndWholeWordAware(): void
	{
		$hidden = $this->createPost('That SPOILER again');
		$other = $this->createPost('Concatenate the strings');
		$this->createFilter('hide', ['home'], 'spoiler', true);

		$ids = $this->postIds($this->request('GET', '/api/v1/timelines/home'));
		self::assertNotContains($hidden, $ids);
		self::assertContains($other, $ids);
	}

	/**
	 * @param list<string> $contexts
	 */
	private function createFilter(string $action, array $contexts, string $keyword, bool $wholeWord = false): string
	{
		$response = $this->request('POST', '/api/v1/filters', [
			'name' => 'Rule',
			'action' => $action,
			'contexts' => $contexts,
			'keywords' => [['text' => $keyword, 'whole_word' => $wholeWord]],
		]);
		self::assertSame(201, $response->status, (string) json_encode($response->decodedBody()));

		return $response->decodedBody()['filter']['id'];
	}

	private function createPost(string $content, ?string $contentWarning = null): string
	{
		$body = ['content' => $content, 'visibility' => 'public'];
		if ($contentWarning !== null) {
			$body['content_warning'] = $contentWarning;
		}

		$response = $this->request('POST', '/api/v1/posts', $body);
		self::assertSame(201, $response->status, (string) json_encode($response->decodedBody()));

		return $response->decodedBody()['post']['id'];
	}

	/**
	 * @param array<string, mixed> $body
	 * @param list<string> $scopes
	 * @param array<string, string> $query
	 */
	private function request(
		string $method,
		string $path,
		array $body = [],
		string $userId = 'alice',
		array $scopes = [],
		array $query = [],
	): Response {
		return $this->app->handle(new Request(
			method: $method,
			path: $path,
			query: $query,
			body: $body === [] ? '' : json_encode($body, JSON_THROW_ON_ERROR),
			userId: $userId,
			scopes: $scopes === [] ? ['read', 'write'] : $scopes,
		));
	}

	/**
	 * @return list<string>
	 */
	private function postIds(Response $response): array
	{
		return array_map(
			static fn (array $post): string => $post['id'],
			$response->decodedBody()['posts'],
		);
	}
}
