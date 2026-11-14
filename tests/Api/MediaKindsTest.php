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
use AlohaSocial\Core\Domain\Media\MediaMetadataStripper;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Platform\Media\MediaTranscoder;
use AlohaSocial\Core\Platform\Media\TranscodedMedia;
use AlohaSocial\Core\Platform\Storage\LocalBlobStore;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function getimagesizefromstring;
use function imagedestroy;
use function pack;
use function strlen;
use function sys_get_temp_dir;

/**
 * Covers the non-image media kinds: byte-sniffed MP4/WebM video and MP3/M4A/
 * Ogg/WAV audio, their ActivityPub and Mastodon shapes, per-kind limits,
 * host transcoding, metadata stripping, focal points and the attachment cap.
 */
final class MediaKindsTest extends TestCase
{
	private string $directory;

	private LocalBlobStore $blobs;

	private InMemoryAttachmentRepository $attachments;

	protected function setUp(): void
	{
		$this->directory = sys_get_temp_dir() . '/alohasocial-media-kinds-' . bin2hex(random_bytes(8));
		mkdir($this->directory, 0o770, true);
		$this->blobs = new LocalBlobStore($this->directory);
		$this->attachments = new InMemoryAttachmentRepository();
	}

	protected function tearDown(): void
	{
		$this->remove($this->directory);
	}

	public function testAnMp4SurvivesUploadReadFederationAndDeletion(): void
	{
		$app = $this->app();
		$video = $this->mp4(640, 360, 2);

		$uploaded = $this->upload($app, $video, 'video/mp4', 'clip.mp4');
		self::assertSame(201, $uploaded->status, $uploaded->body);
		$attachment = $uploaded->decodedBody()['attachment'];

		self::assertSame('video', $attachment['media_type']);
		self::assertSame('video/mp4', $attachment['mime_type']);
		self::assertSame(640, $attachment['width']);
		self::assertSame(360, $attachment['height']);
		self::assertSame(2, $attachment['duration_seconds']);
		self::assertNull($attachment['thumbnail_url']);

		self::assertSame(
			['media/' . $attachment['id'] . '/original.mp4'],
			$this->blobs->list('media/' . $attachment['id']),
		);

		$original = $app->handle(new Request('GET', $attachment['url']));
		self::assertSame(200, $original->status);
		self::assertSame('video/mp4', $original->header('Content-Type'));
		self::assertSame($video, $original->body);
		self::assertSame('nosniff', $original->header('X-Content-Type-Options'));

		$post = $app->handle(new Request(
			method: 'POST',
			path: '/api/v1/posts',
			headers: ['Content-Type' => 'application/json'],
			body: json_encode([
				'content' => 'with a video',
				'attachment_ids' => [$attachment['id']],
			], JSON_THROW_ON_ERROR),
			userId: 'alice',
		));
		self::assertSame(201, $post->status, $post->body);

		self::assertSame([[
			'type' => 'Video',
			'url' => $attachment['url'],
			'mediaType' => 'video/mp4',
			'width' => 640,
			'height' => 360,
			'duration' => 'PT2S',
		]], $app->media?->presentActivityAttachments($app->posts->get($post->decodedBody()['post']['id'])));

		$deletable = $this->upload($app, $this->mp4(320, 180, 1), 'video/mp4', 'clip.mp4')->decodedBody()['attachment'];
		$delete = $app->handle(new Request(
			method: 'DELETE',
			path: '/api/mastodon/v1/media/' . $deletable['id'],
			userId: 'alice',
			scopes: ['write:media'],
		));
		self::assertSame(200, $delete->status, $delete->body);
		self::assertSame([], $this->blobs->list('media/' . $deletable['id']));
		self::assertNull($this->attachments->find($deletable['id']));
	}

