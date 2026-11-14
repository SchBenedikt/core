<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Domain\Filter\FilterAction;
use AlohaSocial\Core\Domain\Filter\FilterContext;
use AlohaSocial\Core\Domain\Post\PostRepository;
use AlohaSocial\Core\Http\Handler;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Service\FilterService;
use AlohaSocial\Core\Service\LocalAccountService;
use AlohaSocial\Core\Service\ValidationException;

use function is_array;
use function is_string;
use function max;

/**
 * `GET /api/v1/notifications?limit=&before=`
 *
 * One page of the caller's unread notifications, newest first, plus the
 * current unread count. The cursor is a notification id owned by the caller,
 * so a page cannot probe another account's events.
 *
 * Keyword rules run here too: a hidden notification is removed and a warned
 * one carries its `filtered` evidence. The count is reduced by the hidden
 * unread rows on the returned page — the handler only sees that page, so the
 * adjustment is bounded by it rather than scanning the whole inbox.
 */
final class NotificationsHandler implements Handler
{
	public function __construct(
		private readonly LocalAccountService $accounts,
		private readonly ?FilterService $filters = null,
		private readonly ?PostRepository $posts = null,
	) {
	}

	public function handle(Request $request): Response
	{
		$userId = $request->userId;
		if ($userId === null) {
			throw HttpException::unauthorized();
		}

		$limit = $request->queryInt(
			'limit',
			LocalAccountService::DEFAULT_NOTIFICATION_LIMIT,
			1,
			LocalAccountService::MAX_NOTIFICATION_LIMIT,
		);
		$before = $request->queryParam('before');

		try {
			$page = $this->accounts->notifications(
				$userId,
				min(LocalAccountService::MAX_NOTIFICATION_LIMIT, $limit * 2),
				$before,
			);
		} catch (ValidationException $exception) {
			throw HttpException::validationFailed($exception->fields(), $exception->getMessage());
		} catch (DatabaseException) {
			throw new HttpException(503, 'notifications_unavailable', 'Notification storage is not available.');
		}

		if ($this->filters !== null && $this->posts !== null) {
			$kept = [];
			$hiddenUnread = 0;
			foreach ($page['notifications'] as $notification) {
				$postId = $notification['post_id'] ?? null;
				$match = null;
				if (is_string($postId)) {
					$post = $this->posts->find($postId);
					if ($post !== null && !$post->isDeleted()) {
						$match = $this->filters->matchPost($userId, FilterContext::Notifications, $post);
					}
				}

				if ($match?->action === FilterAction::Hide) {
					if (($notification['read'] ?? false) !== true) {
						$hiddenUnread++;
					}

					continue;
				}

				if ($match !== null) {
					$notification['filtered'] = [$this->filters->filterBlock($match)];
				}

				$kept[] = $notification;
			}

			$page['notifications'] = $kept;
			$page['unread_count'] = max(0, ($page['unread_count'] ?? 0) - $hiddenUnread);
		}

		$page['notifications'] = array_slice($page['notifications'], 0, $limit);

		return Response::json($page);
	}
}
