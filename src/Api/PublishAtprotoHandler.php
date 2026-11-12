<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Domain\Atproto\AtprotoPublishFailedException;
use AlohaSocial\Core\Domain\Post\PostNotFoundException;
use AlohaSocial\Core\Http\Handler;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Service\Atproto\AtprotoAccountNotConnectedException;
use AlohaSocial\Core\Service\Atproto\AtprotoNotConfiguredException;
use AlohaSocial\Core\Service\Atproto\AtprotoPostRejectedException;
use AlohaSocial\Core\Service\Atproto\AtprotoPublishDisabledException;
use AlohaSocial\Core\Service\Atproto\AtprotoPublishService;

/**
 * `POST /api/v1/posts/{id}/atproto`
 *
 * An explicit, authenticated mirror of one local post. Failures are mapped
 * to stable codes so a client can distinguish configuration, account,
 * unsupported post and PDS problems.
 */
final class PublishAtprotoHandler implements Handler
{
	public function __construct(
		private readonly AtprotoPublishService $service,
	) {
	}

	public function handle(Request $request): Response
	{
		$userId = $request->userId;
		if ($userId === null) {
			throw HttpException::unauthorized();
		}

		$postId = (string) $request->attribute('id', '');

		try {
			$publication = $this->service->publish($userId, $postId);
		} catch (PostNotFoundException) {
			throw HttpException::notFound('Post not found.');
		} catch (AtprotoPublishDisabledException $exception) {
			throw new HttpException(503, 'atproto_publish_disabled', $exception->getMessage());
		} catch (AtprotoNotConfiguredException $exception) {
			throw new HttpException(503, 'atproto_not_configured', $exception->getMessage());
		} catch (AtprotoAccountNotConnectedException $exception) {
			throw new HttpException(409, 'atproto_account_not_connected', $exception->getMessage());
		} catch (AtprotoPostRejectedException $exception) {
			throw new HttpException(
				422,
				'atproto_post_unsupported',
				$exception->getMessage(),
				[],
				['reason' => $exception->reason],
			);
		} catch (AtprotoPublishFailedException) {
			throw new HttpException(502, 'atproto_publish_failed', 'The AT Protocol PDS could not publish this post.');
		}

		return Response::json(['publication' => $publication->toArray()]);
	}
}
