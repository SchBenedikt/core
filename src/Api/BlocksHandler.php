<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Domain\Federation\Block;
use AlohaSocial\Core\Http\Handler;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Service\BlockService;
use AlohaSocial\Core\Service\InvalidCursorException;

/**
 * `GET /api/v1/blocks?limit=&before=`
 *
 * Lists the signed-in account's blocks, newest first. Blocks are private to
 * the caller; there is no public block collection.
 */
final class BlocksHandler implements Handler
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

		try {
			$blocks = $this->blocks->blocks(
				$userId,
				$request->queryInt('limit', BlockService::DEFAULT_LIMIT, 1, BlockService::MAX_LIMIT),
				$request->queryParam('before'),
			);
		} catch (InvalidCursorException $exception) {
			throw HttpException::badRequest('invalid_cursor', $exception->getMessage());
		}

		return Response::json([
			'blocks' => array_map(static fn (Block $block): array => $block->toArray(), $blocks),
			'total' => $this->blocks->count($userId),
		]);
	}
}
