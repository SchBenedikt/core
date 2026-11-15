<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Support;

use AlohaSocial\Core\Domain\Atproto\AtprotoBlobUrlBuilder;

final class FixedAtprotoBlobUrlBuilder implements AtprotoBlobUrlBuilder
{
	public function url(string $did, string $cid): ?string
	{
		return 'https://cdn.example/' . rawurlencode($did) . '/' . rawurlencode($cid);
	}
}
