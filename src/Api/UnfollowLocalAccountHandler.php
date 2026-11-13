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

/**
 * `DELETE /api/v1/accounts/{id}/follow`
 *
 * Removes a local follow. An absent edge is a successful no-op, matching the
 * idempotent follow and the other relationship writes.
 */
final class UnfollowLocalAccountHandler implements Handler
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
			$relationship = $this->accounts->unfollow(
				$userId,
				(string) $request->attribute('id', ''),
			);
		} catch (UnknownLocalActorException) {
			throw HttpException::notFound('Account not found.');
		} catch (ConfigurationException $exception) {
			throw new HttpException(503, 'accounts_not_configured', $exception->getMessage());
		}

		return Response::json(['relationship' => $relationship]);
	}
}
