<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Domain\Post\PostNotFoundException;
use AlohaSocial\Core\Http\Handler;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Service\ConversationService;

/** `POST /api/v1/conversations/{id}/dismiss`. */
final class DismissConversationHandler implements Handler
{
	public function __construct(private readonly ConversationService $conversations)
	{
	}

	public function handle(Request $request): Response
	{
		$userId = $request->userId;
		if ($userId === null) {
			throw HttpException::unauthorized();
		}

		try {
			$rootId = $this->conversations->dismiss($userId, (string) $request->attribute('id', ''));
		} catch (PostNotFoundException) {
			throw HttpException::notFound('Conversation not found.');
		} catch (DatabaseException) {
			throw new HttpException(503, 'conversation_unavailable', 'Conversation state is not available.');
		}

		return Response::json(['conversation_id' => $rootId, 'dismissed' => true]);
	}
}
