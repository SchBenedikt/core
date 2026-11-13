<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Api;

use AlohaSocial\Core\Application;
use AlohaSocial\Core\Domain\Federation\FollowedActor;
use AlohaSocial\Core\Domain\Federation\FollowingRepository;
use AlohaSocial\Core\Domain\Federation\InMemoryActorDirectory;
use AlohaSocial\Core\Domain\Federation\InMemoryFollowingRepository;
use AlohaSocial\Core\Domain\Media\Attachment;
use AlohaSocial\Core\Domain\Media\InMemoryAttachmentRepository;
use AlohaSocial\Core\Domain\Notification\NotificationRepository;
use AlohaSocial\Core\Domain\Post\BoostRepository;
use AlohaSocial\Core\Domain\Post\FavouriteRepository;
use AlohaSocial\Core\Domain\Post\InMemoryBoostRepository;
use AlohaSocial\Core\Domain\Post\InMemoryFavouriteRepository;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Platform\Storage\InMemoryBlobStore;
use AlohaSocial\Core\Service\InstanceInfo;
use AlohaSocial\Core\Service\Mastodon\MastodonApiConfig;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Core\Tests\Support\FailingNotificationRepository;
use PHPUnit\Framework\TestCase;

use function imagecolorallocate;
use function imagecreatetruecolor;
use function imagefill;
use function imagepng;
use function imagedestroy;
use function ob_get_clean;
use function ob_start;

/**
 * End-to-end coverage for the optional Mastodon compatibility surface.
 *
 * The routes are additive and disabled by default; this test exercises the
 * real HTTP kernel, the shared post service, scopes, form/JSON input,
 * idempotency keys and the id-aware cursor.
 */
final class MastodonApiTest extends TestCase
{
	private FrozenClock $clock;

	protected function setUp(): void
	{
		$this->clock = new FrozenClock(Time::parse('2026-11-11T10:00:00Z'));
	}

	public function testInstanceIsServedUnderTheDefaultPrefix(): void
	{
		$response = $this->app()->handle(new Request('GET', MastodonApiConfig::DEFAULT_PREFIX . '/instance'));

		self::assertSame(200, $response->status);
		self::assertSame('Aloha Social', $response->decodedBody()['title']);
		self::assertSame('4.2.0 (compatible; Aloha Social 0.1.0)', $response->decodedBody()['version']);
		self::assertSame([
			'max_options' => 4,
			'max_characters_per_option' => 50,
			'min_expiration' => 300,
			'max_expiration' => 604800,
		], $response->decodedBody()['configuration']['polls']);
	}

	public function testInstanceExposesConfiguredMetadataAndLocalStats(): void
	{
		$app = $this->app(actors: ['alice', 'bob'], instanceConfig: [
			'instance.description' => 'A small test instance.',
			'instance.contact_email' => 'admin@social.example',
			'instance.contact_account' => 'alice',
			'instance.registrations' => 'true',
			'instance.approval_required' => 'false',
			'instance.invites_enabled' => 'true',
			'instance.languages' => 'de, en',
			'instance.thumbnail' => 'https://social.example/thumbnail.png',
		]);
		$this->createStatus($app, ['status' => 'first local status']);
		$this->createStatus($app, ['status' => 'second local status'], userId: 'bob');

		$response = $app->handle(new Request('GET', MastodonApiConfig::DEFAULT_PREFIX . '/instance'));
		self::assertSame(200, $response->status);
		$body = $response->decodedBody();
		self::assertSame('A small test instance.', $body['short_description']);
		self::assertSame('A small test instance.', $body['description']);
		self::assertSame('admin@social.example', $body['email']);
		self::assertTrue($body['registrations']);
		self::assertFalse($body['approval_required']);
		self::assertTrue($body['invites_enabled']);
		self::assertSame(['de', 'en'], $body['languages']);
		self::assertSame('https://social.example/thumbnail.png', $body['thumbnail']);
		self::assertSame('alice', $body['contact_account']['id']);
		self::assertSame([
			'user_count' => 2,
			'status_count' => 2,
			'domain_count' => 0,
		], $body['stats']);

		// The core endpoint shares the same description and the additive stats.
		$core = $app->handle(new Request('GET', '/api/v1/instance'));
		self::assertSame(200, $core->status);
		self::assertSame('A small test instance.', $core->decodedBody()['instance']['description']);
		self::assertSame(2, $core->decodedBody()['instance']['stats']['status_count']);
	}

	public function testInstanceContactAccountIsOmittedWhenItIsNotLocal(): void
	{
		$response = $this->app(instanceConfig: ['instance.contact_account' => 'ghost'])->handle(
			new Request('GET', MastodonApiConfig::DEFAULT_PREFIX . '/instance'),
		);

		self::assertSame(200, $response->status);
		self::assertNull($response->decodedBody()['contact_account']);
	}

	public function testTheSurfaceIsDisabledByDefaultAndWithTheFlagOff(): void
	{
		$app = $this->app(enabled: false);
		$response = $app->handle(new Request(
			'GET',
			MastodonApiConfig::DEFAULT_PREFIX . '/instance',
		));

		self::assertSame(404, $response->status);
		self::assertSame('mastodon_api_disabled', $response->decodedBody()['error']['code']);

		// The gate runs before auth and scopes: a hidden feature must not
		// reveal a different error to a caller with a token.
		$write = $app->handle(new Request(
			method: 'POST',
			path: MastodonApiConfig::DEFAULT_PREFIX . '/statuses',
			body: 'status=no',
			userId: 'alice',
			scopes: ['write:statuses'],
		));
		self::assertSame(404, $write->status);
		self::assertSame('mastodon_api_disabled', $write->decodedBody()['error']['code']);
	}

	public function testCreateReadAndDeleteAStatus(): void
	{
		$app = $this->app();
		$created = $this->createStatus($app, [
			'status' => 'hello <world>',
			'spoiler_text' => 'spoilers',
		]);

		self::assertSame(200, $created->status);
		$status = $created->decodedBody();
		self::assertSame('public', $status['visibility']);
		self::assertTrue($status['sensitive']);
		self::assertSame('spoilers', $status['spoiler_text']);
		self::assertSame('<p>hello &lt;world&gt;</p>', $status['content']);
		self::assertSame('alice', $status['account']['acct']);
		self::assertSame('https://social.example/@alice', $status['account']['url']);

		$shown = $app->handle(new Request('GET', MastodonApiConfig::DEFAULT_PREFIX . '/statuses/' . $status['id']));
		self::assertSame(200, $shown->status);
		self::assertSame($status['id'], $shown->decodedBody()['id']);

		$account = $app->handle(new Request(
			'GET',
			MastodonApiConfig::DEFAULT_PREFIX . '/accounts/alice/statuses',
		));
		self::assertSame(200, $account->status);
		self::assertCount(1, $account->decodedBody());
		self::assertSame($status['id'], $account->decodedBody()[0]['id']);

		$deleted = $app->handle(new Request(
			method: 'DELETE',
			path: MastodonApiConfig::DEFAULT_PREFIX . '/statuses/' . $status['id'],
			userId: 'alice',
			scopes: ['write:statuses'],
		));
		self::assertSame(204, $deleted->status);

		$missing = $app->handle(new Request(
			'GET',
			MastodonApiConfig::DEFAULT_PREFIX . '/statuses/' . $status['id'],
		));
		self::assertSame(404, $missing->status);
	}

	public function testScopesAreEnforcedAndAParentScopeGrantsItsChildren(): void
	{
		$app = $this->app();

		$anonymous = $app->handle(new Request(
			method: 'POST',
			path: MastodonApiConfig::DEFAULT_PREFIX . '/statuses',
			body: 'status=no-auth',
		));
		self::assertSame(401, $anonymous->status);

		$noScope = $this->createStatus($app, ['status' => 'no scope'], scopes: ['read:statuses']);
		self::assertSame(403, $noScope->status);
		self::assertSame('insufficient_scope', $noScope->decodedBody()['error']['code']);

		$withParent = $this->createStatus($app, ['status' => 'parent scope'], scopes: ['write']);
		self::assertSame(200, $withParent->status);

		$verifyWithoutScope = $app->handle(new Request(
			method: 'GET',
			path: MastodonApiConfig::DEFAULT_PREFIX . '/accounts/verify_credentials',
			userId: 'alice',
			scopes: ['read:statuses'],
		));
		self::assertSame(403, $verifyWithoutScope->status);

		$verify = $app->handle(new Request(
			method: 'GET',
			path: MastodonApiConfig::DEFAULT_PREFIX . '/accounts/verify_credentials',
			userId: 'alice',
			scopes: ['read:accounts'],
		));
		self::assertSame(200, $verify->status);
		self::assertSame('alice', $verify->decodedBody()['id']);
	}

	public function testCreateStatusWithPollReturnsAndReadsTheMastodonPoll(): void
	{
		$app = $this->app();
		$created = $this->createStatus($app, [
			'status' => 'Which option?',
			'poll' => [
				'options' => ['Yes', 'No'],
				'expires_in' => '3600',
				'multiple' => 'true',
				'hide_totals' => 'true',
			],
		]);

		self::assertSame(200, $created->status);
		$status = $created->decodedBody();
		$poll = $status['poll'];
		self::assertSame('poll-' . $status['id'], $poll['id']);
		self::assertSame('2026-11-11T11:00:00.000Z', $poll['expires_at']);
		self::assertFalse($poll['expired']);
		self::assertTrue($poll['multiple']);
		self::assertSame(0, $poll['votes_count']);
		self::assertSame(0, $poll['voters_count']);
		self::assertFalse($poll['voted']);
		self::assertSame([], $poll['own_votes']);
		self::assertSame([
			['title' => 'Yes', 'votes_count' => 0],
			['title' => 'No', 'votes_count' => 0],
		], $poll['options']);
		self::assertSame([], $poll['emojis']);

		$shown = $app->handle(new Request(
			'GET',
			MastodonApiConfig::DEFAULT_PREFIX . '/statuses/' . $status['id'],
		));
		self::assertSame($poll, $shown->decodedBody()['poll']);

		$timeline = $app->handle(new Request(
			'GET',
			MastodonApiConfig::DEFAULT_PREFIX . '/timelines/public',
		));
		self::assertSame($poll, $timeline->decodedBody()[0]['poll']);
	}

	public function testPollAcceptsJsonAndAnExplicitExpiry(): void
	{
		$app = $this->app();
		$response = $app->handle(new Request(
			method: 'POST',
			path: MastodonApiConfig::DEFAULT_PREFIX . '/statuses',
			headers: ['Content-Type' => 'application/json'],
			body: json_encode([
				'status' => 'JSON poll',
				'poll' => [
					'options' => ['A', 'B', 'C'],
					'expires_at' => '2026-11-11T12:00:00Z',
				],
			], JSON_THROW_ON_ERROR),
			userId: 'alice',
			scopes: ['write:statuses'],
		));

		self::assertSame(200, $response->status);
		self::assertSame('2026-11-11T12:00:00.000Z', $response->decodedBody()['poll']['expires_at']);
		self::assertCount(3, $response->decodedBody()['poll']['options']);
	}

	public function testPollValidationCreatesNoPartialStatus(): void
	{
		$app = $this->app();

		$invalidPolls = [
			['options' => ['only one'], 'expires_in' => '3600'],
			['options' => ['1', '2', '3', '4', '5'], 'expires_in' => '3600'],
			['options' => ['yes', '   '], 'expires_in' => '3600'],
			['options' => ['yes', str_repeat('x', 51)], 'expires_in' => '3600'],
			['options' => ['yes', 'no']],
			['options' => ['yes', 'no'], 'expires_in' => '299'],
			['options' => ['yes', 'no'], 'expires_in' => '604801'],
			['options' => ['yes', 'no'], 'expires_in' => '3600', 'multiple' => 'maybe'],
		];

		foreach ($invalidPolls as $poll) {
			$response = $this->createStatus($app, ['status' => 'invalid poll', 'poll' => $poll]);
			self::assertSame(422, $response->status);
			self::assertSame('validation_failed', $response->decodedBody()['error']['code']);
		}

		$scalar = $this->createStatus($app, ['status' => 'invalid poll', 'poll' => 'not an object']);
		self::assertSame(422, $scalar->status);
		self::assertSame(
			[],
			$app->handle(new Request('GET', MastodonApiConfig::DEFAULT_PREFIX . '/timelines/public'))->decodedBody(),
		);
	}

