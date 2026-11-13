<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Domain\Federation\UnknownLocalActorException;
use AlohaSocial\Core\Exceptions\ConfigurationException;
use AlohaSocial\Core\Http\Handler;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Service\LocalAccountService;
use AlohaSocial\Core\Service\ValidationException;

/**
 * `POST /api/v1/accounts/{id}/follow`
 *
 * Follows one local account. The follower edge is idempotent, so a repeated
 * call returns the same relationship without a second row. A self-follow is
 * a validation error; an unknown target is not found.
 */
final class FollowLocalAccountHandler implements Handler
{
	public function __construct(
		private readonly LocalAccountService $accounts,
	) {
	}

	public function handle(Request $request): Response
	{
		$userId = $request->userId;
		if ($userId === null) {
			throw HttpException::unauthorized();
		}

		try {
			$relationship = $this->accounts->follow(
				$userId,
				(string) $request->attribute('id', ''),
			);
		} catch (UnknownLocalActorException) {
			throw HttpException::notFound('Account not found.');
		} catch (ValidationException $exception) {
			throw HttpException::validationFailed($exception->fields(), $exception->getMessage());
		} catch (ConfigurationException $exception) {
			throw new HttpException(503, 'accounts_not_configured', $exception->getMessage());
		}

		return Response::json(['relationship' => $relationship]);
	}
}
