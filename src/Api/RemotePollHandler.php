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
use AlohaSocial\Core\Service\Federation\RemotePollException;
use AlohaSocial\Core\Service\Federation\RemotePollService;

/**
 * `GET /api/v1/remote-polls/{id}`
 *
 * `id` is the remote question URL, percent-encoded as one path segment.
 */
final class RemotePollHandler implements Handler
{
	public function __construct(
		private readonly ?RemotePollService $polls,
	) {
	}

	public function handle(Request $request): Response
	{
		if ($this->polls === null) {
			throw new HttpException(503, 'polls_not_configured', 'Remote poll storage is not configured.');
		}

		try {
			return Response::json([
				'poll' => $this->polls->present(
					(string) $request->attribute('id', ''),
					$request->userId,
				),
			]);
		} catch (RemotePollException $exception) {
			throw new HttpException($exception->status, $exception->reason, $exception->getMessage());
		}
	}
}
