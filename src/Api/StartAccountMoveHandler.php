<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Domain\Federation\AccountMove;
use AlohaSocial\Core\Http\Handler;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Service\AccountMoveException;
use AlohaSocial\Core\Service\AccountMoveService;
use AlohaSocial\Core\Support\Time;
use Throwable;

use function count;
use function is_string;

/** Starts an authenticated, reciprocal ActivityPub account move. */
final class StartAccountMoveHandler implements Handler
{
	public function __construct(
		private readonly ?AccountMoveService $moves,
	) {
	}

	public function handle(Request $request): Response
	{
		$userId = $request->userId;
		if ($userId === null) {
			throw HttpException::unauthorized();
		}
		if ($this->moves === null) {
			throw new HttpException(503, 'account_moves_not_configured', 'Account moves are not configured on this instance.');
		}

		$target = $request->json()['target_actor_id'] ?? null;
		if (!is_string($target)) {
			throw HttpException::validationFailed(['target_actor_id' => 'target_actor_id must be an ActivityPub actor URL.']);
		}

		try {
			$move = $this->moves->moveLocalAccount($userId, $target);
		} catch (AccountMoveException $exception) {
			$status = $exception->reason === 'move_already_started' ? 409 : 422;
			throw new HttpException($status, $exception->reason, $exception->getMessage());
		} catch (Throwable $exception) {
			throw new HttpException(502, 'account_move_failed', 'The Move could not be queued. Retry the same target.');
		}

		return Response::json(['move' => self::summary($move)], 202);
	}

	/** @return array<string, mixed> */
	private static function summary(AccountMove $move): array
	{
		return [
			'event_id' => $move->eventId,
			'target_actor_id' => $move->targetActorId,
			'direction' => $move->direction->value,
			'state' => $move->state->value,
			'processed_outgoing' => count($move->processedOutgoingIds),
			'processed_followers' => count($move->processedFollowerIds),
			'created_at' => Time::format($move->createdAt),
			'updated_at' => Time::format($move->updatedAt),
		];
	}
}