	public function testPollExpiresWithoutAScheduledJob(): void
	{
		$app = $this->app();
		$status = $this->createStatus($app, [
			'status' => 'expires soon',
			'poll' => [
				'options' => ['Yes', 'No'],
				'expires_in' => '300',
			],
		])->decodedBody();

		$this->clock->advance(299);
		self::assertFalse($app->handle(new Request(
			'GET',
			MastodonApiConfig::DEFAULT_PREFIX . '/statuses/' . $status['id'],
		))->decodedBody()['poll']['expired']);

		$this->clock->advance(1);
		self::assertTrue($app->handle(new Request(
			'GET',
			MastodonApiConfig::DEFAULT_PREFIX . '/statuses/' . $status['id'],
		))->decodedBody()['poll']['expired']);
	}

	public function testPollCreationRequiresAuthTheWriteScopeAndTheFeatureFlag(): void
	{
		$fields = [
			'status' => 'protected poll',
			'poll' => [
				'options' => ['Yes', 'No'],
				'expires_in' => '3600',
			],
		];
		$app = $this->app();

		self::assertSame(401, $this->createStatus($app, $fields, userId: null)->status);

		$wrongScope = $this->createStatus($app, $fields, scopes: ['read:statuses']);
		self::assertSame(403, $wrongScope->status);
		self::assertSame('insufficient_scope', $wrongScope->decodedBody()['error']['code']);

		$disabled = $this->app(enabled: false);
		$off = $this->createStatus($disabled, $fields);
		self::assertSame(404, $off->status);
		self::assertSame('mastodon_api_disabled', $off->decodedBody()['error']['code']);

		self::assertSame([], $app->handle(new Request(
			'GET',
			MastodonApiConfig::DEFAULT_PREFIX . '/timelines/public',
		))->decodedBody());
	}

	public function testAPollRetryWithTheSameIdempotencyKeyDoesNotDuplicateThePoll(): void
	{
		$app = $this->app();
		$fields = [
			'status' => 'idempotent poll',
			'poll' => [
				'options' => ['Yes', 'No'],
				'expires_in' => '3600',
			],
		];
		$first = $this->createStatus($app, $fields, ['Idempotency-Key' => 'poll-key']);
		$second = $this->createStatus($app, $fields, ['Idempotency-Key' => 'poll-key']);

		self::assertSame(200, $first->status);
		self::assertSame(200, $second->status);
		self::assertSame($first->decodedBody()['id'], $second->decodedBody()['id']);
		self::assertSame($first->decodedBody()['poll'], $second->decodedBody()['poll']);
		self::assertCount(1, $app->handle(new Request(
			'GET',
			MastodonApiConfig::DEFAULT_PREFIX . '/timelines/public',
		))->decodedBody());
	}

	public function testAnIdempotencyKeyReturnsTheSameStatus(): void
	{
		$app = $this->app();
		$first = $this->createStatus(
			$app,
			['status' => 'once'],
			['Idempotency-Key' => 'client-key-1'],
		);
		$second = $this->createStatus(
			$app,
			['status' => 'once'],
			['Idempotency-Key' => 'client-key-1'],
		);

		self::assertSame(200, $first->status);
		self::assertSame(200, $second->status);
		self::assertSame($first->decodedBody()['id'], $second->decodedBody()['id']);

		$timeline = $app->handle(new Request(
			'GET',
			MastodonApiConfig::DEFAULT_PREFIX . '/timelines/public',
		));
		self::assertCount(1, $timeline->decodedBody());
	}

	public function testPaginationUsesTheStatusIdAndReturnsALink(): void
	{
		$app = $this->app();
		$ids = [];
		foreach (['one', 'two', 'three'] as $content) {
			$ids[] = $this->createStatus($app, ['status' => $content])->decodedBody()['id'];
			$this->clock->advance(1);
		}

		$first = $app->handle(new Request(
			'GET',
			MastodonApiConfig::DEFAULT_PREFIX . '/timelines/public',
			['limit' => '2'],
		));
		self::assertSame(200, $first->status);
		self::assertCount(2, $first->decodedBody());
		self::assertSame([$ids[2], $ids[1]], array_column($first->decodedBody(), 'id'));
		self::assertStringContainsString('max_id=' . rawurlencode($ids[1]), (string) $first->header('Link'));

		$second = $app->handle(new Request(
			'GET',
			MastodonApiConfig::DEFAULT_PREFIX . '/timelines/public',
			['limit' => '2', 'max_id' => $ids[1]],
		));
		self::assertSame(200, $second->status);
		self::assertSame([$ids[0]], array_column($second->decodedBody(), 'id'));
	}

	public function testSinceIdReturnsOnlyNewerStatusesAndLimitIsClamped(): void
	{
		$app = $this->app();
		$ids = [];
		foreach (['one', 'two', 'three'] as $content) {
			$ids[] = $this->createStatus($app, ['status' => $content])->decodedBody()['id'];
			$this->clock->advance(1);
		}

		$since = $app->handle(new Request(
			'GET',
			MastodonApiConfig::DEFAULT_PREFIX . '/timelines/public',
			['since_id' => $ids[0], 'limit' => '100'],
		));
		self::assertSame(200, $since->status);
		self::assertSame([$ids[2], $ids[1]], array_column($since->decodedBody(), 'id'));

		$unknown = $app->handle(new Request(
			'GET',
			MastodonApiConfig::DEFAULT_PREFIX . '/timelines/public',
			['max_id' => 'does-not-exist'],
		));
		self::assertSame(404, $unknown->status);
	}

	public function testJsonAndMediaValidationDoNotCreateAPost(): void
	{
		$app = $this->app();
		$created = $app->handle(new Request(
			method: 'POST',
			path: MastodonApiConfig::DEFAULT_PREFIX . '/statuses',
			headers: ['Content-Type' => 'application/json'],
			body: json_encode(['status' => 'json body'], JSON_THROW_ON_ERROR),
			userId: 'alice',
			scopes: ['write:statuses'],
		));
		self::assertSame(200, $created->status);

		$invalidMedia = $this->createStatus($app, [
			'status' => 'bad media',
			'media_ids' => ['missing-attachment'],
		]);
		self::assertSame(422, $invalidMedia->status);
		self::assertSame('validation_failed', $invalidMedia->decodedBody()['error']['code']);

		$timeline = $app->handle(new Request(
			'GET',
			MastodonApiConfig::DEFAULT_PREFIX . '/timelines/public',
		));
		self::assertCount(1, $timeline->decodedBody());
		self::assertSame('<p>json body</p>', $timeline->decodedBody()[0]['content']);
	}

	public function testMediaUploadStoresAPresentedAttachmentAndCanBeUsedInAStatus(): void
	{
		$attachments = new InMemoryAttachmentRepository();
		$blobs = new InMemoryBlobStore();
		$app = $this->app(attachments: $attachments, blobs: $blobs);
		$png = $this->png();

		$response = $this->upload($app, $png, 'a mastodon image');

		self::assertSame(200, $response->status);
		$media = $response->decodedBody();
		self::assertSame('image', $media['type']);
		self::assertSame('a mastodon image', $media['description']);
		self::assertSame(32, $media['meta']['original']['width']);
		self::assertSame(32, $media['meta']['original']['height']);
		self::assertStringStartsWith('https://social.example/api/v1/media/', $media['url']);
		self::assertStringEndsWith('?variant=thumbnail', $media['preview_url']);
		self::assertSame(1, $attachments->count());

		$stored = $attachments->find($media['id']);
		self::assertNotNull($stored);
		self::assertSame('alice', $stored->ownerId);
		self::assertSame('a mastodon image', $stored->altText);
		self::assertSame([
			'media/' . $media['id'] . '/original.png',
			'media/' . $media['id'] . '/thumbnail.png',
		], $blobs->list('media/' . $media['id']));

		$fetched = $app->handle(new Request('GET', $media['url']));
		self::assertSame(200, $fetched->status);
		self::assertSame($png, $fetched->body);

		$created = $this->createStatus($app, [
			'status' => 'with an uploaded image',
			'media_ids' => [$media['id']],
		]);
		self::assertSame(200, $created->status);
		$projected = $created->decodedBody()['media_attachments'];
		self::assertCount(1, $projected);
		self::assertSame($media['id'], $projected[0]['id']);
		self::assertSame('a mastodon image', $projected[0]['description']);
	}

	public function testMediaUploadRequiresAuthAndTheWriteMediaScope(): void
	{
		$attachments = new InMemoryAttachmentRepository();
		$blobs = new InMemoryBlobStore();
		$app = $this->app(attachments: $attachments, blobs: $blobs);

		$anonymous = $this->upload($app, $this->png(), userId: null);
		self::assertSame(401, $anonymous->status);
		self::assertSame('authentication_required', $anonymous->decodedBody()['error']['code']);

		$wrongScope = $this->upload($app, $this->png(), scopes: ['write:statuses']);
		self::assertSame(403, $wrongScope->status);
		self::assertSame('insufficient_scope', $wrongScope->decodedBody()['error']['code']);
		self::assertSame(0, $attachments->count());
		self::assertSame([], $blobs->list('media'));

		$parentScope = $this->upload($app, $this->png(), scopes: ['write']);
		self::assertSame(200, $parentScope->status);
		self::assertSame(1, $attachments->count());
	}

	public function testMediaUploadIsDisabledBeforeAuth(): void
	{
		$app = $this->app(enabled: false);

		$response = $this->upload($app, $this->png(), userId: null);

		self::assertSame(404, $response->status);
		self::assertSame('mastodon_api_disabled', $response->decodedBody()['error']['code']);
	}

	public function testMediaUploadRejectsMissingInvalidCorruptAndOversizedFilesWithoutRows(): void
	{
		$attachments = new InMemoryAttachmentRepository();
		$blobs = new InMemoryBlobStore();
		$app = $this->app(attachments: $attachments, blobs: $blobs);

		$missing = $this->upload($app, null, 'no file');
		self::assertSame(422, $missing->status);
		self::assertSame('validation_failed', $missing->decodedBody()['error']['code']);
		self::assertArrayHasKey('file', $missing->decodedBody()['error']['fields']);

		$invalid = $this->upload($app, 'not an image');
		self::assertSame(422, $invalid->status);
		self::assertArrayHasKey('file', $invalid->decodedBody()['error']['fields']);

		$corrupt = $this->upload($app, "\x89PNG\r\n\x1a\nnot-really-a-png");
		self::assertSame(422, $corrupt->status);
		self::assertArrayHasKey('file', $corrupt->decodedBody()['error']['fields']);

		$oversized = $this->upload($app, str_repeat('a', Attachment::MAX_BYTES + 200_000));
		self::assertSame(413, $oversized->status);
		self::assertSame('payload_too_large', $oversized->decodedBody()['error']['code']);

		self::assertSame(0, $attachments->count());
		self::assertSame([], $blobs->list('media'));
	}

	public function testMediaUploadIdempotencyReturnsTheSameAttachment(): void
	{
		$attachments = new InMemoryAttachmentRepository();
		$blobs = new InMemoryBlobStore();
		$app = $this->app(attachments: $attachments, blobs: $blobs);

		$first = $this->upload($app, $this->png(), 'retry me', ['Idempotency-Key' => 'media-key-1']);
		$second = $this->upload($app, $this->png(), 'retry me', ['Idempotency-Key' => 'media-key-1']);

		self::assertSame(200, $first->status);
		self::assertSame(200, $second->status);
		self::assertSame($first->decodedBody()['id'], $second->decodedBody()['id']);
		self::assertSame(1, $attachments->count());
		self::assertCount(2, $blobs->list('media'));

		$third = $this->upload($app, $this->png(), 'different intent', ['Idempotency-Key' => 'media-key-2']);
		self::assertSame(200, $third->status);
		self::assertNotSame($first->decodedBody()['id'], $third->decodedBody()['id']);
		self::assertSame(2, $attachments->count());
	}

