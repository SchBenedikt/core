<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Api;

use AlohaSocial\Core\Application;
use AlohaSocial\Core\Domain\Media\Attachment;
use AlohaSocial\Core\Domain\Media\InMemoryAttachmentRepository;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Platform\Storage\LocalBlobStore;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

use function getimagesizefromstring;
use function imagecolorallocate;
use function imagecreatetruecolor;
use function imagefill;
use function imagepng;
use function imagedestroy;
use function ob_get_clean;
use function ob_start;
use function sys_get_temp_dir;

final class MediaTest extends TestCase
{
	private string $directory;

	private LocalBlobStore $blobs;

	private InMemoryAttachmentRepository $attachments;

	private Application $app;

	protected function setUp(): void
	{
		$this->directory = sys_get_temp_dir() . '/alohasocial-media-' . bin2hex(random_bytes(8));
		mkdir($this->directory, 0o770, true);
		$this->blobs = new LocalBlobStore($this->directory);
		$this->attachments = new InMemoryAttachmentRepository();
		$this->app = Application::inMemory(
			clock: new FrozenClock(Time::parse('2026-11-11T10:00:00Z')),
			config: new ArrayConfig([
				'social' => ['instance.url' => 'https://social.example'],
			]),
			attachmentRepository: $this->attachments,
			blobStore: $this->blobs,
		);
	}

	protected function tearDown(): void
	{
		$this->remove($this->directory);
	}

	public function testUploadStoresOriginalThumbnailAndMetadata(): void
	{
		$image = $this->png(1200, 800);
		$response = $this->upload($image, 'a wide test image');

		self::assertSame(201, $response->status);
		$attachment = $response->decodedBody()['attachment'];

		self::assertSame('image', $attachment['media_type']);
		self::assertSame('image/png', $attachment['mime_type']);
		self::assertSame(1200, $attachment['width']);
		self::assertSame(800, $attachment['height']);
		self::assertSame('a wide test image', $attachment['alt_text']);
		self::assertStringStartsWith('https://social.example/api/v1/media/', $attachment['url']);
		self::assertStringEndsWith('?variant=thumbnail', $attachment['thumbnail_url']);

		$stored = $this->attachments->find($attachment['id']);
		self::assertNotNull($stored);
		self::assertSame([
			'media/' . $attachment['id'] . '/original.png',
			'media/' . $attachment['id'] . '/thumbnail.png',
		], $this->blobs->list('media/' . $attachment['id']));

		$original = $this->app->handle(new Request('GET', $attachment['url']));
		self::assertSame(200, $original->status);
		self::assertSame('image/png', $original->header('Content-Type'));
		self::assertSame($image, $original->body);
		self::assertSame('nosniff', $original->header('X-Content-Type-Options'));

		self::assertSame(1, $this->attachments->count());

		$thumbnail = $this->app->handle(new Request(
			method: 'GET',
			path: '/api/v1/media/' . $attachment['id'],
			query: ['variant' => 'thumbnail'],
		));
		self::assertSame(200, $thumbnail->status);
		$thumbnailInfo = getimagesizefromstring($thumbnail->body);
		self::assertIsArray($thumbnailInfo);
		self::assertLessThanOrEqual(640, $thumbnailInfo[0]);
		self::assertLessThanOrEqual(640, $thumbnailInfo[1]);
		self::assertNotSame($image, $thumbnail->body, 'The original must not be overwritten by its thumbnail.');
	}

	public function testPostAndTimelinesExposeTheAttachmentWithAltTextAndUrl(): void
	{
		$uploaded = $this->upload($this->png(900, 600), 'alt on the post')->decodedBody()['attachment'];

		$post = $this->app->handle(new Request(
			method: 'POST',
			path: '/api/v1/posts',
			headers: ['Content-Type' => 'application/json'],
			body: json_encode([
				'content' => 'with a picture',
				'attachment_ids' => [$uploaded['id']],
			], JSON_THROW_ON_ERROR),
			userId: 'alice',
		));

		self::assertSame(201, $post->status);
		$shape = $post->decodedBody()['post']['attachments'][0];
		self::assertSame($uploaded['id'], $shape['id']);
		self::assertSame('alt on the post', $shape['alt_text']);
		self::assertSame($uploaded['url'], $shape['url']);

		$single = $this->app->handle(new Request('GET', '/api/v1/posts/' . $post->decodedBody()['post']['id']));
		self::assertSame($shape, $single->decodedBody()['post']['attachments'][0]);

		$public = $this->app->handle(new Request('GET', '/api/v1/timelines/public'));
		self::assertSame($shape, $public->decodedBody()['posts'][0]['attachments'][0]);

		$home = $this->app->handle(new Request('GET', '/api/v1/timelines/home', userId: 'alice'));
		self::assertSame($shape, $home->decodedBody()['posts'][0]['attachments'][0]);

		$plain = $this->app->handle(new Request(
			method: 'POST',
			path: '/api/v1/posts',
			headers: ['Content-Type' => 'application/json'],
			body: json_encode(['content' => 'no picture'], JSON_THROW_ON_ERROR),
			userId: 'alice',
		));
		self::assertArrayNotHasKey('attachments', $plain->decodedBody()['post']);
	}

