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
use AlohaSocial\Core\Service\Collection\MediaCollectionService;

/**
 * `GET /api/v1/collections`
 */
final class CollectionsHandler implements Handler
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

		return Response::json(['collections' => $this->collections->forOwner($userId)], 200, ['Cache-Control' => 'private, no-store']);
	}
}