	public function testMediaDescriptionCanBeUpdatedAndClearedWithoutChangingBlobs(): void
	{
		$attachments = new InMemoryAttachmentRepository();
		$blobs = new InMemoryBlobStore();
		$app = $this->app(attachments: $attachments, blobs: $blobs);
		$uploaded = $this->upload($app, $this->png(), 'old description')->decodedBody();
		$keys = $blobs->list('media/' . $uploaded['id']);

		$updated = $this->updateMedia($app, $uploaded['id'], ['description' => 'new description']);

		self::assertSame(200, $updated->status);
		self::assertSame($uploaded['id'], $updated->decodedBody()['id']);
		self::assertSame('new description', $updated->decodedBody()['description']);
		self::assertSame($uploaded['url'], $updated->decodedBody()['url']);
		self::assertSame('new description', $attachments->find($uploaded['id'])?->altText);
		self::assertSame(1, $attachments->count());
		self::assertSame($keys, $blobs->list('media/' . $uploaded['id']));

		$unchanged = $this->updateMedia($app, $uploaded['id'], []);
		self::assertSame('new description', $unchanged->decodedBody()['description']);

		$cleared = $this->updateMedia($app, $uploaded['id'], ['description' => '']);
		self::assertNull($cleared->decodedBody()['description']);
		self::assertNull($attachments->find($uploaded['id'])?->altText);
	}

	public function testMediaUpdateAcceptsFormAndMultipartFields(): void
	{
		$attachments = new InMemoryAttachmentRepository();
		$blobs = new InMemoryBlobStore();
		$app = $this->app(attachments: $attachments, blobs: $blobs);
		$uploaded = $this->upload($app, $this->png())->decodedBody();

		$form = $app->handle(new Request(
			method: 'PUT',
			path: MastodonApiConfig::DEFAULT_PREFIX . '/media/' . $uploaded['id'],
			headers: ['Content-Type' => 'application/x-www-form-urlencoded'],
			body: 'description=form+description&focus=0.5%2C0.5',
			userId: 'alice',
			scopes: ['write:media'],
		));
		self::assertSame(200, $form->status);
		self::assertSame('form description', $form->decodedBody()['description']);

		$boundary = '----AlohaSocialMediaUpdate' . bin2hex(random_bytes(8));
		$body = '--' . $boundary . "\r\n"
			. "Content-Disposition: form-data; name=\"alt_text\"\r\n\r\n"
			. "multipart description\r\n"
			. '--' . $boundary . "--\r\n";
		$multipart = $app->handle(new Request(
			method: 'PUT',
			path: MastodonApiConfig::DEFAULT_PREFIX . '/media/' . $uploaded['id'],
			headers: ['Content-Type' => 'multipart/form-data; boundary=' . $boundary],
			body: $body,
			userId: 'alice',
			scopes: ['write:media'],
		));
		self::assertSame(200, $multipart->status);
		self::assertSame('multipart description', $multipart->decodedBody()['description']);
	}

	public function testMediaUpdateRejectsForeignAttachedMissingAndBinaryChanges(): void
	{
		$attachments = new InMemoryAttachmentRepository();
		$blobs = new InMemoryBlobStore();
		$app = $this->app(attachments: $attachments, blobs: $blobs, actors: ['alice', 'bob']);
		$uploaded = $this->upload($app, $this->png(), 'owned by alice')->decodedBody();

		$foreign = $this->updateMedia($app, $uploaded['id'], ['description' => 'not mine'], userId: 'bob');
		self::assertSame(404, $foreign->status);
		self::assertSame('owned by alice', $attachments->find($uploaded['id'])?->altText);

		$missing = $this->updateMedia($app, 'missing', ['description' => 'nothing']);
		self::assertSame(404, $missing->status);

		$binary = $this->updateMedia($app, $uploaded['id'], ['file' => 'replacement']);
		self::assertSame(422, $binary->status);
		self::assertSame('unsupported_parameter', $binary->decodedBody()['error']['code']);

		$invalid = $this->updateMedia($app, $uploaded['id'], ['description' => ['not a string']]);
		self::assertSame(422, $invalid->status);
		self::assertArrayHasKey('description', $invalid->decodedBody()['error']['fields']);

		$long = $this->updateMedia($app, $uploaded['id'], [
			'description' => str_repeat('a', Attachment::MAX_ALT_TEXT_LENGTH + 1),
		]);
		self::assertSame(422, $long->status);
		self::assertArrayHasKey('description', $long->decodedBody()['error']['fields']);

		$created = $this->createStatus($app, [
			'status' => 'attached',
			'media_ids' => [$uploaded['id']],
		])->decodedBody();
		$attached = $this->updateMedia($app, $uploaded['id'], ['description' => 'too late']);
		self::assertSame(404, $attached->status);
		self::assertSame('owned by alice', $attachments->find($uploaded['id'])?->altText);
		self::assertSame($uploaded['id'], $created['media_attachments'][0]['id']);
	}

	public function testMediaDeleteRemovesOnlyUnattachedOwnedMedia(): void
	{
		$attachments = new InMemoryAttachmentRepository();
		$blobs = new InMemoryBlobStore();
		$app = $this->app(attachments: $attachments, blobs: $blobs, actors: ['alice', 'bob']);
		$uploaded = $this->upload($app, $this->png())->decodedBody();

		$deleted = $this->deleteMedia($app, $uploaded['id']);
		self::assertSame(200, $deleted->status);
		self::assertSame('{}', $deleted->body);
		self::assertNull($attachments->find($uploaded['id']));
		self::assertSame(0, $attachments->count());
		self::assertSame([], $blobs->list('media/' . $uploaded['id']));
		self::assertSame(404, $this->deleteMedia($app, $uploaded['id'])->status);

		$attachedUpload = $this->upload($app, $this->png(), 'attached')->decodedBody();
		$this->createStatus($app, [
			'status' => 'keeps media',
			'media_ids' => [$attachedUpload['id']],
		]);
		$attached = $this->deleteMedia($app, $attachedUpload['id']);
		self::assertSame(422, $attached->status);
		self::assertSame('validation_failed', $attached->decodedBody()['error']['code']);
		self::assertNotNull($attachments->find($attachedUpload['id']));
		self::assertCount(2, $blobs->list('media/' . $attachedUpload['id']));

		$foreignUpload = $this->upload($app, $this->png(), 'foreign')->decodedBody();
		$foreign = $this->deleteMedia($app, $foreignUpload['id'], userId: 'bob');
		self::assertSame(404, $foreign->status);
		self::assertNotNull($attachments->find($foreignUpload['id']));
	}

	public function testMediaUpdateAndDeleteRequireAuthScopeAndTheFeatureFlag(): void
	{
		$attachments = new InMemoryAttachmentRepository();
		$blobs = new InMemoryBlobStore();
		$app = $this->app(attachments: $attachments, blobs: $blobs);
		$uploaded = $this->upload($app, $this->png())->decodedBody();

		$anonymousUpdate = $this->updateMedia($app, $uploaded['id'], ['description' => 'no auth'], userId: null);
		self::assertSame(401, $anonymousUpdate->status);
		$wrongUpdateScope = $this->updateMedia($app, $uploaded['id'], ['description' => 'no scope'], scopes: ['write:statuses']);
		self::assertSame(403, $wrongUpdateScope->status);
		$anonymousDelete = $this->deleteMedia($app, $uploaded['id'], userId: null);
		self::assertSame(401, $anonymousDelete->status);
		$wrongDeleteScope = $this->deleteMedia($app, $uploaded['id'], scopes: ['write:statuses']);
		self::assertSame(403, $wrongDeleteScope->status);
		self::assertNotNull($attachments->find($uploaded['id']));

		$parentUpdate = $this->updateMedia($app, $uploaded['id'], ['description' => 'parent'], scopes: ['write']);
		self::assertSame(200, $parentUpdate->status);

		$disabled = $this->app(enabled: false);
		$disabledUpdate = $this->updateMedia($disabled, $uploaded['id'], ['description' => 'off']);
		self::assertSame(404, $disabledUpdate->status);
		self::assertSame('mastodon_api_disabled', $disabledUpdate->decodedBody()['error']['code']);
		$disabledDelete = $this->deleteMedia($disabled, $uploaded['id']);
		self::assertSame(404, $disabledDelete->status);
		self::assertSame('mastodon_api_disabled', $disabledDelete->decodedBody()['error']['code']);
	}

	public function testFavouriteAndUnfavouriteAreIdempotentAndProjected(): void
	{
		$favourites = new InMemoryFavouriteRepository();
		$app = $this->app(favourites: $favourites);
		$status = $this->createStatus($app, ['status' => 'favourite me'])->decodedBody();

		$first = $this->favourite($app, $status['id']);
		self::assertSame(200, $first->status);
		self::assertTrue($first->decodedBody()['favourited']);
		self::assertSame(1, $first->decodedBody()['favourites_count']);
		self::assertSame(1, $favourites->countForPost($status['id']));

		$again = $this->favourite($app, $status['id']);
		self::assertSame(200, $again->status);
		self::assertSame(1, $again->decodedBody()['favourites_count']);
		self::assertSame(1, $favourites->countForPost($status['id']));

		$ownerView = $app->handle(new Request(
			'GET',
			MastodonApiConfig::DEFAULT_PREFIX . '/statuses/' . $status['id'],
			userId: 'alice',
		));
		self::assertTrue($ownerView->decodedBody()['favourited']);

		$otherView = $app->handle(new Request(
			'GET',
			MastodonApiConfig::DEFAULT_PREFIX . '/statuses/' . $status['id'],
			userId: 'bob',
		));
		self::assertFalse($otherView->decodedBody()['favourited']);
		self::assertSame(1, $otherView->decodedBody()['favourites_count']);

		$timeline = $app->handle(new Request('GET', MastodonApiConfig::DEFAULT_PREFIX . '/timelines/public'));
		self::assertSame(1, $timeline->decodedBody()[0]['favourites_count']);
		self::assertFalse($timeline->decodedBody()[0]['favourited']);

		$unfavourite = $this->favourite($app, $status['id'], 'unfavourite');
		self::assertSame(200, $unfavourite->status);
		self::assertFalse($unfavourite->decodedBody()['favourited']);
		self::assertSame(0, $unfavourite->decodedBody()['favourites_count']);
		self::assertSame(0, $favourites->countForPost($status['id']));

		$againUnfavourite = $this->favourite($app, $status['id'], 'unfavourite');
		self::assertSame(200, $againUnfavourite->status);
		self::assertSame(0, $againUnfavourite->decodedBody()['favourites_count']);
	}

	public function testFavouriteCreatesOneNotificationForTheAuthorAndSurvivesUnfavourite(): void
	{
		$app = $this->app(actors: ['alice', 'bob']);
		$status = $this->createStatus($app, ['status' => 'notify bob'], userId: 'bob')->decodedBody();

		$this->favourite($app, $status['id']);
		$this->favourite($app, $status['id']);

		$notifications = $this->notifications($app, userId: 'bob');
		self::assertSame(200, $notifications->status);
		self::assertCount(1, $notifications->decodedBody());
		$notification = $notifications->decodedBody()[0];
		self::assertSame('favourite', $notification['type']);
		self::assertSame('alice', $notification['account']['id']);
		self::assertSame('alice', $notification['account']['acct']);
		self::assertSame($status['id'], $notification['status']['id']);
		self::assertFalse($notification['status']['favourited']);
		self::assertSame(1, $notification['status']['favourites_count']);
		self::assertSame('2026-11-11T10:00:00.000Z', $notification['created_at']);
		self::assertStringStartsWith('notification-', $notification['id']);

		$this->favourite($app, $status['id'], 'unfavourite');
		self::assertCount(1, $this->notifications($app, userId: 'bob')->decodedBody());
		self::assertCount(0, $this->notifications($app, userId: 'alice')->decodedBody());

		$deleted = $app->handle(new Request(
			method: 'DELETE',
			path: MastodonApiConfig::DEFAULT_PREFIX . '/statuses/' . $status['id'],
			userId: 'bob',
			scopes: ['write:statuses'],
		));
		self::assertSame(204, $deleted->status);
		$afterDelete = $this->notifications($app, userId: 'bob');
		self::assertCount(1, $afterDelete->decodedBody());
		self::assertArrayNotHasKey('status', $afterDelete->decodedBody()[0]);

		$own = $this->createStatus($app, ['status' => 'do not notify myself'])->decodedBody();
		$this->favourite($app, $own['id']);
		self::assertCount(0, $this->notifications($app, userId: 'alice')->decodedBody());
	}

