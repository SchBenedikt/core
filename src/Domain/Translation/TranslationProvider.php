<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Translation;

/**
 * Host-provided translation backend for the Mastodon-compatible translate
 * endpoints.
 *
 * A host supplies an implementation only when the operator opted in to
 * translation; with no provider the endpoints report themselves unavailable
 * instead of silently proxying text to an external service. Implementations
 * must bound their own network use and never receive content the caller has
 * not already been authorized to read — the service performs that check
 * before calling {@see translate()}.
 */
interface TranslationProvider
{
	/**
	 * Languages the provider can translate into, as Mastodon advertises them.
	 *
	 * @return list<array{code: string, name: string}>
	 */
	public function languages(): array;

	/**
	 * Translates `$text` into `$targetLanguage`, or returns null when the pair
	 * is unsupported or the provider failed. `$sourceLanguage` is the post's
	 * declared language tag, when known.
	 */
	public function translate(string $text, ?string $sourceLanguage, string $targetLanguage): ?string;
}
