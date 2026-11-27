<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$fixture = getenv('MEDIA_FIXTURE') ?: '';

/**
 * A minimal ActivityPub Note for the thread-context tests. The ids use the
 * documented remote origin; LocalHttpClient maps the request to this server.
 *
 * @return array<string, mixed>
 */
$note = static function (
	string $id,
	string $author,
	string $content,
	string $published,
	?string $inReplyTo = null,
	array $to = [],
): array {
	$object = [
		'id' => $id,
		'type' => 'Note',
		'attributedTo' => 'https://remote.example/users/' . $author,
		'content' => $content,
		'published' => $published,
	];
	if ($inReplyTo !== null) {
		$object['inReplyTo'] = $inReplyTo;
	}
	if ($to !== []) {
		$object['to'] = $to;
	}

	return $object;
};

$threadTarget = $note(
	'https://remote.example/notes/target',
	'bob',
	'target content',
	'2026-11-11T10:02:00Z',
	'https://remote.example/notes/parent',
);
$threadTarget['replies'] = [
	'type' => 'Collection',
	'first' => 'http://thread.remote.example/thread/replies',
];

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

	case '/notes/target':
		header('Content-Type: application/activity+json');
		echo json_encode($threadTarget, JSON_THROW_ON_ERROR);
		return;

	case '/notes/parent':
		header('Content-Type: application/activity+json');
		echo json_encode($note(
			'https://remote.example/notes/parent',
			'carol',
			'parent content',
			'2026-11-11T10:01:00Z',
			'https://remote.example/notes/root',
		), JSON_THROW_ON_ERROR);
		return;

	case '/notes/root':
		header('Content-Type: application/activity+json');
		echo json_encode($note(
			'https://remote.example/notes/root',
			'dave',
			'root content',
			'2026-11-11T10:00:00Z',
		), JSON_THROW_ON_ERROR);
		return;

	case '/notes/public-target':
		$publicTarget = $note(
			'https://remote.example/notes/public-target',
			'bob',
			'public target',
			'2026-11-11T10:02:00Z',
			'https://remote.example/notes/private-parent',
			['https://www.w3.org/ns/activitystreams#Public'],
		);
		$publicTarget['replies'] = [
			'type' => 'Collection',
			'first' => 'http://thread.remote.example/thread/public-replies',
		];
		header('Content-Type: application/activity+json');
		echo json_encode($publicTarget, JSON_THROW_ON_ERROR);
		return;

	case '/notes/private-parent':
		header('Content-Type: application/activity+json');
		echo json_encode($note(
			'https://remote.example/notes/private-parent',
			'dave',
			'followers-only parent',
			'2026-11-11T10:01:00Z',
			'https://remote.example/notes/root',
			['https://remote.example/users/dave/followers'],
		), JSON_THROW_ON_ERROR);
		return;

	case '/notes/followers-target':
		header('Content-Type: application/activity+json');
		echo json_encode($note(
			'https://remote.example/notes/followers-target',
			'bob',
			'followers target',
			'2026-11-11T10:02:00Z',
			null,
			['https://remote.example/users/bob/followers'],
		), JSON_THROW_ON_ERROR);
		return;

	case '/thread/public-replies':
		header('Content-Type: application/activity+json');
		echo json_encode([
			'id' => 'http://thread.remote.example/thread/public-replies',
			'type' => 'OrderedCollection',
			'orderedItems' => [
				$note(
					'https://remote.example/notes/public-reply',
					'erin',
					'public reply',
					'2026-11-11T10:03:00Z',
					'https://remote.example/notes/public-target',
					['https://www.w3.org/ns/activitystreams#Public'],
				),
				$note(
					'https://remote.example/notes/followers-reply',
					'frank',
					'followers-only reply',
					'2026-11-11T10:04:00Z',
					'https://remote.example/notes/public-target',
					['https://remote.example/users/frank/followers'],
				),
			],
		], JSON_THROW_ON_ERROR);
		return;

	case '/notes/blocked-target':
		header('Content-Type: application/activity+json');
		echo json_encode($note(
			'https://remote.example/notes/blocked-target',
			'bob',
			'blocked target',
			'2026-11-11T10:02:00Z',
			'https://remote.example/notes/blocked-parent',
		), JSON_THROW_ON_ERROR);
		return;

	case '/notes/blocked-parent':
		header('Content-Type: application/activity+json');
		echo json_encode($note(
			'https://remote.example/notes/blocked-parent',
			'mallory',
			'blocked parent',
			'2026-11-11T10:01:00Z',
			'https://remote.example/notes/root',
		), JSON_THROW_ON_ERROR);
		return;

	case '/notes/ssrf-target':
		header('Content-Type: application/activity+json');
		echo json_encode($note(
			'https://remote.example/notes/ssrf-target',
			'bob',
			'ssrf target',
			'2026-11-11T10:02:00Z',
			'http://127.0.0.1:' . $_SERVER['SERVER_PORT'] . '/secret',
		), JSON_THROW_ON_ERROR);
		return;

	case '/notes/invalid-target':
		header('Content-Type: application/activity+json');
		echo json_encode([
			'id' => 'https://remote.example/notes/invalid-target',
			'type' => 'Note',
			'attributedTo' => 'https://remote.example/users/bob',
			'published' => '2026-11-11T10:02:00Z',
		], JSON_THROW_ON_ERROR);
		return;

	case '/notes/too-large':
		header('Content-Type: application/activity+json');
		echo str_repeat('x', 2_000_000);
		return;

	case '/notes/slow':
		usleep(1_800_000);
		header('Content-Type: application/activity+json');
		echo json_encode($note(
			'https://remote.example/notes/slow',
			'bob',
			'slow target',
			'2026-11-11T10:02:00Z',
		), JSON_THROW_ON_ERROR);
		return;

	case '/thread/replies':
		header('Content-Type: application/activity+json');
		$direct = $note(
			'https://remote.example/notes/reply-a',
			'erin',
			'first direct reply',
			'2026-11-11T10:03:00Z',
			'https://remote.example/notes/target',
		);
		$blocked = $note(
			'https://remote.example/notes/reply-blocked',
			'mallory',
			'blocked reply',
			'2026-11-11T10:04:00Z',
			'https://remote.example/notes/target',
		);
		$second = $note(
			'https://remote.example/notes/reply-b',
			'frank',
			'second direct reply',
			'2026-11-11T10:05:00Z',
			'https://remote.example/notes/target',
		);
		$nested = $note(
			'https://remote.example/notes/reply-nested',
			'grace',
			'nested reply',
			'2026-11-11T10:06:00Z',
			'https://remote.example/notes/reply-a',
		);
		echo json_encode([
			'id' => 'http://thread.remote.example/thread/replies',
			'type' => 'OrderedCollection',
			'orderedItems' => [
				[
					'id' => 'https://remote.example/activities/create-reply-a',
					'type' => 'Create',
					'object' => $direct,
				],
				$blocked,
				$second,
				$nested,
				['id' => 'https://remote.example/notes/broken-reply', 'type' => 'Note'],
			],
		], JSON_THROW_ON_ERROR);
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
				'to' => ['https://www.w3.org/ns/activitystreams#Public'],
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