	public function testFollowNotificationsAreFilteredAndPaginated(): void
	{
		$app = $this->app(actors: ['alice', 'bob', 'carol', 'dave']);
		$status = $this->createStatus($app, ['status' => 'many notifications'], userId: 'bob')->decodedBody();

		$this->followAccount($app, 'bob');
		$this->followAccount($app, 'bob');
		$this->followAccount($app, 'alice', userId: 'bob');
		$this->favourite($app, $status['id']);
		$this->favourite($app, $status['id'], userId: 'carol');
		$this->favourite($app, $status['id'], userId: 'dave');

		$all = $this->notifications($app, userId: 'bob');
		self::assertCount(4, $all->decodedBody());
		self::assertNotNull($all->header('Link'));

		$firstPage = $this->notifications($app, ['limit' => '2'], userId: 'bob');
		self::assertCount(2, $firstPage->decodedBody());
		self::assertStringContainsString('limit=2&max_id=', (string) $firstPage->header('Link'));

		$secondPage = $this->notifications($app, [
			'limit' => '2',
			'max_id' => $firstPage->decodedBody()[1]['id'],
		], userId: 'bob');
		self::assertCount(2, $secondPage->decodedBody());
		self::assertNotSame($firstPage->decodedBody()[0]['id'], $secondPage->decodedBody()[0]['id']);

		$newer = $this->notifications($app, [
			'since_id' => $secondPage->decodedBody()[1]['id'],
		], userId: 'bob');
		self::assertCount(3, $newer->decodedBody());
		$newerWithMin = $this->notifications($app, [
			'min_id' => $secondPage->decodedBody()[1]['id'],
		], userId: 'bob');
		self::assertCount(3, $newerWithMin->decodedBody());

		$follows = $this->notifications($app, ['types' => ['follow']], userId: 'bob');
		self::assertCount(1, $follows->decodedBody());
		self::assertSame('follow', $follows->decodedBody()[0]['type']);
		self::assertArrayNotHasKey('status', $follows->decodedBody()[0]);
		self::assertStringContainsString('types%5B0%5D=follow', (string) $follows->header('Link'));

		$withoutFavourites = $this->notifications($app, ['exclude_types' => ['favourite']], userId: 'bob');
		self::assertCount(1, $withoutFavourites->decodedBody());
		self::assertSame('follow', $withoutFavourites->decodedBody()[0]['type']);

		$unknownType = $this->notifications($app, ['types' => ['mention']], userId: 'bob');
		self::assertSame(200, $unknownType->status);
		self::assertSame([], $unknownType->decodedBody());
	}

	public function testDismissClearAndUnreadCountFollowTheReadMarker(): void
	{
		$app = $this->app(actors: ['alice', 'bob', 'carol']);
		$status = $this->createStatus($app, ['status' => 'read me'], userId: 'bob')->decodedBody();

		$this->followAccount($app, 'bob');
		$this->favourite($app, $status['id']);

		$all = $this->notifications($app, userId: 'bob');
		self::assertSame(200, $all->status);
		self::assertCount(2, $all->decodedBody());
		self::assertTrue($all->decodedBody()[0]['unread']);
		$dismissedId = $all->decodedBody()[0]['id'];

		$count = $this->unreadCount($app, userId: 'bob');
		self::assertSame(200, $count->status);
		self::assertSame(2, $count->decodedBody()['count']);

		$dismissed = $this->dismissNotification($app, $dismissedId, userId: 'bob');
		self::assertSame(200, $dismissed->status);
		self::assertSame([], $dismissed->decodedBody());

		$again = $this->dismissNotification($app, $dismissedId, userId: 'bob');
		self::assertSame(200, $again->status);
		self::assertSame([], $again->decodedBody());

		$remaining = $this->notifications($app, userId: 'bob');
		self::assertCount(1, $remaining->decodedBody());
		self::assertNotSame($dismissedId, $remaining->decodedBody()[0]['id']);
		self::assertTrue($remaining->decodedBody()[0]['unread']);
		self::assertSame(1, $this->unreadCount($app, userId: 'bob')->decodedBody()['count']);

		$cleared = $this->clearNotifications($app, userId: 'bob');
		self::assertSame(200, $cleared->status);
		self::assertSame([], $cleared->decodedBody());
		self::assertSame(0, $this->unreadCount($app, userId: 'bob')->decodedBody()['count']);
		self::assertSame([], $this->notifications($app, userId: 'bob')->decodedBody());

		$clearAgain = $this->clearNotifications($app, userId: 'bob');
		self::assertSame(200, $clearAgain->status);
		self::assertSame(200, $this->dismissNotification($app, $dismissedId, userId: 'bob')->status);

		// A new event after clear is a new row and is unread again.
		$this->followAccount($app, 'bob', userId: 'carol');
		self::assertSame(1, $this->unreadCount($app, userId: 'bob')->decodedBody()['count']);
		self::assertCount(1, $this->notifications($app, userId: 'bob')->decodedBody());
	}

	public function testDismissAndClearCannotTouchAnotherAccountsNotifications(): void
	{
		$app = $this->app(actors: ['alice', 'bob']);
		$this->followAccount($app, 'alice', userId: 'bob');
		$aliceNotification = $this->notifications($app, userId: 'alice')->decodedBody()[0];

		$foreignDismiss = $this->dismissNotification($app, $aliceNotification['id'], userId: 'bob');
		self::assertSame(404, $foreignDismiss->status);
		self::assertSame('not_found', $foreignDismiss->decodedBody()['error']['code']);

		$foreignClear = $this->clearNotifications($app, userId: 'bob');
		self::assertSame(200, $foreignClear->status);
		self::assertCount(1, $this->notifications($app, userId: 'alice')->decodedBody());
		self::assertSame(1, $this->unreadCount($app, userId: 'alice')->decodedBody()['count']);

		$missing = $this->dismissNotification($app, 'notification-missing', userId: 'alice');
		self::assertSame(404, $missing->status);
	}

	public function testUnreadCountSupportsMastodonLocalFiltersAndIsCapped(): void
	{
		$app = $this->app(actors: ['alice', 'bob', 'carol', 'dave']);
		$status = $this->createStatus($app, ['status' => 'count me'], userId: 'bob')->decodedBody();

		$this->followAccount($app, 'bob');
		$this->favourite($app, $status['id']);
		$this->favourite($app, $status['id'], userId: 'carol');

		self::assertSame(3, $this->unreadCount($app, userId: 'bob')->decodedBody()['count']);
		self::assertSame(1, $this->unreadCount($app, ['types' => ['follow']], userId: 'bob')->decodedBody()['count']);
		self::assertSame(1, $this->unreadCount($app, ['exclude_types' => ['favourite']], userId: 'bob')->decodedBody()['count']);
		self::assertSame(2, $this->unreadCount($app, ['account_id' => 'alice'], userId: 'bob')->decodedBody()['count']);
		self::assertSame(1, $this->unreadCount($app, ['limit' => '1'], userId: 'bob')->decodedBody()['count']);
		self::assertSame(3, $this->unreadCount($app, ['limit' => '0'], userId: 'bob')->decodedBody()['count']);
		self::assertSame(3, $this->unreadCount($app, ['limit' => '1001'], userId: 'bob')->decodedBody()['count']);
		self::assertSame(0, $this->unreadCount($app, ['types' => ['mention']], userId: 'bob')->decodedBody()['count']);
	}

	public function testNotificationDismissClearAndUnreadCountRequireAuthScopeAndEnabledApi(): void
	{
		$app = $this->app(actors: ['alice', 'bob']);
		$this->followAccount($app, 'bob');
		$id = $this->notifications($app, userId: 'bob')->decodedBody()[0]['id'];

		self::assertSame(401, $this->unreadCount($app, userId: null)->status);
		self::assertSame(401, $this->dismissNotification($app, $id, userId: null)->status);
		self::assertSame(401, $this->clearNotifications($app, userId: null)->status);

		self::assertSame(403, $this->unreadCount($app, scopes: ['read:statuses'], userId: 'bob')->status);
		self::assertSame(403, $this->dismissNotification($app, $id, scopes: ['read:notifications'], userId: 'bob')->status);
		self::assertSame(403, $this->clearNotifications($app, scopes: ['read:notifications'], userId: 'bob')->status);

		self::assertSame(200, $this->dismissNotification($app, $id, scopes: ['write'], userId: 'bob')->status);
		self::assertSame(200, $this->clearNotifications($app, scopes: ['write'], userId: 'bob')->status);

		$disabled = $this->app(enabled: false);
		self::assertSame(404, $this->unreadCount($disabled, userId: 'bob')->status);
		self::assertSame(404, $this->dismissNotification($disabled, $id, userId: 'bob')->status);
		self::assertSame(404, $this->clearNotifications($disabled, userId: 'bob')->status);
	}

	public function testNotificationStorageFailureKeepsThePrimaryWriteAndAnswers503(): void
	{
		$app = $this->app(
			actors: ['alice', 'bob'],
			notificationRepository: new FailingNotificationRepository(),
		);
		$status = $this->createStatus($app, ['status' => 'still favourited'], userId: 'bob')->decodedBody();

		$favourite = $this->favourite($app, $status['id']);
		self::assertSame(200, $favourite->status);
		self::assertTrue($favourite->decodedBody()['favourited']);

		$follow = $this->followAccount($app, 'bob');
		self::assertSame(200, $follow->status);
		self::assertTrue($follow->decodedBody()['following']);

		$notifications = $this->notifications($app, userId: 'bob');
		self::assertSame(503, $notifications->status);
		self::assertSame('notifications_unavailable', $notifications->decodedBody()['error']['code']);

		self::assertSame(503, $this->dismissNotification($app, 'notification-any', userId: 'bob')->status);
		self::assertSame(503, $this->clearNotifications($app, userId: 'bob')->status);
		$count = $this->unreadCount($app, userId: 'bob');
		self::assertSame(503, $count->status);
		self::assertSame('notifications_unavailable', $count->decodedBody()['error']['code']);
	}

	public function testNotificationsRequireAuthScopeAndAValidCursor(): void
	{
		$app = $this->app(actors: ['alice', 'bob']);
		$status = $this->createStatus($app, ['status' => 'cursor'], userId: 'bob')->decodedBody();
		$this->favourite($app, $status['id']);

		$anonymous = $this->notifications($app, userId: null);
		self::assertSame(401, $anonymous->status);
		self::assertSame('authentication_required', $anonymous->decodedBody()['error']['code']);

		$wrongScope = $this->notifications($app, scopes: ['read:statuses']);
		self::assertSame(403, $wrongScope->status);
		self::assertSame('insufficient_scope', $wrongScope->decodedBody()['error']['code']);

		$disabled = $this->app(enabled: false);
		$off = $this->notifications($disabled, userId: null);
		self::assertSame(404, $off->status);
		self::assertSame('mastodon_api_disabled', $off->decodedBody()['error']['code']);

		$missingCursor = $this->notifications($app, ['max_id' => 'notification-missing'], userId: 'bob');
		self::assertSame(404, $missingCursor->status);

		$combined = $this->notifications($app, [
			'max_id' => $this->notifications($app, userId: 'bob')->decodedBody()[0]['id'],
			'since_id' => $this->notifications($app, userId: 'bob')->decodedBody()[0]['id'],
		], userId: 'bob');
		self::assertSame(422, $combined->status);
	}

	public function testFavouriteRequiresAuthAndWriteFavouritesScope(): void
	{
		$favourites = new InMemoryFavouriteRepository();
		$app = $this->app(favourites: $favourites);
		$status = $this->createStatus($app, ['status' => 'scope me'])->decodedBody();

		$anonymous = $this->favourite($app, $status['id'], userId: null);
		self::assertSame(401, $anonymous->status);
		self::assertSame('authentication_required', $anonymous->decodedBody()['error']['code']);

		$wrongScope = $this->favourite($app, $status['id'], scopes: ['write:statuses']);
		self::assertSame(403, $wrongScope->status);
		self::assertSame('insufficient_scope', $wrongScope->decodedBody()['error']['code']);
		self::assertSame(0, $favourites->countForPost($status['id']));

		$parentScope = $this->favourite($app, $status['id'], scopes: ['write']);
		self::assertSame(200, $parentScope->status);
		self::assertSame(1, $favourites->countForPost($status['id']));
	}

	public function testFavouriteRejectsAStatusTheLocalCallerCannotSee(): void
	{
		$favourites = new InMemoryFavouriteRepository();
		$app = $this->app(favourites: $favourites, actors: ['alice', 'bob']);
		$status = $this->createStatus($app, [
			'status' => 'followers only',
			'visibility' => 'private',
		])->decodedBody();

		$response = $this->favourite($app, $status['id'], userId: 'bob');

		self::assertSame(404, $response->status);
		self::assertSame('not_found', $response->decodedBody()['error']['code']);
		self::assertSame(0, $favourites->countForPost($status['id']));
	}

