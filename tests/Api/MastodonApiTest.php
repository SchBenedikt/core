<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Api;

use AlohaSocial\Core\Application;
use AlohaSocial\Core\Domain\Federation\InMemoryActorDirectory;
use AlohaSocial\Core\Domain\Media\Attachment;
use AlohaSocial\Core\Domain\Media\InMemoryAttachmentRepository;
use AlohaSocial\Core\Domain\Post\FavouriteRepository;
use AlohaSocial\Core\Domain\Post\InMemoryFavouriteRepository;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Platform\Storage\InMemoryBlobStore;
use AlohaSocial\Core\Service\InstanceInfo;
use AlohaSocial\Core\Service\Mastodon\MastodonApiConfig;
use AlohaSocial\Core\Support\Time;
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

	public function testDirectVisibilityAndMissingInputAreRejected(): void
	{
		$app = $this->app();

		$direct = $this->createStatus($app, ['status' => 'private', 'visibility' => 'direct']);
		self::assertSame(422, $direct->status);
		self::assertSame('unsupported_parameter', $direct->decodedBody()['error']['code']);

		$missing = $this->createStatus($app, []);
		self::assertSame(422, $missing->status);
		self::assertSame('validation_failed', $missing->decodedBody()['error']['code']);
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

	private function app(
		bool $enabled = true,
		string $prefix = MastodonApiConfig::DEFAULT_PREFIX,
		?InMemoryAttachmentRepository $attachments = null,
		?InMemoryBlobStore $blobs = null,
		?FavouriteRepository $favourites = null,
		array $actors = ['alice'],
	): Application {
		return Application::inMemory(
			clock: $this->clock,
			config: new ArrayConfig([
				InstanceInfo::CONFIG_APP => [
					'instance.url' => 'https://social.example',
					'instance.name' => 'Aloha Social',
					'mastodon.api' => $enabled ? 'true' : 'false',
					'mastodon.prefix' => $prefix,
				],
			]),
			attachmentRepository: $attachments,
			favouriteRepository: $favourites,
			blobStore: $blobs,
			localActors: new InMemoryActorDirectory($actors),
		);
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

	private function createStatus(
		Application $app,
		array $fields,
		array $headers = [],
		array $scopes = ['write:statuses'],
	): Response {
		return $app->handle(new Request(
			method: 'POST',
			path: MastodonApiConfig::DEFAULT_PREFIX . '/statuses',
			headers: array_merge(['Content-Type' => 'application/x-www-form-urlencoded'], $headers),
			body: http_build_query($fields),
			userId: 'alice',
			scopes: $scopes,
		));
	}
}
