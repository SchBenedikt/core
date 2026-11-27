<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Recommendation;

use AlohaSocial\Core\Domain\Federation\RemotePostSchema;
use AlohaSocial\Core\Domain\Post\PostSchema;
use AlohaSocial\Core\Domain\Recommendation\PdoRecommendationCandidateSource;
use AlohaSocial\Core\Platform\Db\PdoConnection;
use AlohaSocial\Core\Support\Time;
use PDO;
use PHPUnit\Framework\TestCase;

final class PdoRecommendationCandidateSourceTest extends TestCase
{
	public function testOnlyLivePublicLocalAndCachedRemotePostsBecomeCandidates(): void
	{
		$db = new PdoConnection(new PDO('sqlite::memory:'));
		PostSchema::create($db);
		RemotePostSchema::create($db);
		$at = '2026-11-15T12:00:00Z';

		$db->execute(
			"INSERT INTO social_post (id, author_id, content, visibility, published_at) VALUES ('public', 'alice', 'visible #php', 'public', :at)",
			['at' => $at],
		);
		$db->execute(
			"INSERT INTO social_post (id, author_id, content, visibility, published_at) VALUES ('unlisted', 'alice', 'link only', 'unlisted', :at)",
			['at' => $at],
		);
		$db->execute(
			"INSERT INTO social_post (id, author_id, content, visibility, published_at, deleted_at) VALUES ('deleted', 'alice', 'gone', 'public', :at, :at)",
			['at' => $at],
		);
		$db->execute(
			"INSERT INTO social_post (id, author_id, content, visibility, published_at, archived_at) VALUES ('archived', 'alice', 'hidden', 'public', :at, :at)",
			['at' => $at],
		);
		$db->execute(
			"INSERT INTO social_remote_post (activity_id, object_id, actor_id, object_author_id, kind, content, published_at, audience) VALUES ('https://remote/activities/1', 'https://remote/objects/1', 'https://remote/actors/bob', 'https://remote/actors/bob', 'post', 'remote', :at, 'public')",
			['at' => $at],
		);
		$db->execute(
			"INSERT INTO social_remote_post (activity_id, object_id, actor_id, kind, content, published_at, audience) VALUES ('https://remote/activities/2', 'https://remote/objects/2', 'https://remote/actors/bob', 'boost', 'boost', :at, 'public')",
			['at' => $at],
		);
		$db->execute(
			"INSERT INTO social_remote_post (activity_id, object_id, actor_id, kind, content, published_at, audience) VALUES ('https://remote/activities/3', 'https://remote/objects/3', 'https://remote/actors/bob', 'post', 'followers only', :at, 'followers')",
			['at' => $at],
		);
		$db->execute(
			"INSERT INTO social_remote_post (activity_id, object_id, actor_id, kind, content, published_at) VALUES ('https://remote/activities/4', 'https://remote/objects/4', 'https://remote/actors/bob', 'post', 'unknown audience', :at)",
			['at' => $at],
		);

		$candidates = (new PdoRecommendationCandidateSource($db))->candidates('alice', 20, Time::parse($at));

		$ids = array_map(static fn ($candidate): string => $candidate->id, $candidates);
		self::assertContains('local:public', $ids);
		self::assertContains('remote:https://remote/activities/1', $ids);
		self::assertNotContains('local:unlisted', $ids);
		self::assertNotContains('local:deleted', $ids);
		self::assertNotContains('local:archived', $ids);
		self::assertNotContains('remote:https://remote/activities/2', $ids);
		self::assertNotContains('remote:https://remote/activities/3', $ids);
		self::assertNotContains('remote:https://remote/activities/4', $ids);
		self::assertSame('https://remote/actors/bob', $candidates[0]->authorId);
	}
}