	public function testFavouriteIsDisabledBeforeAuthAndRejectsMissingOrDeletedStatuses(): void
	{
		$disabled = $this->app(enabled: false);
		$response = $this->favourite($disabled, 'anything', userId: null);
		self::assertSame(404, $response->status);
		self::assertSame('mastodon_api_disabled', $response->decodedBody()['error']['code']);

		$app = $this->app();
		$missing = $this->favourite($app, 'does-not-exist');
		self::assertSame(404, $missing->status);
		self::assertSame('not_found', $missing->decodedBody()['error']['code']);

		$status = $this->createStatus($app, ['status' => 'delete me'])->decodedBody();
		$deleted = $app->handle(new Request(
			method: 'DELETE',
			path: MastodonApiConfig::DEFAULT_PREFIX . '/statuses/' . $status['id'],
			userId: 'alice',
			scopes: ['write:statuses'],
		));
		self::assertSame(204, $deleted->status);
		self::assertSame(404, $this->favourite($app, $status['id'])->status);
	}

	public function testLocalFollowUnfollowAndRelationshipsAreIdempotent(): void
	{
		$app = $this->app(actors: ['alice', 'bob']);

		$followed = $this->followAccount($app, 'bob');
		self::assertSame(200, $followed->status);
		self::assertSame('bob', $followed->decodedBody()['id']);
		self::assertTrue($followed->decodedBody()['following']);
		self::assertFalse($followed->decodedBody()['followed_by']);
		self::assertTrue($followed->decodedBody()['showing_reblogs']);
		self::assertFalse($followed->decodedBody()['blocking']);
		self::assertFalse($followed->decodedBody()['requested']);
		self::assertSame(1, $app->followers->count('bob'));

		$repeated = $this->followAccount($app, 'bob');
		self::assertSame(200, $repeated->status);
		self::assertTrue($repeated->decodedBody()['following']);
		self::assertSame(1, $app->followers->count('bob'));

		$bobView = $this->relationships($app, ['alice'], userId: 'bob');
		self::assertSame(200, $bobView->status);
		self::assertCount(1, $bobView->decodedBody());
		self::assertFalse($bobView->decodedBody()[0]['following']);
		self::assertTrue($bobView->decodedBody()[0]['followed_by']);

		$alice = $app->handle(new Request(
			'GET',
			MastodonApiConfig::DEFAULT_PREFIX . '/accounts/verify_credentials',
			userId: 'alice',
			scopes: ['read:accounts'],
		))->decodedBody();
		self::assertSame(0, $alice['followers_count']);
		self::assertSame(1, $alice['following_count']);

		$bob = $app->handle(new Request(
			'GET',
			MastodonApiConfig::DEFAULT_PREFIX . '/accounts/verify_credentials',
			userId: 'bob',
			scopes: ['read:accounts'],
		))->decodedBody();
		self::assertSame(1, $bob['followers_count']);
		self::assertSame(0, $bob['following_count']);

		$unfollowed = $this->followAccount($app, 'bob', 'unfollow');
		self::assertSame(200, $unfollowed->status);
		self::assertFalse($unfollowed->decodedBody()['following']);
		self::assertSame(0, $app->followers->count('bob'));

		$repeatedUnfollow = $this->followAccount($app, 'bob', 'unfollow');
		self::assertSame(200, $repeatedUnfollow->status);
		self::assertFalse($repeatedUnfollow->decodedBody()['following']);
		$bobViewAfter = $this->relationships($app, ['alice'], userId: 'bob')->decodedBody();
		self::assertFalse($bobViewAfter[0]['followed_by']);
	}

	public function testAccountProfileAndLocalFollowListsArePublicAndPaginated(): void
	{
		$app = $this->app(actors: ['alice', 'bob', 'carol']);

		$this->followAccount($app, 'bob', userId: 'alice');
		$this->followAccount($app, 'bob', userId: 'carol');
		$this->followAccount($app, 'carol', userId: 'alice');

		$profile = $app->handle(new Request(
			'GET',
			MastodonApiConfig::DEFAULT_PREFIX . '/accounts/bob',
		));
		self::assertSame(200, $profile->status);
		self::assertSame('bob', $profile->decodedBody()['id']);
		self::assertSame('bob', $profile->decodedBody()['acct']);
		self::assertSame(2, $profile->decodedBody()['followers_count']);
		self::assertSame(0, $profile->decodedBody()['following_count']);

		$followers = $app->handle(new Request(
			'GET',
			MastodonApiConfig::DEFAULT_PREFIX . '/accounts/bob/followers',
			query: ['limit' => '1'],
		));
		self::assertSame(200, $followers->status);
		self::assertCount(1, $followers->decodedBody());
		$firstFollower = $followers->decodedBody()[0]['id'];
		self::assertContains($firstFollower, ['alice', 'carol']);
		self::assertStringContainsString('rel="next"', $followers->headers['Link']);

		$nextFollowers = $app->handle(new Request(
			'GET',
			MastodonApiConfig::DEFAULT_PREFIX . '/accounts/bob/followers',
			query: ['limit' => '1', 'max_id' => $firstFollower],
		));
		self::assertSame(200, $nextFollowers->status);
		self::assertCount(1, $nextFollowers->decodedBody());
		self::assertNotSame($firstFollower, $nextFollowers->decodedBody()[0]['id']);

		$following = $app->handle(new Request(
			'GET',
			MastodonApiConfig::DEFAULT_PREFIX . '/accounts/alice/following',
			query: ['limit' => '1'],
		));
		self::assertSame(200, $following->status);
		self::assertCount(1, $following->decodedBody());
		$firstFollowing = $following->decodedBody()[0]['id'];
		self::assertContains($firstFollowing, ['bob', 'carol']);

		$nextFollowing = $app->handle(new Request(
			'GET',
			MastodonApiConfig::DEFAULT_PREFIX . '/accounts/alice/following',
			query: ['limit' => '1', 'max_id' => $firstFollowing],
		));
		self::assertSame(200, $nextFollowing->status);
		self::assertCount(1, $nextFollowing->decodedBody());
		self::assertNotSame($firstFollowing, $nextFollowing->decodedBody()[0]['id']);

		$missingCursor = $app->handle(new Request(
			'GET',
			MastodonApiConfig::DEFAULT_PREFIX . '/accounts/alice/following',
			query: ['max_id' => 'missing-account'],
		));
		self::assertSame(404, $missingCursor->status);
		self::assertSame('not_found', $missingCursor->decodedBody()['error']['code']);

		$combined = $app->handle(new Request(
			'GET',
			MastodonApiConfig::DEFAULT_PREFIX . '/accounts/alice/following',
			query: ['max_id' => $firstFollowing, 'since_id' => $firstFollowing],
		));
		self::assertSame(422, $combined->status);
	}

	public function testAccountFollowingProjectsRemoteTargetsAsMinimalAccounts(): void
	{
		$following = new InMemoryFollowingRepository();
		$app = $this->app(
			actors: ['alice'],
			followingRepository: $following,
		);
		$following->insert(new FollowedActor(
			id: 'remote-follow',
			localUserId: 'alice',
			actorId: 'https://remote.example/users/bob',
			inboxUrl: 'https://remote.example/users/bob/inbox',
			followedAt: Time::parse('2026-11-11T10:00:00Z'),
		));

		$response = $app->handle(new Request(
			'GET',
			MastodonApiConfig::DEFAULT_PREFIX . '/accounts/alice/following',
		));

		self::assertSame(200, $response->status);
		self::assertCount(1, $response->decodedBody());
		self::assertSame('https://remote.example/users/bob', $response->decodedBody()[0]['id']);
		self::assertSame('bob', $response->decodedBody()[0]['username']);
		self::assertSame('bob@remote.example', $response->decodedBody()[0]['acct']);
		self::assertSame('https://remote.example/users/bob', $response->decodedBody()[0]['url']);
	}

	public function testAccountProfileHidesUnknownAndRemoteAccountsAndHonoursTheDisabledFeature(): void
	{
		$app = $this->app(actors: ['alice']);

		$missing = $app->handle(new Request(
			'GET',
			MastodonApiConfig::DEFAULT_PREFIX . '/accounts/ghost',
		));
		self::assertSame(404, $missing->status);
		self::assertSame('not_found', $missing->decodedBody()['error']['code']);

		$remote = $app->handle(new Request(
			'GET',
			MastodonApiConfig::DEFAULT_PREFIX . '/accounts/' . rawurlencode('https://remote.example/users/bob'),
		));
		self::assertSame(404, $remote->status);

		$missingFollowers = $app->handle(new Request(
			'GET',
			MastodonApiConfig::DEFAULT_PREFIX . '/accounts/ghost/followers',
		));
		self::assertSame(404, $missingFollowers->status);

		$disabled = $this->app(enabled: false);
		foreach (['/accounts/alice', '/accounts/alice/followers', '/accounts/alice/following'] as $path) {
			$response = $disabled->handle(new Request(
				'GET',
				MastodonApiConfig::DEFAULT_PREFIX . $path,
			));
			self::assertSame(404, $response->status);
			self::assertSame('mastodon_api_disabled', $response->decodedBody()['error']['code']);
		}
	}

	public function testLocalFollowAddsTheAuthorsPostsToTheHomeTimeline(): void
	{
		$app = $this->app(actors: ['alice', 'bob']);
		$status = $this->createStatus($app, ['status' => 'from bob'], userId: 'bob')->decodedBody();

		$this->followAccount($app, 'bob');

		$home = $app->handle(new Request(
			'GET',
			'/api/v1/timelines/home',
			userId: 'alice',
		));
		self::assertSame(200, $home->status);
		self::assertSame([$status['id']], array_column($home->decodedBody()['posts'], 'id'));

		$this->followAccount($app, 'bob', 'unfollow');
		self::assertSame([], $app->handle(new Request(
			'GET',
			'/api/v1/timelines/home',
			userId: 'alice',
		))->decodedBody()['posts']);
	}

	public function testBlockingALocalFollowedAccountHidesItsPostsUntilUnblock(): void
	{
		$app = $this->app(actors: ['alice', 'bob']);
		$status = $this->createStatus($app, ['status' => 'hide me'], userId: 'bob')->decodedBody();
		$bobActor = 'https://social.example/@bob';

		$this->followAccount($app, 'bob');
		self::assertSame([$status['id']], array_column($app->handle(new Request(
			'GET',
			'/api/v1/timelines/home',
			userId: 'alice',
		))->decodedBody()['posts'], 'id'));

		$blocked = $app->handle(new Request(
			method: 'POST',
			path: '/api/v1/blocks',
			headers: ['Content-Type' => 'application/json'],
			body: json_encode(['actor' => $bobActor], JSON_THROW_ON_ERROR),
			userId: 'alice',
		));
		self::assertSame(201, $blocked->status);
		self::assertSame($bobActor, $blocked->decodedBody()['block']['actor']);

		$hidden = $app->handle(new Request(
			'GET',
			'/api/v1/timelines/home',
			userId: 'alice',
		));
		self::assertSame(200, $hidden->status);
		self::assertSame([], $hidden->decodedBody()['posts']);

		$blockId = $blocked->decodedBody()['block']['id'];
		self::assertSame(204, $app->handle(new Request(
			'DELETE',
			'/api/v1/blocks/' . $blockId,
			userId: 'alice',
		))->status);

		$restored = $app->handle(new Request(
			'GET',
			'/api/v1/timelines/home',
			userId: 'alice',
		));
		self::assertSame([$status['id']], array_column($restored->decodedBody()['posts'], 'id'));
	}

