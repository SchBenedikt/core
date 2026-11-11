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
use AlohaSocial\Core\Service\Federation\ActorDocumentService;

/**
 * `GET /@{handle}/followers`
 *
 * An `OrderedCollection` of the actors that follow this account, newest
 * first. It reads the same repository the inbox writes, so an accepted
 * Follow or Undo is visible here immediately.
 */
final class FollowersCollectionHandler implements Handler
{
	public function __construct(
		private readonly ?ActorDocumentService $documents,
	) {
	}

	public function handle(Request $request): Response
	{
		if ($this->documents === null) {
			throw new HttpException(
				503,
				'actor_not_configured',
				'Actor documents are not configured on this instance.',
			);
		}

		$handle = (string) $request->attribute('handle', '');

		try {
			$collection = $this->documents->followers($handle);
		} catch (UnknownLocalActorException) {
			throw new HttpException(404, 'actor_not_found', 'Actor not found.');
		} catch (ConfigurationException) {
			throw new HttpException(
				503,
				'instance_not_configured',
				'The instance URL is not configured.',
			);
		}

		return Response::json($collection, 200, ['Content-Type' => ActorHandler::CONTENT_TYPE]);
	}
}
