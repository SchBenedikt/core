<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Http;

/**
 * A unit of application behaviour behind one route.
 *
 * Handlers are the only HTTP-aware part of the core: they read the request,
 * call a service, and shape the response. They hold no host state.
 */
interface Handler
{
	public function handle(Request $request): Response;
}