	public function testAnAudioFileIsServedAndFederatedAsAudio(): void
	{
		$app = $this->app();
		$audio = $this->wav(2);

		$uploaded = $this->upload($app, $audio, 'audio/wav', 'sound.wav');
		self::assertSame(201, $uploaded->status, $uploaded->body);
		$attachment = $uploaded->decodedBody()['attachment'];

		self::assertSame('audio', $attachment['media_type']);
		self::assertSame('audio/wav', $attachment['mime_type']);
		self::assertSame(2, $attachment['duration_seconds']);
		self::assertSame(0, $attachment['width']);
		self::assertNull($attachment['thumbnail_url']);

		$original = $app->handle(new Request('GET', $attachment['url']));
		self::assertSame('audio/wav', $original->header('Content-Type'));
		self::assertSame($audio, $original->body);

		$thumbnail = $app->handle(new Request(
			method: 'GET',
			path: '/api/v1/media/' . $attachment['id'],
			query: ['variant' => 'thumbnail'],
		));
		self::assertSame(200, $thumbnail->status);
		self::assertSame($audio, $thumbnail->body, 'Without a generated preview the original is served.');

		$post = $app->handle(new Request(
			method: 'POST',
			path: '/api/v1/posts',
			headers: ['Content-Type' => 'application/json'],
			body: json_encode([
				'content' => 'with audio',
				'attachment_ids' => [$attachment['id']],
			], JSON_THROW_ON_ERROR),
			userId: 'alice',
		));

		self::assertSame([[
			'type' => 'Audio',
			'url' => $attachment['url'],
			'mediaType' => 'audio/wav',
			'duration' => 'PT2S',
		]], $app->media?->presentActivityAttachments($app->posts->get($post->decodedBody()['post']['id'])));
	}

	public function testAStillGifStaysAnImageInsteadOfAGifv(): void
	{
		$uploaded = $this->upload($this->app(), $this->gif(), 'image/gif', 'still.gif');

		self::assertSame(201, $uploaded->status, $uploaded->body);
		$attachment = $uploaded->decodedBody()['attachment'];
		self::assertSame('image', $attachment['media_type']);
		self::assertFalse($attachment['animated']);
		self::assertSame('image/gif', $attachment['mime_type']);
	}

