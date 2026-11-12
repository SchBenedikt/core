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
use AlohaSocial\Core\Service\Federation\RemoteThreadException;
use AlohaSocial\Core\Service\Federation\RemoteThreadNotFoundException;
use AlohaSocial\Core\Service\Federation\RemoteThreadService;

/**
 * `GET /api/v1/remote-posts/{id}/thread`
 *
 * `id` is the remote activity id and therefore contains a URL; clients must
 * percent-encode it as one path segment. The target must belong to an actor
 * the caller follows and does not block.
 */
final class ShowRemoteThreadHandler implements Handler
{
	public function __construct(
		private readonly ?RemoteThreadService $threads,
	) {
	}

	public function handle(Request $request): Response
	{
		if ($this->threads === null) {
			throw new HttpException(503, 'thread_not_configured', 'Remote thread fetching is not configured.');
		}

		$userId = $request->userId;
		if ($userId === null) {
			throw HttpException::unauthorized();
		}

		$id = trim((string) $request->attribute('id', ''));
		if ($id === '') {
			throw HttpException::notFound('Remote post not found.');
		}

		try {
			$thread = $this->threads->threadForUser($id, $userId);
		} catch (RemoteThreadNotFoundException) {
			throw HttpException::notFound('Remote post not found.');
		} catch (RemoteThreadException $exception) {
			if ($exception->reason === 'thread_disabled') {
				throw new HttpException(503, 'thread_fetch_disabled', 'Remote thread fetching is disabled.');
			}

			throw new HttpException(502, 'thread_fetch_failed', 'The remote thread could not be fetched.');
		}

		return Response::json(['thread' => $thread->toArray()]);
	}
}
