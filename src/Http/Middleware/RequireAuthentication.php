<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Http\Middleware;

use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Middleware;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Http\Route;

/**
 * Refuses an unauthenticated request to a route that requires a user.
 *
 * The host authenticates and puts the user id on the request; this
 * middleware only enforces the contract's `requires_auth` flag.
 */
final class RequireAuthentication implements Middleware
{
	public function process(Request $request, callable $next): Response
	{
		$route = $request->attribute('route');

		if ($route instanceof Route && $route->requiresAuth && $request->userId === null) {
			throw HttpException::unauthorized();
		}

		return $next($request);
	}
}
