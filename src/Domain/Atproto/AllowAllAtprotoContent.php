<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto;

/**
 * Default policy: store and project what the source delivers.
 *
 * A host with a block list or labeler policy injects its own implementation;
 * this default exists so the importer is usable in tests and small hosts
 * without pretending that a missing policy is a moderation decision.
 */
final class AllowAllAtprotoContent implements AtprotoModerationPolicy
{
	public function allowsAuthor(string $did): bool
	{
		return true;
	}

	public function allowsLabel(AtprotoLabel $label): bool
	{
		return true;
	}
}
