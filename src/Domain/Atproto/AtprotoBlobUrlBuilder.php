<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto;

/**
 * Turns a blob reference into a readable URL.
 *
 * A repository record only contains a blob CID; it does not contain a public
 * URL. The host owns the PDS/AppView CDN and therefore supplies this mapping.
 * Returning `null` keeps the image reference without inventing a URL.
 */
interface AtprotoBlobUrlBuilder
{
	public function url(string $did, string $cid): ?string;
}