	public function testAccountStatusesRespectLocalFollowerVisibility(): void
	{
		$app = $this->app(actors: ['alice', 'bob', 'carol']);
		$status = $this->createStatus($app, [
			'status' => 'followers only',
			'visibility' => 'private',
		], userId: 'bob')->decodedBody();
		$path = MastodonApiConfig::DEFAULT_PREFIX . '/accounts/bob/statuses';
		$detailPath = MastodonApiConfig::DEFAULT_PREFIX . '/statuses/' . $status['id'];

		self::assertSame([], $app->handle(new Request('GET', $path))->decodedBody());
		self::assertSame([], $app->handle(new Request('GET', $path, userId: 'alice'))->decodedBody());
		self::assertSame(404, $app->handle(new Request('GET', $detailPath, userId: 'alice'))->status);

		$this->followAccount($app, 'bob');
		self::assertSame(200, $app->handle(new Request('GET', $detailPath, userId: 'alice'))->status);
		self::assertSame(200, $app->handle(new Request(
			method: 'POST',
			path: MastodonApiConfig::DEFAULT_PREFIX . '/statuses/' . $status['id'] . '/favourite',
			userId: 'alice',
			scopes: ['write:favourites'],
		))->status);

		$aliceStatuses = $app->handle(new Request('GET', $path, userId: 'alice'))->decodedBody();
		self::assertSame([$status['id']], array_column($aliceStatuses, 'id'));
		self::assertTrue($aliceStatuses[0]['favourited']);
		self::assertSame([$status['id']], array_column(
			$app->handle(new Request('GET', $path, userId: 'bob'))->decodedBody(),
			'id',
		));
		self::assertSame([], $app->handle(new Request('GET', $path, userId: 'carol'))->decodedBody());
		self::assertSame([], $app->handle(new Request('GET', $path))->decodedBody());

		$this->followAccount($app, 'bob', 'unfollow');
		self::assertSame([], $app->handle(new Request('GET', $path, userId: 'alice'))->decodedBody());
		self::assertSame(404, $app->handle(new Request('GET', $detailPath, userId: 'alice'))->status);
	}

	public function testLocalFollowRejectsSelfUnknownAndMissingAuthOrScope(): void
	{
		$app = $this->app(actors: ['alice', 'bob']);

		$self = $this->followAccount($app, 'alice');
		self::assertSame(422, $self->status);
		self::assertSame('validation_failed', $self->decodedBody()['error']['code']);
		self::assertArrayHasKey('id', $self->decodedBody()['error']['fields']);
		self::assertSame(0, $app->followers->count('alice'));

		self::assertSame(404, $this->followAccount($app, 'nobody')->status);
		self::assertSame(401, $this->followAccount($app, 'bob', userId: null)->status);
		self::assertSame(403, $this->followAccount($app, 'bob', scopes: ['write:statuses'])->status);
		self::assertSame(0, $app->followers->count('bob'));

		$parent = $this->followAccount($app, 'bob', scopes: ['write']);
		self::assertSame(200, $parent->status);
		self::assertTrue($parent->decodedBody()['following']);

		$disabled = $this->app(enabled: false, actors: ['alice', 'bob']);
		$hidden = $this->followAccount($disabled, 'bob');
		self::assertSame(404, $hidden->status);
		self::assertSame('mastodon_api_disabled', $hidden->decodedBody()['error']['code']);

		$unconfigured = $this->app(actors: null);
		$missing = $this->followAccount($unconfigured, 'bob');
		self::assertSame(503, $missing->status);
		self::assertSame('follows_not_configured', $missing->decodedBody()['error']['code']);

		$noInstanceUrl = $this->app(instanceUrl: null, actors: ['alice', 'bob']);
		$badConfig = $this->followAccount($noInstanceUrl, 'bob');
		self::assertSame(503, $badConfig->status);
		self::assertSame('instance_not_configured', $badConfig->decodedBody()['error']['code']);
	}

	public function testRelationshipsAcceptScalarAndArrayIdsAndValidateThem(): void
	{
		$app = $this->app(actors: ['alice', 'bob', 'carol']);
		$this->followAccount($app, 'bob');

		$scalar = $this->relationships($app, 'bob');
		self::assertSame(200, $scalar->status);
		self::assertCount(1, $scalar->decodedBody());
		self::assertTrue($scalar->decodedBody()[0]['following']);

		$duplicates = $this->relationships($app, ['bob', 'bob', 'carol']);
		self::assertSame(200, $duplicates->status);
		self::assertSame(['bob', 'carol'], array_column($duplicates->decodedBody(), 'id'));

		$missing = $this->relationships($app, []);
		self::assertSame(422, $missing->status);
		self::assertSame('validation_failed', $missing->decodedBody()['error']['code']);

		$unknown = $this->relationships($app, ['nobody']);
		self::assertSame(404, $unknown->status);

		$anonymous = $this->relationships($app, ['bob'], userId: null);
		self::assertSame(401, $anonymous->status);
		$wrongScope = $this->relationships($app, ['bob'], scopes: ['read:accounts']);
		self::assertSame(403, $wrongScope->status);
		$parentScope = $this->relationships($app, ['bob'], scopes: ['read']);
		self::assertSame(200, $parentScope->status);

		$disabled = $this->relationships($this->app(enabled: false, actors: ['alice', 'bob']), ['bob']);
		self::assertSame(404, $disabled->status);
		self::assertSame('mastodon_api_disabled', $disabled->decodedBody()['error']['code']);

		$tooMany = array_map(static fn (int $index): string => 'id-' . $index, range(1, 101));
		self::assertSame(422, $this->relationships($app, $tooMany)->status);
	}

	public function testAnExistingAttachmentIsProjectedOnTheStatus(): void
	{
		$app = $this->app();
		self::assertNotNull($app->media);
		$attachment = $app->media->upload('alice', $this->png(), 'a test image');

		$created = $this->createStatus($app, [
			'status' => 'with media',
			'media_ids' => [$attachment->id],
		]);
		self::assertSame(200, $created->status);
		$media = $created->decodedBody()['media_attachments'];
		self::assertCount(1, $media);
		self::assertSame($attachment->id, $media[0]['id']);
		self::assertSame('a test image', $media[0]['description']);
		self::assertStringContainsString('/api/v1/media/' . $attachment->id, $media[0]['url']);
	}

	public function testThePrefixIsConfigurableAndDoesNotShadowTheCoreApi(): void
	{
		$app = $this->app(prefix: '/compat/mastodon');

		self::assertSame(200, $app->handle(new Request('GET', '/compat/mastodon/instance'))->status);
		self::assertSame(404, $app->handle(new Request('GET', '/api/mastodon/v1/instance'))->status);
		self::assertSame(200, $app->handle(new Request('GET', '/api/v1/instance'))->status);

		$created = $app->handle(new Request(
			method: 'POST',
			path: '/compat/mastodon/statuses',
			headers: ['Content-Type' => 'application/x-www-form-urlencoded'],
			body: 'status=custom-prefix',
			userId: 'alice',
			scopes: ['write:statuses'],
		));
		self::assertSame(200, $created->status);
		self::assertSame(200, $app->handle(new Request(
			'GET',
			'/compat/mastodon/statuses/' . $created->decodedBody()['id'],
		))->status);
		self::assertSame(200, $this->favourite(
			$app,
			$created->decodedBody()['id'],
			prefix: '/compat/mastodon',
		)->status);

		$uploaded = $this->upload($app, $this->png(), 'custom prefix', prefix: '/compat/mastodon');
		self::assertSame(200, $uploaded->status);
		self::assertStringContainsString('/api/v1/media/', $uploaded->decodedBody()['url']);
		self::assertSame(200, $this->updateMedia(
			$app,
			$uploaded->decodedBody()['id'],
			['description' => 'custom update'],
			prefix: '/compat/mastodon',
		)->status);
		self::assertSame(200, $this->deleteMedia(
			$app,
			$uploaded->decodedBody()['id'],
			prefix: '/compat/mastodon',
		)->status);
	}

	public function testSearchFindsLocalAccountsAndStatuses(): void
	{
		$app = $this->app(actors: ['alice', 'bob']);
		$this->createStatus($app, ['status' => 'alice says hello']);
		$this->createStatus($app, ['status' => 'bob says hello'], userId: 'bob');

		$all = $this->search($app, [
			'q' => 'alice',
			'resolve' => 'true',
			'exclude_unreviewed' => 'false',
		]);
		self::assertSame(200, $all->status);
		self::assertCount(1, $all->decodedBody()['accounts']);
		self::assertSame('alice', $all->decodedBody()['accounts'][0]['id']);
		self::assertCount(1, $all->decodedBody()['statuses']);
		self::assertSame('alice', $all->decodedBody()['statuses'][0]['account']['id']);
		self::assertSame([], $all->decodedBody()['hashtags']);

		$statuses = $this->search($app, ['q' => 'hello', 'type' => 'statuses']);
		self::assertCount(2, $statuses->decodedBody()['statuses']);
		self::assertSame([], $statuses->decodedBody()['accounts']);

		$accounts = $this->search($app, ['q' => 'hello', 'type' => 'accounts']);
		self::assertSame([], $accounts->decodedBody()['accounts']);
		self::assertSame([], $accounts->decodedBody()['statuses']);

		$hashtags = $this->search($app, ['q' => 'hello', 'type' => 'hashtags']);
		self::assertSame([], $hashtags->decodedBody()['accounts']);
		self::assertSame([], $hashtags->decodedBody()['statuses']);
		self::assertSame([], $hashtags->decodedBody()['hashtags']);
	}

	public function testSearchCanBeLimitedToFollowedAccountsAndToAnOffsetPage(): void
	{
		$app = $this->app(actors: ['alice', 'bob']);
		$this->createStatus($app, ['status' => 'alice hello one']);
		$this->createStatus($app, ['status' => 'alice hello two']);
		$this->createStatus($app, ['status' => 'bob hello three'], userId: 'bob');

		$all = $this->search($app, ['q' => 'hello', 'type' => 'statuses', 'limit' => '2']);
		$next = $this->search($app, ['q' => 'hello', 'type' => 'statuses', 'limit' => '2', 'offset' => '2']);
		self::assertCount(2, $all->decodedBody()['statuses']);
		self::assertCount(1, $next->decodedBody()['statuses']);
		self::assertNotSame($all->decodedBody()['statuses'][0]['id'], $next->decodedBody()['statuses'][0]['id']);

		$this->followAccount($app, 'bob');
		$following = $this->search($app, [
			'q' => 'hello',
			'type' => 'statuses',
			'following' => 'true',
		]);
		self::assertCount(1, $following->decodedBody()['statuses']);
		self::assertSame('bob', $following->decodedBody()['statuses'][0]['account']['id']);

		$followingAccounts = $this->search($app, [
			'q' => 'bob',
			'type' => 'accounts',
			'following' => 'true',
		]);
		self::assertCount(1, $followingAccounts->decodedBody()['accounts']);
		self::assertSame('bob', $followingAccounts->decodedBody()['accounts'][0]['id']);
	}

	public function testSearchHidesPrivateAndDeletedStatuses(): void
	{
		$app = $this->app();
		$private = $this->createStatus($app, [
			'status' => 'private hello',
			'visibility' => 'private',
		])->decodedBody();
		$public = $this->createStatus($app, ['status' => 'public hello'])->decodedBody();

		self::assertCount(1, $this->search($app, ['q' => 'hello'])->decodedBody()['statuses']);

		$app->handle(new Request(
			method: 'DELETE',
			path: MastodonApiConfig::DEFAULT_PREFIX . '/statuses/' . $public['id'],
			userId: 'alice',
			scopes: ['write:statuses'],
		));

		self::assertSame([], $this->search($app, ['q' => 'hello'])->decodedBody()['statuses']);
		self::assertSame($private['id'], $app->handle(new Request(
			'GET',
			MastodonApiConfig::DEFAULT_PREFIX . '/statuses/' . $private['id'],
			userId: 'alice',
		))->decodedBody()['id']);
	}

	public function testSearchRejectsMissingOrUnknownInputAndRequiresItsScope(): void
	{
		$app = $this->app();

		$missing = $this->search($app, ['type' => 'statuses']);
		self::assertSame(422, $missing->status);
		self::assertSame('validation_failed', $missing->decodedBody()['error']['code']);

		$unknown = $this->search($app, ['q' => 'hello', 'type' => 'unknown']);
		self::assertSame(422, $unknown->status);
		self::assertSame('validation_failed', $unknown->decodedBody()['error']['code']);

		$anonymous = $app->handle(new Request(
			'GET',
			MastodonApiConfig::DEFAULT_PREFIX . '/search?q=hello',
		));
		self::assertSame(401, $anonymous->status);

		$wrongScope = $this->search($app, ['q' => 'hello'], scopes: ['read:accounts']);
		self::assertSame(403, $wrongScope->status);
		self::assertSame('insufficient_scope', $wrongScope->decodedBody()['error']['code']);

		self::assertSame(200, $this->search($app, ['q' => 'alice'], scopes: ['read'])->status);

		$disabled = $this->search($this->app(enabled: false), ['q' => 'alice']);
		self::assertSame(404, $disabled->status);
		self::assertSame('mastodon_api_disabled', $disabled->decodedBody()['error']['code']);
	}

