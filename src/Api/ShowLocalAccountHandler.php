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
 * `GET /api/v1/accounts/{id}`
 *
 * A public local profile. When the request carries a local viewer, the
 * response includes that viewer's relationship; otherwise `relationship` is
 * null and no viewer is guessed.
 */
final class ShowLocalAccountHandler implements Handler
{
	public function __construct(
		private readonly LocalAccountService $accounts,
	) {
	}

	public function handle(Request $request): Response
	{
		try {
			$account = $this->accounts->profile(
				(string) $request->attribute('id', ''),
				$request->userId,
			);
		} catch (UnknownLocalActorException) {
			throw HttpException::notFound('Account not found.');
		} catch (ConfigurationException $exception) {
			throw new HttpException(503, 'accounts_not_configured', $exception->getMessage());
		}

		return Response::json(['account' => $account]);
	}
}
