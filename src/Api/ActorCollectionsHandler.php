<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Domain\Federation\LocalActorDirectory;
use AlohaSocial\Core\Http\Handler;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Service\Collection\MediaCollectionService;

/**
 * `GET /@{handle}/collections`
 *
 * A profile collection list is viewer-aware: public collections are visible to
 * everyone, followers-only collections to accepted followers and the owner,
 * and unlisted collections never appear in the enumeration.
 */
final class ActorCollectionsHandler implements Handler
{
	public function __construct(
		private readonly MediaCollectionService $collections,
		private readonly ?LocalActorDirectory $actors = null,
	) {
	}

	public function handle(Request $request): Response
	{
		$handle = trim((string) $request->attribute('handle', ''));
		if ($handle === '' || $this->actors === null || !$this->actors->has($handle)) {
			throw new HttpException(404, 'actor_not_found', 'Actor not found.');
		}

		return Response::json([
			'collections' => $this->collections->forActor(
				$handle,
				$request->userId,
				$request->queryInt('limit', MediaCollectionService::DEFAULT_LIMIT, 1, MediaCollectionService::MAX_LIMIT),
				$request->queryInt('offset', 0, 0, 10000),
			),
		], 200, ['Cache-Control' => 'private, no-store']);
	}
}
