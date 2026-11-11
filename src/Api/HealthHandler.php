<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Http\Handler;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;

/**
 * Liveness probe for a load balancer or a container health check.
 *
 * It answers without touching the database on purpose: a probe that needs
 * the database turns a slow database into a restart loop.
 */
final class HealthHandler implements Handler
{
	public function handle(Request $request): Response
	{
		return Response::json(['status' => 'ok']);
	}
}
