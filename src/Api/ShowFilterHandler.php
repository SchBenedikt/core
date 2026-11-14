<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Domain\Filter\FilterNotFoundException;
use AlohaSocial\Core\Http\Handler;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Service\FilterService;

/**
 * `GET /api/v1/filters/{id}`
 */
final class ShowFilterHandler implements Handler
{
	public function __construct(
		private readonly FilterService $filters,
	) {
	}

	public function handle(Request $request): Response
	{
		$userId = $request->userId;
		if ($userId === null) {
			throw HttpException::unauthorized();
		}

		try {
			$rule = $this->filters->findOwned($userId, (string) $request->attribute('id', ''));
		} catch (FilterNotFoundException) {
			throw HttpException::notFound('Filter not found.');
		}

		return Response::json(['filter' => $rule->toArray()]);
	}
}
