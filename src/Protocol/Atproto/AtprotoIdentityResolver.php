<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\Atproto;

/**
 * Resolves a Bluesky handle to a DID for a rich-text mention facet.
 *
 * The PDS of the publishing account answers `com.atproto.identity.resolveHandle`
 * for handles it knows. A resolver returns `null` instead of throwing when the
 * handle is unknown or the PDS is unreachable: the record builder turns that
 * into a refusal, because a mention facet without a DID is worse than no post.
 */
interface AtprotoIdentityResolver
{
	/**
	 * @return string|null the DID, or `null` when the handle cannot be resolved
	 */
	public function resolveHandle(string $pdsUrl, string $handle): ?string;
}
