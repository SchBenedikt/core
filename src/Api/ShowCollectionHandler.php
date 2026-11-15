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
 * `GET /api/v1/collections/{id}`
 */
final class ShowCollectionHandler implements Handler
{
	public function __construct(
		private readonly MediaCollectionService $collections,
	) {
	}

	public function handle(Request $request): Response
	{
		try {
			$collection = $this->collections->show(
				$request->userId,
				(string) $request->attribute('id', ''),
				$request->queryInt('limit', MediaCollectionService::DEFAULT_LIMIT, 1, MediaCollectionService::MAX_LIMIT),
				$request->queryInt('offset', 0, 0, 10000),
			);
		} catch (MediaCollectionNotFoundException) {
			throw HttpException::notFound('Collection not found.');
		}

		return Response::json(['collection' => $collection], 200, ['Cache-Control' => 'private, no-store']);
	}
}
