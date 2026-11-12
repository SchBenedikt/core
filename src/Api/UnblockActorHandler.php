<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Http\Handler;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Service\BlockService;

/**
 * `DELETE /api/v1/blocks/{id}`
 *
 * Removes one of the caller's blocks. An id that belongs to another account
 * is answered with 404 rather than 403: the id alone must not reveal that
 * another account blocks this actor.
 */
final class UnblockActorHandler implements Handler
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

		$id = (string) $request->attribute('id', '');
		if ($id === '' || !$this->blocks->unblock($userId, $id)) {
			throw HttpException::notFound('Blocked actor not found.');
		}

		return Response::empty(204);
	}
}