	public function testSearchReportsMissingLocalCapabilities(): void
	{
		$app = $this->app(actors: null);

		$accounts = $this->search($app, ['q' => 'alice', 'type' => 'accounts']);
		self::assertSame(503, $accounts->status);
		self::assertSame('search_not_configured', $accounts->decodedBody()['error']['code']);

		$following = $this->search($app, ['q' => 'alice', 'following' => 'true']);
		self::assertSame(503, $following->status);
		self::assertSame('search_not_configured', $following->decodedBody()['error']['code']);
	}

	public function testDirectVisibilityWithoutARecipientAndMissingInputAreRejected(): void
	{
		$app = $this->app();

		$direct = $this->createStatus($app, ['status' => 'private', 'visibility' => 'direct']);
		self::assertSame(422, $direct->status);
		self::assertSame('validation_failed', $direct->decodedBody()['error']['code']);

		$missing = $this->createStatus($app, []);
		self::assertSame(422, $missing->status);
		self::assertSame('validation_failed', $missing->decodedBody()['error']['code']);
	}

	public function testDirectStatusesUseLocalMentionsAndRepliesExposeTheirTarget(): void
	{
		$app = $this->app(actors: ['alice', 'bob', 'carol']);

		$direct = $this->createStatus($app, [
			'status' => '@bob the secret',
			'visibility' => 'direct',
		]);
		self::assertSame(200, $direct->status);
		$body = $direct->decodedBody();
		self::assertSame('direct', $body['visibility']);
		self::assertSame(['bob'], array_column($body['mentions'], 'id'));

		self::assertSame(200, $app->handle(new Request(
			'GET',
			MastodonApiConfig::DEFAULT_PREFIX . '/statuses/' . $body['id'],
			userId: 'bob',
		))->status);
		self::assertSame(404, $app->handle(new Request(
			'GET',
			MastodonApiConfig::DEFAULT_PREFIX . '/statuses/' . $body['id'],
			userId: 'carol',
		))->status);

		$explicit = $this->createStatus($app, [
			'status' => 'explicit audience',
			'visibility' => 'direct',
			'recipient_ids' => ['bob'],
		])->decodedBody();
		self::assertSame(['bob'], array_column($explicit['mentions'], 'id'));

		// A full remote handle must not be reduced to the known local id
		// `carol`; direct delivery in this slice only addresses local ids.
		self::assertSame(422, $this->createStatus($app, [
			'status' => '@bob@carol',
			'visibility' => 'direct',
		])->status);

		$parent = $this->createStatus($app, ['status' => 'parent'], userId: 'bob')->decodedBody();
		$reply = $this->createStatus($app, [
			'status' => 'a reply',
			'in_reply_to_id' => $parent['id'],
		])->decodedBody();
		self::assertSame($parent['id'], $reply['in_reply_to_id']);
		self::assertSame('bob', $reply['in_reply_to_account_id']);
	}

	public function testAPrivateStatusIsNotPubliclyReadable(): void
	{
		$app = $this->app();
		$status = $this->createStatus($app, [
			'status' => 'followers only',
			'visibility' => 'private',
		])->decodedBody();

		self::assertSame(404, $app->handle(new Request(
			'GET',
			MastodonApiConfig::DEFAULT_PREFIX . '/statuses/' . $status['id'],
		))->status);
		self::assertSame(200, $app->handle(new Request(
			'GET',
			MastodonApiConfig::DEFAULT_PREFIX . '/statuses/' . $status['id'],
			userId: 'alice',
		))->status);
		self::assertCount(0, $app->handle(new Request(
			'GET',
			MastodonApiConfig::DEFAULT_PREFIX . '/timelines/public',
		))->decodedBody());
	}

	public function testPollVotePersistsChoicesAndProjectsTheViewerState(): void
	{
		$app = $this->app(actors: ['alice', 'bob']);
		$status = $this->createStatus($app, [
			'status' => 'Choose two',
			'poll' => [
				'options' => ['A', 'B', 'C'],
				'expires_in' => '3600',
				'multiple' => 'true',
			],
		])->decodedBody();
		$pollId = $status['poll']['id'];

		$response = $this->votePoll($app, $pollId, [0, 2], userId: 'bob');
		self::assertSame(200, $response->status);
		$poll = $response->decodedBody();
		self::assertTrue($poll['voted']);
		self::assertSame([0, 2], $poll['own_votes']);
		self::assertSame(2, $poll['votes_count']);
		self::assertSame(1, $poll['voters_count']);
		self::assertSame([
			['title' => 'A', 'votes_count' => 1],
			['title' => 'B', 'votes_count' => 0],
			['title' => 'C', 'votes_count' => 1],
		], $poll['options']);

		$viewer = $this->showPoll($app, $pollId, userId: 'bob');
		self::assertSame($poll, $viewer->decodedBody());

		$owner = $this->showPoll($app, $pollId, userId: 'alice');
		self::assertFalse($owner->decodedBody()['voted']);
		self::assertSame([], $owner->decodedBody()['own_votes']);
		self::assertSame(2, $owner->decodedBody()['votes_count']);

		$anonymous = $this->showPoll($app, $pollId);
		self::assertFalse($anonymous->decodedBody()['voted']);
		self::assertSame(2, $anonymous->decodedBody()['votes_count']);

		$statusRead = $app->handle(new Request(
			method: 'GET',
			path: MastodonApiConfig::DEFAULT_PREFIX . '/statuses/' . $status['id'],
			userId: 'bob',
		));
		self::assertSame($poll, $statusRead->decodedBody()['poll']);
	}

	public function testAnIdenticalPollVoteIsIdempotentButADifferentSecondVoteIsRejected(): void
	{
		$app = $this->app(actors: ['alice', 'bob']);
		$status = $this->createStatus($app, [
			'status' => 'One choice',
			'poll' => [
				'options' => ['Yes', 'No'],
				'expires_in' => '3600',
			],
		])->decodedBody();
		$pollId = $status['poll']['id'];

		$first = $this->votePoll($app, $pollId, [1], userId: 'bob');
		$second = $this->votePoll($app, $pollId, ['1'], userId: 'bob');
		self::assertSame(200, $first->status);
		self::assertSame(200, $second->status);
		self::assertSame(1, $second->decodedBody()['votes_count']);
		self::assertSame(1, $second->decodedBody()['voters_count']);
		self::assertSame([1], $second->decodedBody()['own_votes']);

		$different = $this->votePoll($app, $pollId, [0], userId: 'bob');
		self::assertSame(422, $different->status);
		self::assertSame('already_voted', $different->decodedBody()['error']['code']);
		self::assertSame(1, $this->showPoll($app, $pollId)->decodedBody()['votes_count']);
	}

	public function testPollVoteValidatesChoiceShapeAndTheSingleChoiceRule(): void
	{
		$app = $this->app();
		$status = $this->createStatus($app, [
			'status' => 'One choice',
			'poll' => [
				'options' => ['Yes', 'No'],
				'expires_in' => '3600',
			],
		])->decodedBody();
		$pollId = $status['poll']['id'];

		foreach ([[], [0, 1], [2], ['not-a-number'], [0, 0]] as $choices) {
			$response = $this->votePoll($app, $pollId, $choices, userId: 'alice');
			self::assertSame(422, $response->status);
			self::assertSame('validation_failed', $response->decodedBody()['error']['code']);
		}

		self::assertSame(0, $this->showPoll($app, $pollId)->decodedBody()['votes_count']);
	}

	public function testPollVoteAcceptsJsonAndRequiresAnActiveUnexpiredPoll(): void
	{
		$app = $this->app(actors: ['alice', 'bob']);
		$status = $this->createStatus($app, [
			'status' => 'Expires',
			'poll' => [
				'options' => ['Yes', 'No'],
				'expires_in' => '300',
				'multiple' => 'true',
			],
		])->decodedBody();
		$pollId = $status['poll']['id'];

		$response = $app->handle(new Request(
			method: 'POST',
			path: MastodonApiConfig::DEFAULT_PREFIX . '/polls/' . rawurlencode($pollId) . '/votes',
			headers: ['Content-Type' => 'application/json'],
			body: json_encode(['choices' => [0]], JSON_THROW_ON_ERROR),
			userId: 'bob',
			scopes: ['write:statuses'],
		));
		self::assertSame(200, $response->status);
		self::assertSame([0], $response->decodedBody()['own_votes']);

		$this->clock->advance(300);
		$ended = $this->votePoll($app, $pollId, [1], userId: 'alice');
		self::assertSame(422, $ended->status);
		self::assertSame('poll_ended', $ended->decodedBody()['error']['code']);
		self::assertSame(1, $this->showPoll($app, $pollId)->decodedBody()['votes_count']);
	}

	public function testPollVoteAndReadRespectVisibilityAndMissingPollIds(): void
	{
		$app = $this->app(actors: ['alice', 'bob']);
		$status = $this->createStatus($app, [
			'status' => 'Private poll',
			'visibility' => 'private',
			'poll' => [
				'options' => ['Yes', 'No'],
				'expires_in' => '3600',
			],
		])->decodedBody();
		$pollId = $status['poll']['id'];

		self::assertSame(404, $this->votePoll($app, $pollId, [0], userId: 'bob')->status);
		self::assertSame(404, $this->showPoll($app, $pollId)->status);
		self::assertSame(200, $this->showPoll($app, $pollId, userId: 'alice')->status);

		self::assertSame(404, $this->showPoll($app, 'poll-missing')->status);
		self::assertSame(404, $this->votePoll($app, 'poll-missing', [0])->status);
		self::assertSame(404, $this->showPoll($app, 'not-a-poll')->status);
	}

	public function testPollVoteRequiresAuthTheWriteScopeAndTheFeatureFlag(): void
	{
		$app = $this->app();
		$status = $this->createStatus($app, [
			'status' => 'Protected poll',
			'poll' => [
				'options' => ['Yes', 'No'],
				'expires_in' => '3600',
			],
		])->decodedBody();
		$pollId = $status['poll']['id'];

		self::assertSame(401, $this->votePoll($app, $pollId, [0], userId: null)->status);
		self::assertSame(403, $this->votePoll($app, $pollId, [0], scopes: ['read:statuses'])->status);
		self::assertSame(200, $this->showPoll($app, $pollId)->status);

		$disabled = $this->app(enabled: false);
		self::assertSame(404, $this->showPoll($disabled, $pollId)->status);
		self::assertSame(404, $this->votePoll($disabled, $pollId, [0])->status);
		self::assertSame(404, $this->votePoll($disabled, $pollId, [0], userId: null)->status);
	}

	/**
	 * @param array<string, string> $query
	 * @param list<string> $scopes
	 */
	private function search(
		Application $app,
		array $query,
		array $scopes = ['read:search'],
		?string $userId = 'alice',
		string $prefix = MastodonApiConfig::DEFAULT_PREFIX,
	): Response {
		return $app->handle(new Request(
			method: 'GET',
			path: $prefix . '/search',
			query: $query,
			userId: $userId,
			scopes: $scopes,
		));
	}

