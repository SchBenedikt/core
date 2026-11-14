<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Migration;

/**
 * Neutral read-model snapshots for the shadow phase.
 *
 * The rows are what a host's read path returns, nothing more: the same fixture
 * is used for the old stack, the imported core and the rollback read, so a
 * changed actor, note or activity id shows up as a diff instead of being hidden
 * behind host-specific SQL. The fixtures are PHP arrays on purpose so they
 * carry the repository's SPDX header; a JSON file would need a REUSE entry and
 * would tempt an editor into reformatting the compared values.
 */
final class ShadowFixtures
{
	/**
	 * @return list<array<string, mixed>>
	 */
	public static function posts(): array
	{
		return [
			[
				'id' => 'post-1',
				'actor_id' => 'https://social.example/@alice',
				'note_url' => 'https://social.example/api/v1/posts/post-1',
				'activity_id' => 'https://social.example/api/v1/posts/post-1/activity',
				'content' => 'Hello from the old instance',
				'published_at' => '2026-11-11T10:00:00Z',
				'attachment_urls' => ['https://social.example/api/v1/media/att-1'],
			],
			[
				'id' => 'post-2',
				'actor_id' => 'https://social.example/@alice',
				'note_url' => 'https://social.example/api/v1/posts/post-2',
				'activity_id' => 'https://social.example/api/v1/posts/post-2/activity',
				'content' => 'A second post',
				'published_at' => '2026-11-11T10:05:00Z',
				'attachment_urls' => [],
			],
		];
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public static function remotePosts(): array
	{
		return [
			[
				'id' => 'https://remote.example/notes/9',
				'actor_id' => 'https://remote.example/users/bob',
				'object_url' => 'https://remote.example/notes/9',
				'activity_id' => 'https://remote.example/activities/create-9',
			],
		];
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public static function following(): array
	{
		return [
			[
				'id' => 'follow-1',
				'actor_id' => 'https://remote.example/users/bob',
				'follow_id' => 'https://social.example/api/v1/following/follow-1/follow',
				'undo_id' => 'https://social.example/api/v1/following/follow-1/follow/undo',
			],
		];
	}

	/**
	 * The same rows after a rollback to the old read path.
	 *
	 * Identity is preserved because it is derived from the preserved ids; this
	 * fixture is what proves the rollback does not mint new URLs.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function rolledBackPosts(): array
	{
		return self::posts();
	}

	/**
	 * A rollback that regenerated the actor identity, which peers would see as
	 * a different account even though the post ids survived.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function tamperedRollbackPosts(): array
	{
		$posts = self::posts();
		$posts[0]['actor_id'] = 'https://social.example/users/alice';
		$posts[0]['note_url'] = 'https://social.example/api/v1/posts/post-1-reimported';

		return $posts;
	}
}
