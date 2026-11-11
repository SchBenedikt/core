<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Domain\Federation\InvalidWebFingerResourceException;
use AlohaSocial\Core\Domain\Federation\UnknownLocalActorException;
use AlohaSocial\Core\Exceptions\ConfigurationException;
use AlohaSocial\Core\Http\Handler;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Service\Federation\WebFingerService;

/**
 * `GET /.well-known/webfinger?resource=acct:{handle}@{host}`
 *
 * The public JRD that turns a handle into the actor document. It is enabled
 * with the rest of the actor surface; without a key or directory it answers
 * 503 instead of advertising an actor that cannot be served.
 */
final class WebFingerHandler implements Handler
{
	public function __construct(
		private readonly ?WebFingerService $webFinger,
	) {
	}

	public function handle(Request $request): Response
	{
		if ($this->webFinger === null) {
			throw new HttpException(
				503,
				'actor_not_configured',
				'WebFinger is not configured on this instance.',
			);
		}

		try {
			$jrd = $this->webFinger->resolve($request->queryParam('resource'));
		} catch (InvalidWebFingerResourceException) {
			throw HttpException::badRequest('invalid_resource', 'The resource must be an acct: URI.');
		} catch (UnknownLocalActorException) {
			throw new HttpException(404, 'actor_not_found', 'Actor not found.');
		} catch (ConfigurationException) {
			throw new HttpException(
				503,
				'instance_not_configured',
				'The instance URL is not configured.',
			);
		}

		return Response::json($jrd, 200, ['Content-Type' => WebFingerService::CONTENT_TYPE]);
	}
}
