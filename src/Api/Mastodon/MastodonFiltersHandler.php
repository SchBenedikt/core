<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api\Mastodon;

use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;

/**
 * `GET {mastodon.prefix}/filters`
 */
final class MastodonFiltersHandler extends MastodonFilterHandler
{
	public function handle(Request $request): Response
	{
		return $this->respond(fn (): array => $this->presenter->presentMany(
			$this->filters->forUser((string) $request->userId),
		));
	}
}
