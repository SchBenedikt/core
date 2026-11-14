<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api\Mastodon;

use AlohaSocial\Core\Domain\Scheduling\ScheduledPostConflictException;
use AlohaSocial\Core\Service\Mastodon\MastodonRequestException;
use AlohaSocial\Core\Domain\Scheduling\ScheduledPostNotFoundException;
use AlohaSocial\Core\Http\Handler;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Service\Mastodon\MastodonScheduledStatusPresenter;
use AlohaSocial\Core\Service\ScheduledPostService;
use AlohaSocial\Core\Service\ValidationException;

/**
 * Error mapping shared by the scheduled-status routes.
 *
 * These routes stay on the scheduled service instead of going through
 * {@see \AlohaSocial\Core\Service\Mastodon\MastodonService}: they have no
 * status to present and no federation step, and the compatibility shape is
 * just a projection of the stored schedule.
 */
abstract class MastodonScheduledHandler implements Handler
{
	public function __construct(
		protected readonly ScheduledPostService $scheduled,
		protected readonly MastodonScheduledStatusPresenter $presenter,
	) {
	}

	/**
	 * @param callable(): array<string, mixed> $work
	 */
	protected function respond(callable $work): Response
	{
		try {
			return Response::json($work());
		} catch (ScheduledPostNotFoundException) {
			throw new HttpException(404, 'not_found', 'Scheduled status not found.');
		} catch (MastodonRequestException $exception) {
			throw new HttpException($exception->status, $exception->errorCode, $exception->getMessage());
		} catch (ScheduledPostConflictException) {
			throw new HttpException(409, 'conflict', 'The scheduled status can no longer be changed.');
		} catch (ValidationException $exception) {
			$fields = [];
			foreach ($exception->fields() as $name => $message) {
				$fields[$name === 'content' ? 'status' : $name] = $message;
			}

			throw HttpException::validationFailed($fields, $exception->getMessage());
		}
	}
}
