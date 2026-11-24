<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Timeline;

/**
 * Keeps a timeline page from showing the same object as a post and a boost,
 * or through several boosts. Non-boost entries are never collapsed.
 */
final class TimelineEntryDeduplicator
{
	/**
	 * @param list<TimelineEntry> $entries
	 *
	 * @return list<TimelineEntry>
	 */
	public static function deduplicateBoosts(array $entries): array
	{
		$originals = [];
		foreach ($entries as $entry) {
			if (!$entry->isBoost()) {
				$originals[$entry->objectId] = true;
			}
		}

		$page = [];
		$boostedObjects = [];
		foreach ($entries as $entry) {
			if (!$entry->isBoost()) {
				$page[] = $entry;
				continue;
			}

			if (isset($originals[$entry->objectId]) || isset($boostedObjects[$entry->objectId])) {
				continue;
			}
			$boostedObjects[$entry->objectId] = true;
			$page[] = $entry;
		}

		return $page;
	}
}
