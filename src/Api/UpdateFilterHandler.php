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
use AlohaSocial\Core\Service\ValidationException;

/**
 * `PATCH /api/v1/filters/{id}`
 *
 * The whole rule is replaced; there is no partial keyword edit, because a
 * silent merge would make a removed keyword keep matching.
 */
final class UpdateFilterHandler implements Handler
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
			$rule = $this->filters->update(
				$userId,
				(string) $request->attribute('id', ''),
				$input['name'],
				$input['action'],
				$input['contexts'],
				$input['keywords'],
				$input['expiresAt'],
			);
		} catch (FilterNotFoundException) {
			throw HttpException::notFound('Filter not found.');
		} catch (ValidationException $exception) {
			throw HttpException::validationFailed($exception->fields(), $exception->getMessage());
		}

		return Response::json(['filter' => $rule->toArray()]);
	}
}
