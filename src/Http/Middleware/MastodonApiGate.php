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
use AlohaSocial\Core\Service\Mastodon\MastodonService;

/**
 * Makes the whole compatibility surface disappear when its switch is off.
 *
 * This gate runs before authentication and scope checks: a disabled feature
 * must answer the same 404 whether or not the caller supplied a token. The
 * handlers still check the switch so a direct handler call cannot bypass it.
 */
final class MastodonApiGate implements Middleware
{
	public function __construct(
		private readonly MastodonService $mastodon,
	) {
	}

	public function process(Request $request, callable $next): Response
	{
		$route = $request->attribute('route');
		if ($route instanceof Route && str_starts_with($route->name, 'mastodon.') && !$this->mastodon->isEnabled()) {
			throw new HttpException(
				404,
				'mastodon_api_disabled',
				'The Mastodon-compatible API is disabled on this instance.',
			);
		}

		return $next($request);
	}
}