	public function testReblogAndUnreblogAreIdempotentAndProjected(): void
	{
		$boosts = new InMemoryBoostRepository();
		$app = $this->app(actors: ['alice', 'bob'], boosts: $boosts);
		$status = $this->createStatus($app, ['status' => 'boost me'])->decodedBody();

		$first = $app->handle(new Request(
			'POST',
			MastodonApiConfig::DEFAULT_PREFIX . '/statuses/' . $status['id'] . '/reblog',
			userId: 'bob',
			scopes: ['write:statuses'],
		));
		self::assertSame(200, $first->status);
		$boost = $first->decodedBody();
		self::assertSame('bob', $boost['account']['id']);
		self::assertSame($status['id'], $boost['reblog']['id']);
		self::assertSame(0, $boost['reblogs_count']);
		self::assertSame(1, $boosts->countForPost($status['id']));

		$again = $app->handle(new Request(
			'POST',
			MastodonApiConfig::DEFAULT_PREFIX . '/statuses/' . $status['id'] . '/reblog',
			userId: 'bob',
			scopes: ['write:statuses'],
		));
		self::assertSame($boost['id'], $again->decodedBody()['id']);
		self::assertSame(1, $boosts->countForPost($status['id']));

		$original = $app->handle(new Request(
			'GET',
			MastodonApiConfig::DEFAULT_PREFIX . '/statuses/' . $status['id'],
			userId: 'bob',
		));
		self::assertTrue($original->decodedBody()['reblogged']);
		self::assertSame(1, $original->decodedBody()['reblogs_count']);

		$byBoostId = $app->handle(new Request(
			'GET',
			MastodonApiConfig::DEFAULT_PREFIX . '/statuses/' . rawurlencode($boost['id']),
		));
		self::assertSame(200, $byBoostId->status);
		self::assertSame($status['id'], $byBoostId->decodedBody()['reblog']['id']);

		$notification = $this->notifications($app, ['types' => ['reblog']]);
		self::assertSame(200, $notification->status);
		self::assertSame('reblog', $notification->decodedBody()[0]['type']);
		self::assertSame('bob', $notification->decodedBody()[0]['account']['id']);

		$unreblog = $app->handle(new Request(
			'POST',
			MastodonApiConfig::DEFAULT_PREFIX . '/statuses/' . $status['id'] . '/unreblog',
			userId: 'bob',
			scopes: ['write:statuses'],
		));
		self::assertSame(200, $unreblog->status);
		self::assertFalse($unreblog->decodedBody()['reblogged']);
		self::assertSame(0, $unreblog->decodedBody()['reblogs_count']);
		self::assertSame(0, $boosts->countForPost($status['id']));
	}

	public function testStatusContextProjectsAncestorsRepliesAndCounts(): void
	{
		$app = $this->app(actors: ['alice', 'bob', 'carol']);
		$parent = $this->createStatus($app, ['status' => 'parent'])->decodedBody();
		$reply = $this->createStatus($app, [
			'status' => 'reply',
			'in_reply_to_id' => $parent['id'],
		], userId: 'bob')->decodedBody();
		$child = $this->createStatus($app, [
			'status' => 'child',
			'in_reply_to_id' => $reply['id'],
		], userId: 'carol')->decodedBody();

		$context = $app->handle(new Request(
			'GET',
			MastodonApiConfig::DEFAULT_PREFIX . '/statuses/' . $parent['id'] . '/context',
		));
		self::assertSame(200, $context->status);
		self::assertSame([], $context->decodedBody()['ancestors']);
		self::assertSame([$reply['id'], $child['id']], array_column($context->decodedBody()['descendants'], 'id'));

		$childContext = $app->handle(new Request(
			'GET',
			MastodonApiConfig::DEFAULT_PREFIX . '/statuses/' . $child['id'] . '/context',
		));
		self::assertSame([$parent['id'], $reply['id']], array_column($childContext->decodedBody()['ancestors'], 'id'));
		self::assertSame([], $childContext->decodedBody()['descendants']);

		$parentView = $app->handle(new Request(
			'GET',
			MastodonApiConfig::DEFAULT_PREFIX . '/statuses/' . $parent['id'],
		));
		self::assertSame(1, $parentView->decodedBody()['replies_count']);

		$notification = $this->notifications($app, ['types' => ['mention']]);
		self::assertSame('mention', $notification->decodedBody()[0]['type']);
		self::assertSame($reply['id'], $notification->decodedBody()[0]['status']['id']);
	}

	private function app(
		bool $enabled = true,
		string $prefix = MastodonApiConfig::DEFAULT_PREFIX,
		?InMemoryAttachmentRepository $attachments = null,
		?InMemoryBlobStore $blobs = null,
		?FavouriteRepository $favourites = null,
		?BoostRepository $boosts = null,
		?NotificationRepository $notificationRepository = null,
		?array $actors = ['alice'],
		?string $instanceUrl = 'https://social.example',
		array $instanceConfig = [],
		?FollowingRepository $followingRepository = null,
	): Application {
		$config = array_merge([
			'instance.name' => 'Aloha Social',
			'mastodon.api' => $enabled ? 'true' : 'false',
			'mastodon.prefix' => $prefix,
		], $instanceConfig);
		if ($instanceUrl !== null) {
			$config['instance.url'] = $instanceUrl;
		}

		return Application::inMemory(
			clock: $this->clock,
			config: new ArrayConfig([InstanceInfo::CONFIG_APP => $config]),
			attachmentRepository: $attachments,
			favouriteRepository: $favourites,
			boostRepository: $boosts,
			notificationRepository: $notificationRepository,
			blobStore: $blobs,
			localActors: $actors === null ? null : new InMemoryActorDirectory($actors),
			followingRepository: $followingRepository,
		);
	}

	private function followAccount(
		Application $app,
		string $targetId,
		string $action = 'follow',
		array $scopes = ['write:follows'],
		?string $userId = 'alice',
		string $prefix = MastodonApiConfig::DEFAULT_PREFIX,
	): Response {
		return $app->handle(new Request(
			method: 'POST',
			path: $prefix . '/accounts/' . rawurlencode($targetId) . '/' . $action,
			userId: $userId,
			scopes: $scopes,
		));
	}

	/**
	 * @param string|list<string> $ids
	 */
	private function relationships(
		Application $app,
		string|array $ids,
		array $scopes = ['read:follows'],
		?string $userId = 'alice',
		string $prefix = MastodonApiConfig::DEFAULT_PREFIX,
	): Response {
		return $app->handle(new Request(
			method: 'GET',
			path: $prefix . '/accounts/relationships',
			query: ['id' => $ids],
			userId: $userId,
			scopes: $scopes,
		));
	}

	private function updateMedia(
		Application $app,
		string $mediaId,
		array $fields,
		array $scopes = ['write:media'],
		?string $userId = 'alice',
		string $prefix = MastodonApiConfig::DEFAULT_PREFIX,
	): Response {
		return $app->handle(new Request(
			method: 'PUT',
			path: $prefix . '/media/' . rawurlencode($mediaId),
			headers: ['Content-Type' => 'application/json'],
			body: json_encode($fields, JSON_THROW_ON_ERROR),
			userId: $userId,
			scopes: $scopes,
		));
	}

	private function deleteMedia(
		Application $app,
		string $mediaId,
		array $scopes = ['write:media'],
		?string $userId = 'alice',
		string $prefix = MastodonApiConfig::DEFAULT_PREFIX,
	): Response {
		return $app->handle(new Request(
			method: 'DELETE',
			path: $prefix . '/media/' . rawurlencode($mediaId),
			userId: $userId,
			scopes: $scopes,
		));
	}

	/**
	 * @param array<string, mixed> $query
	 * @param list<string>         $scopes
	 * @param string               $prefix compatibility prefix to target
	 */
	private function notifications(
		Application $app,
		array $query = [],
		array $scopes = ['read:notifications'],
		?string $userId = 'alice',
		string $prefix = MastodonApiConfig::DEFAULT_PREFIX,
	): Response {
		return $app->handle(new Request(
			method: 'GET',
			path: $prefix . '/notifications',
			query: $query,
			userId: $userId,
			scopes: $scopes,
		));
	}

	/**
	 * @param array<string, mixed> $query
	 * @param list<string>         $scopes
	 */
	private function unreadCount(
		Application $app,
		array $query = [],
		array $scopes = ['read:notifications'],
		?string $userId = 'alice',
	): Response {
		return $app->handle(new Request(
			method: 'GET',
			path: MastodonApiConfig::DEFAULT_PREFIX . '/notifications/unread_count',
			query: $query,
			userId: $userId,
			scopes: $scopes,
		));
	}

	/**
	 * @param list<string> $scopes
	 */
	private function dismissNotification(
		Application $app,
		string $id,
		array $scopes = ['write:notifications'],
		?string $userId = 'alice',
	): Response {
		return $app->handle(new Request(
			method: 'POST',
			path: MastodonApiConfig::DEFAULT_PREFIX . '/notifications/' . rawurlencode($id) . '/dismiss',
			userId: $userId,
			scopes: $scopes,
		));
	}

	/**
	 * @param list<string> $scopes
	 */
	private function clearNotifications(
		Application $app,
		array $scopes = ['write:notifications'],
		?string $userId = 'alice',
	): Response {
		return $app->handle(new Request(
			method: 'POST',
			path: MastodonApiConfig::DEFAULT_PREFIX . '/notifications/clear',
			userId: $userId,
			scopes: $scopes,
		));
	}

	/**
	 * @param array<string, string> $headers
	 * @param list<string>          $scopes
	 * @param string                $prefix  compatibility prefix to target
	 */
	private function favourite(
		Application $app,
		string $postId,
		string $action = 'favourite',
		array $scopes = ['write:favourites'],
		?string $userId = 'alice',
		string $prefix = MastodonApiConfig::DEFAULT_PREFIX,
	): Response {
		return $app->handle(new Request(
			method: 'POST',
			path: $prefix . '/statuses/' . rawurlencode($postId) . '/' . $action,
			userId: $userId,
			scopes: $scopes,
		));
	}

	/**
	 * @param array<string, string> $headers
	 * @param list<string>          $scopes
	 * @param string                $prefix  compatibility prefix to target
	 */
	private function upload(
		Application $app,
		?string $bytes,
		?string $description = null,
		array $headers = [],
		array $scopes = ['write:media'],
		?string $userId = 'alice',
		string $prefix = MastodonApiConfig::DEFAULT_PREFIX,
	): Response {
		$boundary = '----AlohaSocialMastodon' . bin2hex(random_bytes(8));
		$body = '';

		if ($description !== null) {
			$body .= '--' . $boundary . "\r\n"
				. "Content-Disposition: form-data; name=\"description\"\r\n\r\n"
				. $description . "\r\n";
		}

		if ($bytes !== null) {
			$body .= '--' . $boundary . "\r\n"
				. "Content-Disposition: form-data; name=\"file\"; filename=\"upload.png\"\r\n"
				. "Content-Type: image/png\r\n\r\n"
				. $bytes . "\r\n";
		}

		$body .= '--' . $boundary . "--\r\n";

		return $app->handle(new Request(
			method: 'POST',
			path: $prefix . '/media',
			headers: array_merge(['Content-Type' => 'multipart/form-data; boundary=' . $boundary], $headers),
			body: $body,
			userId: $userId,
			scopes: $scopes,
		));
	}

	/**
	 * @param array<string, mixed>  $fields
	 * @param array<string, string> $headers
	 */
	private function png(): string
	{
		$image = imagecreatetruecolor(32, 32);
		self::assertNotFalse($image);
		$color = imagecolorallocate($image, 20, 120, 200);
		imagefill($image, 0, 0, $color);

		ob_start();
		imagepng($image);
		$bytes = ob_get_clean();
		imagedestroy($image);

		self::assertIsString($bytes);

		return $bytes;
	}

	/**
	 * @param list<int|string> $choices
	 * @param list<string> $scopes
	 */
	private function votePoll(
		Application $app,
		string $pollId,
		array $choices,
		array $scopes = ['write:statuses'],
		?string $userId = 'alice',
		string $prefix = MastodonApiConfig::DEFAULT_PREFIX,
	): Response {
		return $app->handle(new Request(
			method: 'POST',
			path: $prefix . '/polls/' . rawurlencode($pollId) . '/votes',
			headers: ['Content-Type' => 'application/x-www-form-urlencoded'],
			body: http_build_query(['choices' => $choices]),
			userId: $userId,
			scopes: $scopes,
		));
	}

	private function showPoll(
		Application $app,
		string $pollId,
		array $scopes = [],
		?string $userId = null,
		string $prefix = MastodonApiConfig::DEFAULT_PREFIX,
	): Response {
		return $app->handle(new Request(
			method: 'GET',
			path: $prefix . '/polls/' . rawurlencode($pollId),
			userId: $userId,
			scopes: $scopes,
		));
	}

	private function createStatus(
		Application $app,
		array $fields,
		array $headers = [],
		array $scopes = ['write:statuses'],
		?string $userId = 'alice',
	): Response {
		return $app->handle(new Request(
			method: 'POST',
			path: MastodonApiConfig::DEFAULT_PREFIX . '/statuses',
			headers: array_merge(['Content-Type' => 'application/x-www-form-urlencoded'], $headers),
			body: http_build_query($fields),
			userId: $userId,
			scopes: $scopes,
		));
	}
}
