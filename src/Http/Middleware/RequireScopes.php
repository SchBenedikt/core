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
use AlohaSocial\Core\Service\Mastodon\MastodonScope;

/**
 * Enforces the scopes a route declares.
 *
 * The host authenticates the token and puts its scopes on the request. The
 * core does not issue tokens; this middleware only makes a token with too few
 * scopes fail with the stable `insufficient_scope` code.
 */
final class RequireScopes implements Middleware
{
	public function process(Request $request, callable $next): Response
	{
		$route = $request->attribute('route');
		if (!$route instanceof Route || $route->requiredScopes === []) {
			return $next($request);
		}

		if (!MastodonScope::allowsAll($request->scopes, $route->requiredScopes)) {
			throw new HttpException(
				403,
				'insufficient_scope',
				'The token does not grant the scopes required by this route.',
			);
		}

		return $next($request);
	}
}
