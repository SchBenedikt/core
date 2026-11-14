<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Domain\Federation\InboxRejectedException;
use AlohaSocial\Core\Exceptions\ConfigurationException;
use AlohaSocial\Core\Http\Handler;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Service\Federation\InboxService;

/**
 * `POST /inbox` and `POST /inbox/shared`
 *
 * The endpoints are unauthenticated in the session sense: the HTTP signature
 * is the authentication, over the actual request path. The shared inbox is not
 * a weaker route; it applies the same actor, policy and replay checks. When the
 * host did not configure an actor resolver and a signing key, both answer 503
 * instead of pretending to accept.
 */
final class InboxHandler implements Handler
{
	/**
	 * Reason to status. 404 for unknown actors and objects keeps the two
	 * indistinguishable from "not found"; 403 is reserved for a valid
	 * signature whose activity violates actor ownership or a host policy.
	 */
	private const STATUS = [
		'signature_required' => 401,
		'invalid_signature' => 401,
		'signature_expired' => 401,
		'unknown_actor' => 404,
		'unknown_object' => 404,
		'actor_mismatch' => 403,
		'blocked_actor' => 403,
		'moderation_blocked' => 403,
		'not_followed' => 403,
		'not_authorized' => 403,
		'follow_not_accepted' => 403,
		'invalid_activity' => 400,
		'invalid_emoji' => 400,
		'unsupported_activity' => 400,
		'replayed_activity' => 400,
	];

	public function __construct(
		private readonly ?InboxService $inbox,
	) {
	}

	public function handle(Request $request): Response
	{
		if ($this->inbox === null) {
			throw new HttpException(
				503,
				'inbox_not_configured',
				'Inbound federation is not configured on this instance.',
			);
		}

		// Invalid JSON is answered by Request::json() as 400 invalid_json,
		// before any signature work: there is nothing to verify yet.
		$activity = $request->json();

		try {
			$this->inbox->receive($request->body, $request->headers, $request->path, $activity);
		} catch (InboxRejectedException $exception) {
			$status = self::STATUS[$exception->reason] ?? 400;

			throw new HttpException($status, $exception->reason, $exception->getMessage());
		} catch (ConfigurationException $exception) {
			throw new HttpException(503, 'instance_not_configured', $exception->getMessage());
		}

		return Response::json(['status' => 'accepted'], 202);
	}
}
