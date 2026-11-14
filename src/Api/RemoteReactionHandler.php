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
use AlohaSocial\Core\Service\ReactionException;
use AlohaSocial\Core\Service\ReactionService;

/**
 * Shared refusal mapping for the remote reaction routes.
 *
 * The service reasons are stable, so the HTTP codes are chosen once here:
 * an unknown or unfollowed object is 404 (both mean "not a target you may
 * see"), a block is 403, an invalid emoji is 422. A missing service is an
 * honest 503 instead of an empty success.
 */
abstract class RemoteReactionHandler implements Handler
{
	public function __construct(
		protected readonly ?ReactionService $reactions,
	) {
	}

	protected function service(): ReactionService
	{
		if ($this->reactions === null) {
			throw new HttpException(
				503,
				'reactions_not_configured',
				'Reaction storage is not configured on this instance.',
			);
		}

		return $this->reactions;
	}

	protected function userId(Request $request): string
	{
		$userId = $request->userId;
		if ($userId === null) {
			throw HttpException::unauthorized();
		}

		return $userId;
	}

	/**
	 * @param callable(): array<string, mixed> $work
	 */
	protected function interaction(callable $work): Response
	{
		try {
			return Response::json(['interaction' => $work()]);
		} catch (ReactionException $exception) {
			throw $this->map($exception);
		}
	}

	protected function map(ReactionException $exception): HttpException
	{
		return match ($exception->reason) {
			'remote_post_not_found' => HttpException::notFound('Remote post not found.'),
			'remote_post_not_followed', 'blocked_actor' => new HttpException(
				403,
				$exception->reason,
				$exception->getMessage(),
			),
			default => new HttpException(422, $exception->reason, $exception->getMessage()),
		};
	}
}
