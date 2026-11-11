<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Http;

/**
 * Turns a handler class name from the manifest into a handler instance.
 *
 * Each host supplies its own: the standalone host uses its compiled
 * container, the Nextcloud host its DI container.
 */
interface HandlerResolver
{
	/**
	 * @param class-string<Handler> $handler
	 */
	public function resolve(string $handler): Handler;
}
