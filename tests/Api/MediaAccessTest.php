<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Api;

use AlohaSocial\Core\Application;
use AlohaSocial\Core\Domain\Federation\InMemoryActorDirectory;
use AlohaSocial\Core\Domain\Media\InMemoryAttachmentRepository;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Platform\Storage\LocalBlobStore;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

use function imagecolorallocate;
use function imagecreatetruecolor;
use function imagedestroy;
use function imagefill;
use function imagepng;
use function ob_get_clean;
use function ob_start;
use function sys_get_temp_dir;

/**
 * The media route behind a post's audience.
 *
 * An unguessable id is not a permission: this is the leak that existed before
 * the rule — a followers-only or direct image was served to anybody who knew
 * its URL. The matrix asserts the post visibility and the relationship decide,
 * that refusals look exactly like an unknown id, and that public media keeps
 * its anonymous, cacheable behaviour.
 */
final class MediaAccessTest extends TestCase
{
	private string $directory;

	private Application $app;

	private string $lastPostId = '';

	protected function setUp(): void
	{
		$this->directory = sys_get_temp_dir() . '/alohasocial-media-access-' . bin2hex(random_bytes(8));
		mkdir($this->directory, 0o770, true);
		$this->app = Application::inMemory(
			clock: new FrozenClock(Time::parse('2026-11-11T10:00:00Z')),
			config: new ArrayConfig(['social' => ['instance.url' => 'https://social.example']]),
			localActors: new InMemoryActorDirectory(['alice', 'bob', 'carol']),
			attachmentRepository: new InMemoryAttachmentRepository(),
			blobStore: new LocalBlobStore($this->directory),
		);
	}

	protected function tearDown(): void
	{
		$this->remove($this->directory);
	}

	public function testPublicMediaStaysAnonymousAndCacheable(): void
	{
		$id = $this->attachmentOn('public');

		$response = $this->media($id);
		self::assertSame(200, $response->status);
		self::assertSame('image/png', $response->headers['Content-Type']);
		self::assertStringContainsString('public', $response->headers['Cache-Control']);

		self::assertSame(200, $this->media($id, 'thumbnail')->status);
	}

	public function testUnlistedMediaStaysReadableByLink(): void
	{
		$id = $this->attachmentOn('unlisted');

		self::assertSame(200, $this->media($id)->status);
		self::assertSame(200, $this->media($id, 'thumbnail')->status);
	}

	public function testAnAnonymousViewerCannotReadFollowersOnlyMedia(): void
	{
		$id = $this->attachmentOn('followers');

		$response = $this->media($id);

		self::assertSame(404, $response->status);
		self::assertSame('not_found', $response->decodedBody()['error']['code']);
		self::assertSame(404, $this->media($id, 'thumbnail')->status);
	}

	public function testAStrangerCannotReadFollowersOnlyMedia(): void
	{
		$id = $this->attachmentOn('followers');

		self::assertSame(404, $this->media($id, 'carol')->status);
		self::assertSame(404, $this->media($id, 'carol', 'thumbnail')->status);
	}

	public function testTheAuthorReadsFollowersOnlyMedia(): void
	{
		$id = $this->attachmentOn('followers');

		$response = $this->media($id, 'alice');

		self::assertSame(200, $response->status);
		self::assertSame('private, no-store', $response->headers['Cache-Control']);
		self::assertSame(200, $this->media($id, 'alice', 'thumbnail')->status);
	}

	public function testAnAcceptedLocalFollowerReadsFollowersOnlyMedia(): void
	{
		$id = $this->attachmentOn('followers');
		$this->follow('bob', 'alice');

		$response = $this->media($id, 'bob');

		self::assertSame(200, $response->status);
		self::assertSame('private, no-store', $response->headers['Cache-Control']);
	}

	public function testLosingTheFollowRevokesTheMediaRead(): void
	{
		$id = $this->attachmentOn('followers');
		$this->follow('bob', 'alice');
		self::assertSame(200, $this->media($id, 'bob')->status);

		$this->app->handle(new Request(
			method: 'DELETE',
			path: '/api/v1/accounts/alice/follow',
			userId: 'bob',
		));

		self::assertSame(404, $this->media($id, 'bob')->status);
	}

