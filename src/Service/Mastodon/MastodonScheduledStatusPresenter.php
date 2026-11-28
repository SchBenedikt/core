<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Mastodon;

use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Domain\Scheduling\ScheduledPost;
use AlohaSocial\Core\Support\Time;

/**
 * The Mastodon `ScheduledStatus` shape.
 *
 * Mastodon keeps the write parameters nested under `params` and does not
 * return a status id, because nothing has been published. The reserved core
 * post id stays internal for the same reason.
 */
final class MastodonScheduledStatusPresenter
{
	/**
	 * @return array<string, mixed>
	 */
	public function present(ScheduledPost $scheduled): array
	{
		return [
			'id' => $scheduled->id,
			'scheduled_at' => Time::format($scheduled->scheduledAt),
			'params' => [
				'text' => $scheduled->content,
				'media_ids' => $scheduled->attachmentIds,
				'sensitive' => $scheduled->contentWarning !== null,
				'visibility' => $this->visibility($scheduled->visibility),
				'spoiler_text' => $scheduled->contentWarning ?? '',
				'poll' => $scheduled->poll?->toArray(),
				'in_reply_to_id' => $scheduled->inReplyToId,
			],
			'media_attachments' => [],
		];
	}

	/**
	 * @param list<ScheduledPost> $scheduled
	 *
	 * @return list<array<string, mixed>>
	 */
	public function presentMany(array $scheduled): array
	{
		return array_map(fn (ScheduledPost $row): array => $this->present($row), $scheduled);
	}

	private function visibility(Visibility $visibility): string
	{
		return match ($visibility) {
			Visibility::Public => 'public',
			Visibility::Unlisted => 'unlisted',
			Visibility::Followers => 'private',
			Visibility::Direct => 'direct',
		};
	}
}
