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
use AlohaSocial\Core\Service\Collection\MediaCollectionNotFoundException;
use AlohaSocial\Core\Service\Collection\MediaCollectionService;

/**
 * `DELETE /api/v1/collections/{id}/items/{postId}`
 */
final class RemoveCollectionItemHandler implements Handler
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

		try {
			$this->collections->removeItem(
				$userId,
				(string) $request->attribute('id', ''),
				(string) $request->attribute('postId', ''),
			);
		} catch (MediaCollectionNotFoundException) {
			throw HttpException::notFound('Collection not found.');
		}

		return Response::empty(204);
	}
}
