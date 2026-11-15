<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Domain\Post\PostNotFoundException;
use AlohaSocial\Core\Http\Handler;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Service\Collection\MediaCollectionNotFoundException;
use AlohaSocial\Core\Service\Collection\MediaCollectionService;
use AlohaSocial\Core\Service\ValidationException;

use function is_string;

/**
 * `POST /api/v1/collections/{id}/items`
 */
final class AddCollectionItemHandler implements Handler
{
	public function __construct(
		private readonly MediaCollectionService $collections,
	) {
	}

	public function handle(Request $request): Response
	{
		$userId = $request->userId;
		if ($userId === null) {
			throw HttpException::unauthorized();
		}

		$postId = $request->payload()['post_id'] ?? null;
		if (!is_string($postId) || trim($postId) === '') {
			throw HttpException::validationFailed(['post_id' => 'A post id is required.']);
		}

		try {
			$item = $this->collections->addItem($userId, (string) $request->attribute('id', ''), $postId);
		} catch (MediaCollectionNotFoundException) {
			throw HttpException::notFound('Collection not found.');
		} catch (PostNotFoundException) {
			throw HttpException::notFound('Post not found.');
		} catch (ValidationException $exception) {
			throw HttpException::validationFailed($exception->fields(), $exception->getMessage());
		}

		return Response::json(['item' => $item->toArray()], 201, ['Cache-Control' => 'private, no-store']);
	}
}
