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

	default:
		http_response_code(404);
		echo 'not found';
}