	public function testAnUnknownVariantIsRejectedWithoutServingBytes(): void
	{
		$uploaded = $this->upload($this->png(100, 100))->decodedBody()['attachment'];

		$response = $this->app->handle(new Request(
			method: 'GET',
			path: '/api/v1/media/' . $uploaded['id'],
			query: ['variant' => 'large'],
		));

		self::assertSame(400, $response->status);
		self::assertSame('invalid_media_variant', $response->decodedBody()['error']['code']);
	}

	public function testWrongTypeIsRejectedWithoutABlobOrPost(): void
	{
		$response = $this->upload('not an image');

		self::assertSame(422, $response->status);
		self::assertSame('validation_failed', $response->decodedBody()['error']['code']);
		self::assertArrayHasKey('file', $response->decodedBody()['error']['fields']);
		self::assertSame([], $this->blobs->list('media'));
		self::assertSame(0, $this->attachments->count());
	}

	public function testCorruptImageIsRejectedWithoutABlob(): void
	{
		$response = $this->upload("\x89PNG\r\n\x1a\nnot-really-a-png");

		self::assertSame(422, $response->status);
		self::assertArrayHasKey('file', $response->decodedBody()['error']['fields']);
		self::assertSame([], $this->blobs->list('media'));
		self::assertSame(0, $this->attachments->count());
	}

	public function testAnOversizedUploadIsRejectedBeforeItIsParsed(): void
	{
		$response = $this->upload(str_repeat('a', Attachment::MAX_REQUEST_BYTES + 200_000));

		self::assertSame(413, $response->status);
		self::assertSame('payload_too_large', $response->decodedBody()['error']['code']);
		self::assertSame([], $this->blobs->list('media'));
		self::assertSame(0, $this->attachments->count());
	}

	public function testAnAttachmentOfAnotherAccountCannotBeAttached(): void
	{
		$uploaded = $this->upload($this->png(100, 100), 'private upload')->decodedBody()['attachment'];

		$response = $this->app->handle(new Request(
			method: 'POST',
			path: '/api/v1/posts',
			headers: ['Content-Type' => 'application/json'],
			body: json_encode([
				'content' => 'not mine',
				'attachment_ids' => [$uploaded['id']],
			], JSON_THROW_ON_ERROR),
			userId: 'bob',
		));

		self::assertSame(422, $response->status);
		self::assertArrayHasKey('attachment_ids', $response->decodedBody()['error']['fields']);
		self::assertSame(
			[],
			$this->app->handle(new Request('GET', '/api/v1/timelines/public'))->decodedBody()['posts'],
		);
	}

	public function testAFederatedNoteOfAPublicPostCarriesItsImage(): void
	{
		$uploaded = $this->upload($this->png(900, 600), 'a blue banner')->decodedBody()['attachment'];
		$post = $this->publishMediaPost($uploaded['id'], 'public');

		$media = $this->app->media;
		self::assertNotNull($media);

		self::assertSame([[
			'type' => 'Image',
			'url' => $uploaded['url'],
			'mediaType' => 'image/png',
			'width' => 900,
			'height' => 600,
			'name' => 'a blue banner',
		]], $media->presentActivityAttachments($this->app->posts->get($post->decodedBody()['post']['id'])));
	}

	public function testAFollowersOnlyPostDoesNotPublishAttachmentUrls(): void
	{
		$uploaded = $this->upload($this->png(200, 200))->decodedBody()['attachment'];
		$post = $this->publishMediaPost($uploaded['id'], 'followers');

		$media = $this->app->media;
		self::assertNotNull($media);

		$presented = $media->presentActivityAttachments($this->app->posts->get($post->decodedBody()['post']['id']));

		self::assertSame([], $presented);
	}

	private function publishMediaPost(string $attachmentId, string $visibility): Response
	{
		return $this->app->handle(new Request(
			method: 'POST',
			path: '/api/v1/posts',
			headers: ['Content-Type' => 'application/json'],
			body: json_encode([
				'content' => 'with media',
				'visibility' => $visibility,
				'attachment_ids' => [$attachmentId],
			], JSON_THROW_ON_ERROR),
			userId: 'alice',
		));
	}

	private function upload(string $bytes, ?string $altText = null): Response
	{
		$boundary = '----AlohaSocial' . bin2hex(random_bytes(8));
		$body = '';

		if ($altText !== null) {
			$body .= '--' . $boundary . "\r\n"
				. "Content-Disposition: form-data; name=\"alt_text\"\r\n\r\n"
				. $altText . "\r\n";
		}

		$body .= '--' . $boundary . "\r\n"
			. "Content-Disposition: form-data; name=\"file\"; filename=\"upload.png\"\r\n"
			. "Content-Type: image/png\r\n\r\n"
			. $bytes . "\r\n"
			. '--' . $boundary . "--\r\n";

		return $this->app->handle(new Request(
			method: 'POST',
			path: '/api/v1/media',
			headers: ['Content-Type' => 'multipart/form-data; boundary=' . $boundary],
			body: $body,
			userId: 'alice',
		));
	}

	private function png(int $width, int $height): string
	{
		$image = imagecreatetruecolor($width, $height);
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

	private function remove(string $path): void
	{
		if (!is_dir($path)) {
			return;
		}

		foreach (scandir($path) ?: [] as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}

			$full = $path . '/' . $entry;
			if (is_dir($full)) {
				$this->remove($full);
			} else {
				unlink($full);
			}
		}

		rmdir($path);
	}
}
