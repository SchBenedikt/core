<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Http;

/**
 * Cross-cutting behaviour around a handler.
 *
 * `$next` continues the pipeline and returns the response; middleware may
 * change the request on the way in and the response on the way out.
 */
interface Middleware
{
	/**
	 * @param callable(Request): Response $next
	 */
	public function process(Request $request, callable $next): Response;
}
