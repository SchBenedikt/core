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
use AlohaSocial\Core\Service\Collection\MediaCollectionService;
use AlohaSocial\Core\Service\ValidationException;
use InvalidArgumentException;

use function is_string;

/**
 * `POST /api/v1/collections`
 */
final class CreateCollectionHandler implements Handler
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
		$description = $input['description'] ?? '';
		$audience = $input['audience'] ?? Visibility::Followers->value;
		if (!is_string($title) || !is_string($description) || !is_string($audience)) {
			throw HttpException::validationFailed(['title' => 'Title, description and audience must be strings.']);
		}

		try {
			$collection = $this->collections->create(
				$userId,
				$title,
				$description,
				Visibility::fromString($audience),
			);
		} catch (InvalidArgumentException) {
			throw HttpException::validationFailed(['audience' => 'Audience must be public, unlisted or followers.']);
		} catch (ValidationException $exception) {
			throw HttpException::validationFailed($exception->fields(), $exception->getMessage());
		}

		return Response::json(
			['collection' => $this->collections->show($userId, $collection->id)],
			201,
			['Location' => '/api/v1/collections/' . rawurlencode($collection->id), 'Cache-Control' => 'private, no-store'],
		);
	}
}
