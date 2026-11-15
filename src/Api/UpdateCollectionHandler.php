<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Http\Handler;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Service\Collection\MediaCollectionNotFoundException;
use AlohaSocial\Core\Service\Collection\MediaCollectionService;
use AlohaSocial\Core\Service\ValidationException;
use InvalidArgumentException;

use function is_string;

/**
 * `PATCH /api/v1/collections/{id}`
 */
final class UpdateCollectionHandler implements Handler
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

		$input = $request->payload();
		$title = $input['title'] ?? null;
		$description = $input['description'] ?? null;
		$audience = $input['audience'] ?? null;
		if (($title !== null && !is_string($title))
			|| ($description !== null && !is_string($description))
			|| ($audience !== null && !is_string($audience))
		) {
			throw HttpException::validationFailed(['title' => 'Title, description and audience must be strings.']);
		}

		$visibility = null;
		if ($audience !== null) {
			try {
				$visibility = Visibility::fromString($audience);
			} catch (InvalidArgumentException) {
				throw HttpException::validationFailed(['audience' => 'Audience must be public, unlisted or followers.']);
			}
		}

		try {
			$collection = $this->collections->update($userId, (string) $request->attribute('id', ''), $title, $description, $visibility);
		} catch (MediaCollectionNotFoundException) {
			throw HttpException::notFound('Collection not found.');
		} catch (ValidationException $exception) {
			throw HttpException::validationFailed($exception->fields(), $exception->getMessage());
		}

		return Response::json(['collection' => $this->collections->show($userId, $collection->id)], 200, ['Cache-Control' => 'private, no-store']);
	}
}
