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
use AlohaSocial\Core\Service\FilterService;
use AlohaSocial\Core\Service\ValidationException;

/**
 * `POST /api/v1/filters`
 */
final class CreateFilterHandler implements Handler
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

		$input = FilterRequestParser::parse($request);

		try {
			$rule = $this->filters->create(
				$userId,
				$input['name'],
				$input['action'],
				$input['contexts'],
				$input['keywords'],
				$input['expiresAt'],
			);
		} catch (ValidationException $exception) {
			throw HttpException::validationFailed($exception->fields(), $exception->getMessage());
		}

		return Response::json(
			['filter' => $rule->toArray()],
			201,
			['Location' => '/api/v1/filters/' . rawurlencode($rule->id)],
		);
	}
}
