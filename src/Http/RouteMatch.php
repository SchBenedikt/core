<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Http;

/**
 * A route that matched, with the parameters taken out of the path.
 */
final readonly class RouteMatch
{
	/**
	 * @param array<string, string> $parameters
	 */
	public function __construct(
		public Route $route,
		public array $parameters = [],
	) {
	}
}
