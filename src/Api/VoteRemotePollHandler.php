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
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Service\Federation\RemotePollException;
use AlohaSocial\Core\Service\Federation\RemotePollService;

use function array_is_list;
use function is_array;
use function is_int;

/**
 * `POST /api/v1/remote-polls/{id}/votes` with `{"choices": [0, 2]}`
 *
 * Choices are option positions in the stored order. The local vote is stored
 * optimistically and one signed Note per choice is queued for the author's
 * inbox; `delivery` in the answer is `queued` or `failed`.
 */
final class VoteRemotePollHandler implements Handler
{
	public function __construct(
		private readonly ?RemotePollService $polls,
		private readonly Clock $clock,
	) {
	}

	public function handle(Request $request): Response
	{
		$userId = $request->userId;
		if ($userId === null) {
			throw HttpException::unauthorized();
		}

		if ($this->polls === null) {
			throw new HttpException(503, 'polls_not_configured', 'Remote poll storage is not configured.');
		}

		$choices = $request->json()['choices'] ?? null;
		if (!is_array($choices) || !array_is_list($choices)) {
			throw HttpException::validationFailed(['choices' => 'choices must be a list of option positions.']);
		}

		foreach ($choices as $choice) {
			if (!is_int($choice)) {
				throw HttpException::validationFailed(['choices' => 'choices must be a list of option positions.']);
			}
		}

		try {
			return Response::json([
				'poll' => $this->polls->vote(
					$userId,
					(string) $request->attribute('id', ''),
					$choices,
					$this->clock->now(),
				),
			]);
		} catch (RemotePollException $exception) {
			throw new HttpException($exception->status, $exception->reason, $exception->getMessage());
		}
	}
}
