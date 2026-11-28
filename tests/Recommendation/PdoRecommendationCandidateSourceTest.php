<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Recommendation;

use AlohaSocial\Core\Domain\Federation\RemotePostSchema;
use AlohaSocial\Core\Domain\Interaction\ReactionSchema;
use AlohaSocial\Core\Domain\Post\BoostSchema;
use AlohaSocial\Core\Domain\Post\FavouriteSchema;
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
		FavouriteSchema::create($db);
		BoostSchema::create($db);
		ReactionSchema::create($db);
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

	public function testLocalCandidatesCarryNormalizedLanguageAndProvenanceEngagement(): void
	{
		$db = new PdoConnection(new PDO('sqlite::memory:'));
		PostSchema::create($db);
		RemotePostSchema::create($db);
		FavouriteSchema::create($db);
		BoostSchema::create($db);
		ReactionSchema::create($db);
		$at = '2026-11-15T12:00:00Z';

		$db->execute(
			"INSERT INTO social_post (id, author_id, content, visibility, published_at, language) VALUES ('post', 'alice', 'hallo', 'public', :at, 'DE-at')",
			['at' => $at],
		);
		$db->execute(
			"INSERT INTO social_post (id, author_id, content, visibility, published_at, in_reply_to_id) VALUES ('reply', 'bob', 'antwort', 'public', :at, 'post')",
			['at' => $at],
		);
		$db->execute(
			"INSERT INTO social_post (id, author_id, content, visibility, published_at, in_reply_to_id, deleted_at) VALUES ('gone-reply', 'bob', 'gelöscht', 'public', :at, 'post', :at)",
			['at' => $at],
		);
		$db->execute(
			"INSERT INTO social_favourite (user_id, post_id, created_at) VALUES ('bob', 'post', :at)",
			['at' => $at],
		);
		$db->execute(
			"INSERT INTO social_boost (id, user_id, post_id, created_at) VALUES ('boost-1', 'bob', 'post', :at)",
			['at' => $at],
		);
		$db->execute(
			"INSERT INTO social_reaction (id, direction, subject_id, object_id, local_post_id, kind, emoji, activity_id, created_at) VALUES ('reaction-1', 'in', 'https://remote/actors/bob', 'https://remote/activities/1', 'post', 'like', '', 'https://remote/activities/1', :at)",
			['at' => $at],
		);

		$candidates = (new PdoRecommendationCandidateSource($db))->candidates('alice', 20, Time::parse($at));

		$byId = [];
		foreach ($candidates as $candidate) {
			$byId[$candidate->id] = $candidate;
		}

		$post = $byId['local:post'] ?? null;
		self::assertNotNull($post);
		self::assertSame(['de-at'], $post->languages);
		// favourite + boost + reaction + live reply; the deleted reply does not count.
		self::assertSame(4, $post->engagement);
	}
}