	public function testADirectRecipientReadsTheMediaButNobodyElseDoes(): void
	{
		$id = $this->attachmentOn('direct', ['bob']);

		self::assertSame(200, $this->media($id, 'bob')->status);
		self::assertSame(200, $this->media($id, 'alice')->status);
		self::assertSame(404, $this->media($id, 'carol')->status);
		self::assertSame(404, $this->media($id)->status);
	}

	public function testDeletingThePostRevokesTheMediaForEverybodyButTheAuthor(): void
	{
		$id = $this->attachmentOn('public');
		$this->follow('bob', 'alice');

		$this->app->handle(new Request('DELETE', '/api/v1/posts/' . $this->lastPostId, userId: 'alice'));

		self::assertSame(200, $this->media($id, 'alice')->status);
		self::assertSame(404, $this->media($id)->status);
		self::assertSame(404, $this->media($id, 'bob')->status);
	}

	public function testAnUploadWithoutAPostStaysReadable(): void
	{
		$attachment = $this->upload()['attachment'];

		self::assertSame(200, $this->media($attachment['id'])->status);
	}

	public function testAnUnknownMediaIdIs404ForEveryViewer(): void
	{
		self::assertSame(404, $this->media('missing-id')->status);
		self::assertSame(404, $this->media('missing-id', 'alice')->status);
	}

	public function testReusingAnAttachmentAcrossAPublicPostPublishesIt(): void
	{
		$id = $this->attachmentOn('followers');
		self::assertSame(404, $this->media($id)->status);

		$public = $this->publish($id, 'public', []);
		self::assertSame(201, $public->status);

		self::assertSame(200, $this->media($id)->status);
	}

	/**
	 * Uploads an image as alice and attaches it to a post with the given
	 * visibility, returning the attachment id.
	 *
	 * @param list<string> $recipientIds
	 */
	private function attachmentOn(string $visibility, array $recipientIds = []): string
	{
		$attachment = $this->upload()['attachment'];
		$response = $this->publish($attachment['id'], $visibility, $recipientIds);
		self::assertSame(201, $response->status, (string) json_encode($response->decodedBody()));

		return $attachment['id'];
	}

	/**
	 * @param list<string> $recipientIds
	 */
	private function publish(string $attachmentId, string $visibility, array $recipientIds): Response
	{
		$body = [
			'content' => 'with media',
			'visibility' => $visibility,
			'attachment_ids' => [$attachmentId],
		];
		if ($recipientIds !== []) {
			$body['recipient_ids'] = $recipientIds;
		}

		$response = $this->app->handle(new Request(
			method: 'POST',
			path: '/api/v1/posts',
			headers: ['Content-Type' => 'application/json'],
			body: json_encode($body, JSON_THROW_ON_ERROR),
			userId: 'alice',
		));

		if ($response->status === 201) {
			$this->lastPostId = (string) $response->decodedBody()['post']['id'];
		}

		return $response;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function upload(): array
	{
		$boundary = '----AlohaSocial' . bin2hex(random_bytes(8));
		$body = '--' . $boundary . "\r\n"
			. "Content-Disposition: form-data; name=\"file\"; filename=\"upload.png\"\r\n"
			. "Content-Type: image/png\r\n\r\n"
			. $this->png() . "\r\n"
			. '--' . $boundary . "--\r\n";

		$response = $this->app->handle(new Request(
			method: 'POST',
			path: '/api/v1/media',
			headers: ['Content-Type' => 'multipart/form-data; boundary=' . $boundary],
			body: $body,
			userId: 'alice',
		));

		self::assertSame(201, $response->status, (string) json_encode($response->decodedBody()));

		return $response->decodedBody();
	}

	private function media(string $id, ?string $viewer = null, ?string $variant = null): Response
	{
		$query = $variant === null ? [] : ['variant' => $variant];

		return $this->app->handle(new Request(
			method: 'GET',
			path: '/api/v1/media/' . $id,
			query: $query,
			userId: $viewer,
		));
	}

	private function follow(string $followerId, string $targetId): void
	{
		$response = $this->app->handle(new Request(
			method: 'POST',
			path: '/api/v1/accounts/' . $targetId . '/follow',
			userId: $followerId,
		));

		self::assertSame(200, $response->status, (string) json_encode($response->decodedBody()));
	}

	private function png(): string
	{
		$image = imagecreatetruecolor(40, 30);
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