	public function testExecutableAndSvgBytesAreRejectedEvenWithAnInnocentContentType(): void
	{
		$app = $this->app();

		foreach ([
			['<?php echo "pwned"; ?>', 'image/png'],
			['<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'image/svg+xml'],
		] as [$bytes, $declared]) {
			$response = $this->upload($app, $bytes, $declared, 'upload.png');
			self::assertSame(422, $response->status);
			self::assertArrayHasKey('file', $response->decodedBody()['error']['fields']);
		}

		self::assertSame([], $this->blobs->list('media'));
		self::assertSame(0, $this->attachments->count());
	}

	public function testTheDeclaredTypeHasToMatchTheSniffedType(): void
	{
		$response = $this->upload($this->app(), $this->mp4(320, 180, 1), 'image/png', 'clip.png');

		self::assertSame(422, $response->status);
		self::assertArrayHasKey('file', $response->decodedBody()['error']['fields']);
		self::assertSame(0, $this->attachments->count());
	}

	public function testAPerKindLimitIsEnforced(): void
	{
		$app = $this->app(['social' => ['media.max_video_bytes' => '100']]);
		$response = $this->upload($app, $this->mp4(640, 360, 2), 'video/mp4', 'big.mp4');

		self::assertSame(422, $response->status);
		self::assertSame('validation_failed', $response->decodedBody()['error']['code']);
		self::assertSame([], $this->blobs->list('media'));
		self::assertSame(0, $this->attachments->count());
	}

	public function testAHostTranscoderFailureIsRefusedWithoutStoringBytes(): void
	{
		$transcoder = new class implements MediaTranscoder {
			public function transcode(string $bytes, string $mimeType): ?TranscodedMedia
			{
				throw new RuntimeException('ffmpeg failed');
			}
		};

		$app = $this->app(transcoder: $transcoder);
		$response = $this->upload($app, $this->mp4(640, 360, 2), 'video/mp4', 'clip.mp4');

		self::assertSame(422, $response->status);
		self::assertSame('validation_failed', $response->decodedBody()['error']['code']);
		self::assertSame([], $this->blobs->list('media'));
		self::assertSame(0, $this->attachments->count());
	}

	public function testFocusAndDescriptionAreUpdatedTogether(): void
	{
		$app = $this->app();
		$uploaded = $this->upload($app, $this->png(400, 300), 'image/png', 'photo.png')->decodedBody()['attachment'];

		$update = $app->handle(new Request(
			method: 'PUT',
			path: '/api/mastodon/v1/media/' . $uploaded['id'],
			headers: ['Content-Type' => 'application/x-www-form-urlencoded'],
			body: http_build_query(['description' => 'a description', 'focus' => '-0.5,0.25']),
			userId: 'alice',
			scopes: ['write:media'],
		));
		self::assertSame(200, $update->status, $update->body);
		self::assertSame('a description', $update->decodedBody()['description']);

		$stored = $this->attachments->find($uploaded['id']);
		self::assertNotNull($stored);
		self::assertSame(-0.5, $stored->focusX);
		self::assertSame(0.25, $stored->focusY);
		self::assertSame('a description', $stored->altText);

		$clear = $app->handle(new Request(
			method: 'PUT',
			path: '/api/mastodon/v1/media/' . $uploaded['id'],
			headers: ['Content-Type' => 'application/x-www-form-urlencoded'],
			body: http_build_query(['focus' => '']),
			userId: 'alice',
			scopes: ['write:media'],
		));
		self::assertSame(200, $clear->status, $clear->body);

		$stored = $this->attachments->find($uploaded['id']);
		self::assertNotNull($stored);
		self::assertNull($stored->focusX);
		self::assertNull($stored->focusY);
		self::assertSame('a description', $stored->altText, 'Clearing the focus keeps the description.');

		$invalid = $app->handle(new Request(
			method: 'PUT',
			path: '/api/mastodon/v1/media/' . $uploaded['id'],
			headers: ['Content-Type' => 'application/x-www-form-urlencoded'],
			body: http_build_query(['focus' => '2,0']),
			userId: 'alice',
			scopes: ['write:media'],
		));
		self::assertSame(422, $invalid->status);
		self::assertArrayHasKey('focus', $invalid->decodedBody()['error']['fields']);
	}

	public function testPngTextMetadataIsStrippedBeforeStorage(): void
	{
		$app = $this->app();
		$png = $this->png(120, 90);
		$text = 'Comment' . "\0" . 'GPSLatitudeSecretCamera';
		$chunk = pack('N', strlen($text)) . 'tEXt' . $text . pack('N', crc32('tEXt' . $text));
		$withText = substr($png, 0, -12) . $chunk . substr($png, -12);
		self::assertStringContainsString('GPSLatitudeSecretCamera', $withText);

		$uploaded = $this->upload($app, $withText, 'image/png', 'photo.png');
		self::assertSame(201, $uploaded->status, $uploaded->body);
		$attachment = $uploaded->decodedBody()['attachment'];

		$stored = $app->handle(new Request('GET', $attachment['url']));
		self::assertSame(200, $stored->status);
		self::assertStringNotContainsString('GPSLatitudeSecretCamera', $stored->body);

		$info = getimagesizefromstring($stored->body);
		self::assertIsArray($info);
		self::assertSame(120, $info[0]);
		self::assertSame(90, $info[1]);
	}

	public function testJpegMetadataIsStrippedWithoutChangingThePixels(): void
	{
		$jpeg = $this->jpeg();
		$segment = "Exif\0\0GPSLatitudeSecretCamera";
		$withExif = substr($jpeg, 0, 2)
			. "\xFF\xE1" . pack('n', strlen($segment) + 2) . $segment
			. substr($jpeg, 2);

		$stripped = (new MediaMetadataStripper())->strip($withExif, 'image/jpeg');

		self::assertStringNotContainsString('GPSLatitudeSecretCamera', $stripped);
		self::assertLessThan(strlen($withExif), strlen($stripped));
		$info = getimagesizefromstring($stripped);
		self::assertIsArray($info);
		self::assertSame('image/jpeg', $info['mime']);
		self::assertSame(1, $info[0]);
		self::assertSame(1, $info[1]);
	}

	public function testTheAttachmentCapIsConfigurable(): void
	{
		$app = $this->app(['social' => ['media.max_attachments' => '2']]);

		$ids = [];
		for ($i = 0; $i < 3; ++$i) {
			$ids[] = 'missing-' . $i;
		}

		$response = $app->handle(new Request(
			method: 'POST',
			path: '/api/v1/posts',
			headers: ['Content-Type' => 'application/json'],
			body: json_encode(['content' => 'too many', 'attachment_ids' => $ids], JSON_THROW_ON_ERROR),
			userId: 'alice',
		));

		self::assertSame(422, $response->status);
		self::assertSame(
			'A post may have at most 2 attachments.',
			$response->decodedBody()['error']['fields']['attachment_ids'],
		);
	}

	/**
	 * @param array<string, array<string, string>> $config
	 */
	private function app(array $config = [], ?MediaTranscoder $transcoder = null): Application
	{
		$social = [
			'instance.url' => 'https://social.example',
			'mastodon.api' => 'true',
			'mastodon.prefix' => '/api/mastodon/v1',
		];
		foreach ($config['social'] ?? [] as $key => $value) {
			$social[$key] = $value;
		}

		return Application::inMemory(
			clock: new FrozenClock(Time::parse('2026-11-11T10:00:00Z')),
			config: new ArrayConfig(['social' => $social]),
			attachmentRepository: $this->attachments,
			blobStore: $this->blobs,
			localActors: new InMemoryActorDirectory(['alice']),
			mediaTranscoder: $transcoder,
		);
	}

	private function upload(Application $app, string $bytes, string $mimeType, string $filename): Response
	{
		$boundary = '----AlohaSocial' . bin2hex(random_bytes(8));
		$body = '--' . $boundary . "\r\n"
			. 'Content-Disposition: form-data; name="file"; filename="' . $filename . "\"\r\n"
			. 'Content-Type: ' . $mimeType . "\r\n\r\n"
			. $bytes . "\r\n"
			. '--' . $boundary . "--\r\n";

		return $app->handle(new Request(
			method: 'POST',
			path: '/api/v1/media',
			headers: ['Content-Type' => 'multipart/form-data; boundary=' . $boundary],
			body: $body,
			userId: 'alice',
		));
	}

	private function mp4(int $width, int $height, int $duration): string
	{
		$ftyp = pack('N', 24) . 'ftyp' . 'isom' . pack('N', 512) . 'isomiso2';

		$mvhd = pack('N', 0)
			. pack('N', 0)
			. pack('N', 0)
			. pack('N', 1000)
			. pack('N', $duration * 1000)
			. pack('N', 0x00010000)
			. pack('n', 0x0100)
			. str_repeat("\0", 10)
			. str_repeat("\0", 36)
			. str_repeat("\0", 24)
			. pack('N', 2);
		$mvhd = pack('N', 8 + strlen($mvhd)) . 'mvhd' . $mvhd;

		$tkhd = pack('N', 0)
			. pack('N', 0)
			. pack('N', 0)
			. pack('N', 1)
			. pack('N', 0)
			. pack('N', $duration * 1000)
			. str_repeat("\0", 8)
			. pack('n', 0)
			. pack('n', 0)
			. pack('n', 0x0100)
			. pack('n', 0)
			. str_repeat("\0", 36)
			. pack('N', $width << 16)
			. pack('N', $height << 16);
		$tkhd = pack('N', 8 + strlen($tkhd)) . 'tkhd' . $tkhd;
		$trak = pack('N', 8 + strlen($tkhd)) . 'trak' . $tkhd;

		$moov = pack('N', 8 + strlen($mvhd) + strlen($trak)) . 'moov' . $mvhd . $trak;

		return $ftyp . $moov;
	}

	private function wav(int $seconds): string
	{
		$data = str_repeat("\0", 8000 * $seconds);
		$fmt = 'fmt ' . pack('V', 16) . pack('v', 1) . pack('v', 1)
			. pack('V', 8000) . pack('V', 8000) . pack('v', 1) . pack('v', 8);

		return 'RIFF' . pack('V', 4 + strlen($fmt) + 8 + strlen($data)) . 'WAVE'
			. $fmt . 'data' . pack('V', strlen($data)) . $data;
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

	private function gif(): string
	{
		$image = imagecreatetruecolor(20, 20);
		self::assertNotFalse($image);
		$color = imagecolorallocate($image, 20, 120, 200);
		imagefill($image, 0, 0, $color);

		ob_start();
		imagegif($image);
		$bytes = ob_get_clean();
		imagedestroy($image);

		self::assertIsString($bytes);

		return $bytes;
	}

	private function jpeg(): string
	{
		$bytes = base64_decode(
			'/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/9oACAEBAAA/APn+iiigD//Z',
			true,
		);
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
