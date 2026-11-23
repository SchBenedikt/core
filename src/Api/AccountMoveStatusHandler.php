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
use AlohaSocial\Core\Service\AccountMoveService;
use AlohaSocial\Core\Support\Time;

use function count;

/** Returns only the signed-in account owner's outbound move status. */
final class AccountMoveStatusHandler implements Handler
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

		$move = $this->moves->statusForLocalAccount($userId);

		return Response::json(['move' => $move === null ? null : self::summary($move)]);
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
