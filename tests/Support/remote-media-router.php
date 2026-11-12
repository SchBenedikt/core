<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$fixture = getenv('MEDIA_FIXTURE') ?: '';

switch ($path) {
	case '/health':
		header('Content-Type: text/plain');
		echo 'ok';
		return;

	case '/image.png':
		header('Content-Type: image/png');
		header('Content-Length: ' . (string) filesize($fixture));
		readfile($fixture);
		return;

	case '/redirect-image':
		header('Location: /image.png', true, 302);
		return;

	case '/redirect-private':
		header('Location: http://127.0.0.1:' . $_SERVER['SERVER_PORT'] . '/image.png', true, 302);
		return;

	case '/too-large':
		header('Content-Type: image/png');
		echo str_repeat('x', 200_000);
		return;

	case '/corrupt':
		header('Content-Type: image/png');
		echo "\x89PNG\r\n\x1a\nnot-an-image";
		return;

	case '/wrong-type':
		header('Content-Type: text/plain');
		echo 'this is not an image';
		return;

	case '/slow':
		usleep(1_800_000);
		header('Content-Type: image/png');
		readfile($fixture);
		return;

	case '/users/bob/outbox':
		$mode = $_GET['media'] ?? 'image';
		$activitySuffix = $_GET['activity'] ?? $mode;
		$activityType = $_GET['type'] ?? 'Create';
		$mediaUrl = match ($mode) {
			'ssrf' => 'http://127.0.0.1:' . $_SERVER['SERVER_PORT'] . '/image.png',
			'corrupt' => 'http://media.remote.example/corrupt',
			'too-large' => 'http://media.remote.example/too-large',
			'wrong-type' => 'http://media.remote.example/wrong-type',
			'slow' => 'http://media.remote.example/slow',
			default => 'http://media.remote.example/image.png',
		};
		$image = static fn (string $url, string $name): array => [
			'type' => 'Image',
			'url' => $url,
			'mediaType' => 'image/png',
			'name' => $name,
		];
		$attachments = match ($mode) {
			'two' => [
				$image('http://media.remote.example/image.png?first', 'First historical test image'),
				$image('http://media.remote.example/image.png?second', 'Second historical test image'),
			],
			'two-missing' => [
				$image('http://media.remote.example/image.png?first', 'First historical test image'),
				$image('http://media.remote.example/missing', 'Missing historical test image'),
			],
			default => [$image($mediaUrl, 'A historical test image')],
		};
		header('Content-Type: application/activity+json');
		echo json_encode([
			'id' => 'https://remote.example/users/bob/outbox',
			'type' => 'OrderedCollectionPage',
			'totalItems' => 1,
			'orderedItems' => [[
				'id' => 'https://remote.example/activities/create-backfill-' . $activitySuffix,
				'type' => $activityType,
				'actor' => 'https://remote.example/users/bob',
				'published' => '2026-11-11T10:01:00Z',
				'object' => [
					'id' => 'https://remote.example/notes/backfill-' . $activitySuffix,
					'type' => 'Note',
					'attributedTo' => 'https://remote.example/users/bob',
					'content' => 'historical post with a remote picture',
					'published' => '2026-11-11T10:01:00Z',
					'attachments' => $attachments,
				],
			]],
		], JSON_THROW_ON_ERROR);
		return;

	default:
		http_response_code(404);
		echo 'not found';
}
