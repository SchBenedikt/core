<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Post\PostAccessDeniedException;
use AlohaSocial\Core\Domain\Post\PostConflictException;
use AlohaSocial\Core\Domain\Post\PostNotFoundException;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Exceptions\ConfigurationException;
use AlohaSocial\Core\Http\Handler;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Service\MediaService;
use AlohaSocial\Core\Service\PostService;
use AlohaSocial\Core\Service\UpdatePost;
use AlohaSocial\Core\Service\ValidationException;
use InvalidArgumentException;

use function array_key_exists;
use function is_array;
use function is_int;
use function is_string;
use function trim;

/**
 * `PATCH /api/v1/posts/{id}`
 *
 * A patch: an absent field is left alone, `content_warning: null` clears the
 * warning. `revision` is the version the caller read; a stale value answers
 * 409 instead of overwriting a concurrent edit. Ownership, validation and the
 * audience rules live in the service.
 */
final class UpdatePostHandler implements Handler
{
	public function __construct(
		private readonly PostService $posts,
		private readonly ?MediaService $media = null,
	) {
	}

	public function handle(Request $request): Response
	{
		$userId = $request->userId;
		if ($userId === null) {
			throw HttpException::unauthorized();
		}

		$body = $request->json();

		$content = null;
		if (array_key_exists('content', $body)) {
			if (!is_string($body['content'])) {
				throw HttpException::validationFailed(['content' => 'content must be a string.']);
			}

			$content = $body['content'];
		}

		$warningProvided = array_key_exists('content_warning', $body);
		$contentWarning = $warningProvided ? $body['content_warning'] : null;
		if ($contentWarning !== null && !is_string($contentWarning)) {
			throw HttpException::validationFailed([
				'content_warning' => 'content_warning must be a string or null.',
			]);
		}

		$attachmentIds = null;
		if (array_key_exists('attachment_ids', $body)) {
			$raw = $body['attachment_ids'];
			if (!is_array($raw) || !array_is_list($raw)) {
				throw HttpException::validationFailed([
					'attachment_ids' => 'attachment_ids must be a list of strings.',
				]);
			}

			$attachmentIds = [];
			foreach ($raw as $attachmentId) {
				if (!is_string($attachmentId) || trim($attachmentId) === '') {
					throw HttpException::validationFailed([
						'attachment_ids' => 'attachment_ids must be a list of strings.',
					]);
				}

				$attachmentIds[] = $attachmentId;
			}
		}

		$visibility = null;
		if (array_key_exists('visibility', $body)) {
			$raw = $body['visibility'];
			if (!is_string($raw)) {
				throw HttpException::validationFailed(['visibility' => 'visibility must be a string.']);
			}

			try {
				$visibility = Visibility::fromString($raw);
			} catch (InvalidArgumentException $exception) {
				throw HttpException::validationFailed(['visibility' => $exception->getMessage()]);
			}
		}

		$expectedRevision = null;
		if (array_key_exists('revision', $body)) {
			if (!is_int($body['revision'])) {
				throw HttpException::validationFailed(['revision' => 'revision must be an integer.']);
			}

			$expectedRevision = $body['revision'];
		}

		try {
			$post = $this->posts->edit($userId, (string) $request->attribute('id', ''), new UpdatePost(
				content: $content,
				contentWarning: $contentWarning,
				contentWarningProvided: $warningProvided,
				attachmentIds: $attachmentIds,
				visibility: $visibility,
				expectedRevision: $expectedRevision,
			));
		} catch (PostNotFoundException) {
			throw HttpException::notFound('Post not found.');
		} catch (PostAccessDeniedException) {
			throw HttpException::forbidden('You can only edit your own posts.');
		} catch (PostConflictException $exception) {
			throw new HttpException(
				409,
				'post_revision_conflict',
				'The post was edited concurrently; it is now at revision ' . $exception->revision . '.',
			);
		} catch (ValidationException $exception) {
			throw HttpException::validationFailed($exception->fields(), $exception->getMessage());
		} catch (ConfigurationException $exception) {
			// Editing a post that has followers to notify needs an instance
			// URL to build the Update from: a server problem, not a client one.
			throw new HttpException(503, 'instance_not_configured', $exception->getMessage());
		}

		return $this->respond($post);
	}

	private function respond(Post $post): Response
	{
		$shape = $this->media === null ? $post->toArray() : $this->media->presentPost($post);

		return Response::json(['post' => $shape]);
	}
}
