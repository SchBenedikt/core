<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Http\ApiVersion;
use AlohaSocial\Core\Http\Handler;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Service\BlockActor;
use AlohaSocial\Core\Service\BlockService;
use AlohaSocial\Core\Service\ValidationException;

use function is_string;

/**
 * `POST /api/v1/blocks`
 *
 * Blocks a remote actor for the signed-in account. A new block answers 201
 * with a Location; repeating the call answers 200 with the existing row and
 * creates nothing. The actor is not removed from other accounts' timelines.
 */
final class BlockActorHandler implements Handler
{
	public function __construct(
		private readonly BlockService $blocks,
	) {
	}

	public function handle(Request $request): Response
	{
		$userId = $request->userId;
		if ($userId === null) {
			throw HttpException::unauthorized();
		}

		$body = $request->json();

		$actor = $body['actor'] ?? null;
		if (!is_string($actor)) {
			throw HttpException::validationFailed(['actor' => 'actor must be a string.']);
		}

		try {
			$result = $this->blocks->block(new BlockActor($userId, $actor));
		} catch (ValidationException $exception) {
			throw HttpException::validationFailed($exception->fields(), $exception->getMessage());
		}

		$block = $result->block;

		return Response::json(
			['block' => $block->toArray()],
			$result->created ? 201 : 200,
			$result->created
				? ['Location' => ApiVersion::BASE_PATH . '/blocks/' . rawurlencode($block->id)]
				: [],
		);
	}
}
