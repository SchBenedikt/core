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
use AlohaSocial\Core\Service\QuoteAccessDeniedException;
use AlohaSocial\Core\Service\QuoteNotFoundException;
use AlohaSocial\Core\Service\QuoteService;

/**
 * `DELETE /api/v1/quote-authorizations/{id}`
 *
 * Only the author of the quoted post may withdraw the grant. Revoking twice
 * is a successful no-op, so a retried request cannot fail after the first.
 */
final class RevokeQuoteAuthorizationHandler implements Handler
{
	public function __construct(
		private readonly QuoteService $quotes,
	) {
	}

	public function handle(Request $request): Response
	{
		$userId = $request->userId;
		if ($userId === null) {
			throw HttpException::unauthorized();
		}

		try {
			$grant = $this->quotes->revoke((string) $request->attribute('id', ''), $userId);
		} catch (QuoteNotFoundException $exception) {
			throw HttpException::notFound($exception->getMessage());
		} catch (QuoteAccessDeniedException $exception) {
			throw HttpException::forbidden($exception->getMessage());
		}

		return Response::json([
			'revoked' => true,
			'id' => $grant->id,
			'state' => $grant->state->value,
		]);
	}
}
