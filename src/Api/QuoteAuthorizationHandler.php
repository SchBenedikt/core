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
use AlohaSocial\Core\Service\QuoteNotFoundException;
use AlohaSocial\Core\Service\QuoteService;

/**
 * `GET /api/v1/quote-authorizations/{id}`
 *
 * The FEP-044f document a peer dereferences. It is public because the quoted
 * author publishes it; a revoked grant and a target that is no longer public
 * both answer 404 instead of a stale authorization.
 */
final class QuoteAuthorizationHandler implements Handler
{
	public function __construct(
		private readonly QuoteService $quotes,
	) {
	}

	public function handle(Request $request): Response
	{
		try {
			$document = $this->quotes->authorizationDocument((string) $request->attribute('id', ''));
		} catch (QuoteNotFoundException $exception) {
			throw HttpException::notFound($exception->getMessage());
		}

		return Response::json($document, 200, ['Content-Type' => 'application/activity+json']);
	}
}
