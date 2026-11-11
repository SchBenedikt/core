<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Exceptions\ConfigurationException;
use AlohaSocial\Core\Http\ApiVersion;
use AlohaSocial\Core\Http\Handler;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Service\CreatePost;
use AlohaSocial\Core\Service\PostService;
use AlohaSocial\Core\Service\ValidationException;
use InvalidArgumentException;

use function is_string;

/**
 * `POST /api/v1/posts`
 *
 * Accepts `content`, optional `content_warning` and optional `visibility`.
 * Validation lives in the service; this handler only maps input to it and
 * the outcome to the documented JSON shapes.
 */
final class CreatePostHandler implements Handler
{
	public function __construct(
		private readonly PostService $posts,
	) {
	}

	public function handle(Request $request): Response
	{
		$userId = $request->userId;
		if ($userId === null) {
			throw HttpException::unauthorized();
		}

		$body = $request->json();

		$content = $body['content'] ?? '';
		if (!is_string($content)) {
			throw HttpException::validationFailed(['content' => 'content must be a string.']);
		}

		$contentWarning = $body['content_warning'] ?? null;
		if ($contentWarning !== null && !is_string($contentWarning)) {
			throw HttpException::validationFailed(['content_warning' => 'content_warning must be a string or null.']);
		}

		$rawVisibility = $body['visibility'] ?? Visibility::Public->value;
		if (!is_string($rawVisibility)) {
			throw HttpException::validationFailed(['visibility' => 'visibility must be a string.']);
		}

		try {
			$visibility = Visibility::fromString($rawVisibility);
		} catch (InvalidArgumentException $exception) {
			throw HttpException::validationFailed(['visibility' => $exception->getMessage()]);
		}

		try {
			$post = $this->posts->create(new CreatePost(
				authorId: $userId,
				content: $content,
				visibility: $visibility,
				contentWarning: $contentWarning,
			));
		} catch (ValidationException $exception) {
			throw HttpException::validationFailed($exception->fields(), $exception->getMessage());
		} catch (ConfigurationException $exception) {
			// There are followers to deliver to but no instance URL to build
			// the activity from: a server problem, not a client one.
			throw new HttpException(503, 'instance_not_configured', $exception->getMessage());
		}

		return $this->respond($post);
	}

	private function respond(Post $post): Response
	{
		return Response::json(
			['post' => $post->toArray()],
			201,
			['Location' => ApiVersion::BASE_PATH . '/posts/' . rawurlencode($post->id)],
		);
	}
}
