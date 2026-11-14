<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\ActivityPub;

use AlohaSocial\Core\Domain\Federation\Block;
use AlohaSocial\Core\Domain\Federation\FollowedActor;
use AlohaSocial\Core\Domain\Media\AttachmentPresenter;
use AlohaSocial\Core\Domain\Post\Boost;
use AlohaSocial\Core\Domain\Federation\RemotePoll;
use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Exceptions\ConfigurationException;
use AlohaSocial\Core\Platform\Config\Config;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;

use function hash;
use function is_string;
use function substr;

/**
 * Builds the ActivityStreams documents this instance sends.
 *
 * The ids are derived from the instance URL and the post id, so the same post
 * always produces the same document id — which is what makes a retried
 * delivery idempotent for the receiving server.
 */
final class ActivityFactory
{
	public const CONTEXT = 'https://www.w3.org/ns/activitystreams';

	public const PUBLIC_COLLECTION = 'https://www.w3.org/ns/activitystreams#Public';

	public const CONFIG_APP = 'social';

	public const CONFIG_URL = 'instance.url';

	public function __construct(
		private readonly Config $config,
		private readonly ?AttachmentPresenter $attachments = null,
	) {
	}

	/**
	 * @throws ConfigurationException when the instance URL is missing or not http(s)
	 */
	public function baseUrl(): string
	{
		$url = $this->config->get(self::CONFIG_APP, self::CONFIG_URL);
		if (!is_string($url) || trim($url) === '') {
			throw new ConfigurationException(
				'The instance URL is not configured (' . self::CONFIG_APP . '/' . self::CONFIG_URL . ').',
			);
		}

		$url = rtrim(trim($url), '/');
		$scheme = parse_url($url, PHP_URL_SCHEME);
		if ($scheme !== 'https' && $scheme !== 'http') {
			throw new ConfigurationException('The instance URL must be an absolute http(s) URL: ' . $url);
		}

		return $url;
	}

	public function actorId(string $userId): string
	{
		return $this->baseUrl() . '/@' . rawurlencode($userId);
	}

	/**
	 * The server-wide inbox advertised by every local actor.
	 *
	 * One endpoint accepts content for all local accounts; the individual
	 * inbox stays the target for Follow and Undo, which are actor-specific.
	 */
	public function sharedInboxId(): string
	{
		return $this->baseUrl() . '/inbox/shared';
	}

	public function keyId(string $userId): string
	{
		return $this->actorId($userId) . '#main-key';
	}

	public function followersId(string $userId): string
	{
		return $this->actorId($userId) . '/followers';
	}

	/**
	 * The following URL the actor document advertises and the collection
	 * route serves.
	 */
	public function followingId(string $userId): string
	{
		return $this->actorId($userId) . '/following';
	}

	/**
	 * The outbox URL the actor document advertises and the outbox route
	 * serves. One function, so a peer never has to guess the id.
	 */
	public function outboxId(string $userId): string
	{
		return $this->actorId($userId) . '/outbox';
	}

	public function followId(string $followedId): string
	{
		return $this->baseUrl() . '/api/v1/following/' . rawurlencode($followedId) . '/follow';
	}

	/**
	 * @return array<string, mixed>
	 */
	public function followActor(string $localUserId, FollowedActor $followed): array
	{
		return [
			'@context' => self::CONTEXT,
			'id' => $this->followId($followed->id),
			'type' => 'Follow',
			'actor' => $this->actorId($localUserId),
			'object' => $followed->actorId,
			'to' => [$followed->actorId],
		];
	}

	/**
	 * An Undo of a Follow, embedding the original Follow so the peer can match
	 * it without storing the activity id itself.
	 *
	 * @return array<string, mixed>
	 */
	public function undoFollow(string $localUserId, FollowedActor $followed): array
	{
		$follow = $this->followActor($localUserId, $followed);

		return [
			'@context' => self::CONTEXT,
			'id' => $this->followId($followed->id) . '/undo',
			'type' => 'Undo',
			'actor' => $follow['actor'],
			'object' => $follow,
			'to' => [$followed->actorId],
		];
	}

	public function noteId(Post $post): string
	{
		return $this->noteIdFor($post->id);
	}

	public function noteIdFor(string $postId): string
	{
		return $this->baseUrl() . '/api/v1/posts/' . rawurlencode($postId);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function createNote(Post $post): array
	{
		$audience = $this->audience($post);

		return [
			'@context' => self::CONTEXT,
			'id' => $this->noteId($post) . '/activity',
			'type' => 'Create',
			'actor' => $this->actorId($post->authorId),
			'published' => Time::format($post->publishedAt),
			'to' => $audience['to'],
			'cc' => $audience['cc'],
			'object' => $this->note($post),
		];
	}

	/**
	 * An Update of the same note.
	 *
	 * The object keeps the id its Create used, and the activity id carries the
	 * revision: a retried delivery of one edit is one activity for the peer,
	 * while the next edit is a version it has not applied yet.
	 *
	 * @return array<string, mixed>
	 */
	public function updateNote(Post $post, DateTimeImmutable $at): array
	{
		$audience = $this->audience($post);

		return [
			'@context' => self::CONTEXT,
			'id' => $this->noteId($post) . '/update/' . $post->revision,
			'type' => 'Update',
			'actor' => $this->actorId($post->authorId),
			'published' => Time::format($at),
			'to' => $audience['to'],
			'cc' => $audience['cc'],
			'object' => $this->note($post),
		];
	}

	/**
	 * The note document itself, shared by Create and Update.
	 *
	 * @return array<string, mixed>
	 */
	private function note(Post $post): array
	{
		$actor = $this->actorId($post->authorId);
		$noteId = $this->noteId($post);
		$audience = $this->audience($post);

		$note = [
			'id' => $noteId,
			'type' => 'Note',
			'attributedTo' => $actor,
			// Plain text for now: the core does not render HTML, and claiming
			// text/html over an unescaped string would be a cross-server XSS.
			'content' => $post->content,
			'mediaType' => 'text/plain',
			'published' => Time::format($post->publishedAt),
			'to' => $audience['to'],
			'cc' => $audience['cc'],
			'sensitive' => $post->contentWarning !== null,
		];

		if ($post->contentWarning !== null) {
			$note['summary'] = $post->contentWarning;
		}

		if ($post->inReplyToId !== null) {
			$note['inReplyTo'] = $this->baseUrl()
				. '/api/v1/posts/' . rawurlencode($post->inReplyToId);
		}

		// The same serialized note is what delivery and the outbox send, so
		// the attachment url is built once from the media service and every
		// consumer sees the identical entry.
		$attachments = $this->attachments?->presentActivityAttachments($post) ?? [];
		if ($attachments !== []) {
			$note['attachment'] = $attachments;
		}

		return $note;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function deleteNote(Post $post, DateTimeImmutable $at): array
	{
		$audience = $this->audience($post);
		$noteId = $this->noteId($post);

		return [
			'@context' => self::CONTEXT,
			'id' => $noteId . '/delete',
			'type' => 'Delete',
			'actor' => $this->actorId($post->authorId),
			'published' => Time::format($at),
			'to' => $audience['to'],
			'cc' => $audience['cc'],
			'object' => [
				'id' => $noteId,
				'type' => 'Tombstone',
			],
		];
	}

	/**
	 * An Accept of a remote Follow.
	 *
	 * The original Follow is embedded unchanged, so the peer can match the
	 * Accept to its request without having stored the activity id itself.
	 *
	 * @param array<string, mixed> $follow
	 *
	 * @return array<string, mixed>
	 */
	public function acceptFollow(string $localUserId, string $followerId, array $follow): array
	{
		$activity = [
			'@context' => self::CONTEXT,
			'id' => $this->baseUrl() . '/api/v1/followers/' . rawurlencode($followerId) . '/accept',
			'type' => 'Accept',
			'actor' => $this->actorId($localUserId),
			'object' => $follow,
		];

		$target = $follow['actor'] ?? null;
		if (is_string($target) && $target !== '') {
			$activity['to'] = [$target];
		}

		return $activity;
	}

	/**
	 * @return array{to: list<string>, cc: list<string>}
	 */
	private function audience(Post $post): array
	{
		$followers = $this->followersId($post->authorId);

		return match ($post->visibility) {
			Visibility::Public => [
				'to' => [self::PUBLIC_COLLECTION],
				'cc' => [$followers],
			],
			Visibility::Unlisted => [
				'to' => [$followers],
				'cc' => [self::PUBLIC_COLLECTION],
			],
			Visibility::Followers => [
				'to' => [$followers],
				'cc' => [],
			],
			Visibility::Direct => [
				'to' => array_map(fn (string $recipientId): string => $this->actorId($recipientId), $post->recipientIds),
				'cc' => [],
			],
		};
	}

	/**
	 * The Like id of one local account on one object.
	 *
	 * Derived from the pair, so a retried favourite re-sends the identical
	 * activity and a later Undo can name the Like without a second id table.
	 */
	public function likeId(string $localUserId, string $objectId): string
	{
		return $this->interactionId('likes', $localUserId, $objectId, '');
	}

	/**
	 * @return array<string, mixed>
	 */
	public function like(string $localUserId, string $objectId): array
	{
		return [
			'@context' => self::CONTEXT,
			'id' => $this->likeId($localUserId, $objectId),
			'type' => 'Like',
			'actor' => $this->actorId($localUserId),
			'object' => $objectId,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function undoLike(string $localUserId, string $objectId): array
	{
		return [
			'@context' => self::CONTEXT,
			'id' => $this->likeId($localUserId, $objectId) . '/undo',
			'type' => 'Undo',
			'actor' => $this->actorId($localUserId),
			'object' => $this->like($localUserId, $objectId),
		];
	}

	public function reactionId(string $localUserId, string $objectId, string $emoji): string
	{
		return $this->interactionId('reactions', $localUserId, $objectId, $emoji);
	}

	/**
	 * A FEP-044f EmojiReact. `content` is the emoji itself; the core only
	 * sends Unicode emoji, so no custom-emoji tag is attached.
	 *
	 * @return array<string, mixed>
	 */
	public function emojiReact(string $localUserId, string $objectId, string $emoji): array
	{
		return [
			'@context' => self::CONTEXT,
			'id' => $this->reactionId($localUserId, $objectId, $emoji),
			'type' => 'EmojiReact',
			'actor' => $this->actorId($localUserId),
			'object' => $objectId,
			'content' => $emoji,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function undoEmojiReact(string $localUserId, string $objectId, string $emoji): array
	{
		return [
			'@context' => self::CONTEXT,
			'id' => $this->reactionId($localUserId, $objectId, $emoji) . '/undo',
			'type' => 'Undo',
			'actor' => $this->actorId($localUserId),
			'object' => $this->emojiReact($localUserId, $objectId, $emoji),
		];
	}

	private function interactionId(string $kind, string $localUserId, string $objectId, string $emoji): string
	{
		return $this->baseUrl() . '/api/v1/' . $kind . '/' . rawurlencode(substr(hash(
			'sha256',
			$localUserId . "\0" . $objectId . "\0" . $emoji,
		), 0, 32));
	}

	/**
	 * A signed vote on a remote Question.
	 *
	 * Mastodon sends one `Note` per selected choice, with the option title as
	 * `name` and the question URL as `inReplyTo`. The activity id is derived
	 * from the voter, the question and the choice, so retrying the same vote
	 * cannot count twice on the receiving side.
	 *
	 * @return array<string, mixed>
	 */
	public function pollVote(string $localUserId, RemotePoll $poll, int $position, DateTimeImmutable $at): array
	{
		$title = $poll->optionTitle($position);
		if ($title === null) {
			throw new InvalidArgumentException('The poll has no such option.');
		}

		$actorId = $this->actorId($localUserId);
		$digest = substr(hash('sha256', $poll->objectId . "\n" . $localUserId . "\n" . $title), 0, 32);
		$noteId = $this->baseUrl() . '/api/v1/poll-votes/' . $digest;

		return [
			'@context' => self::CONTEXT,
			'id' => $noteId . '/activity',
			'type' => 'Create',
			'actor' => $actorId,
			'published' => Time::format($at),
			'to' => [$poll->actorId],
			'object' => [
				'id' => $noteId,
				'type' => 'Note',
				'attributedTo' => $actorId,
				'inReplyTo' => $poll->objectId,
				'name' => $title,
				'to' => [$poll->actorId],
				'published' => Time::format($at),
			],
		];
	}

	public function boostId(Boost $boost): string
	{
		return $this->baseUrl() . '/api/v1/boosts/' . rawurlencode($boost->id);
	}

	/**
	 * The Announce that shares a public or unlisted post.
	 *
	 * A boost is always addressed to the public collection: boosting a post
	 * whose audience is narrower would turn the booster into a new audience
	 * member and leak it to the booster's followers.
	 *
	 * @return array<string, mixed>
	 */
	public function announce(Boost $boost): array
	{
		$actor = $this->actorId($boost->userId);

		return [
			'@context' => self::CONTEXT,
			'id' => $this->boostId($boost) . '/activity',
			'type' => 'Announce',
			'actor' => $actor,
			'published' => Time::format($boost->createdAt),
			'object' => $this->noteIdFor($boost->postId),
			'to' => [self::PUBLIC_COLLECTION],
			'cc' => [$this->followersId($boost->userId)],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function undoAnnounce(Boost $boost): array
	{
		return [
			'@context' => self::CONTEXT,
			'id' => $this->boostId($boost) . '/activity/undo',
			'type' => 'Undo',
			'actor' => $this->actorId($boost->userId),
			'object' => $this->announce($boost),
			'to' => [self::PUBLIC_COLLECTION],
			'cc' => [$this->followersId($boost->userId)],
		];
	}

	public function blockId(Block $block): string
	{
		return $this->baseUrl() . '/api/v1/blocks/' . rawurlencode($block->id) . '/block';
	}

	/**
	 * A remote actor is blocked for one local account. The object is the
	 * actor URL, not a local block id, and `to` addresses that actor so a
 * peer can match the activity to its own account.
	 *
	 * @return array<string, mixed>
	 */
	public function blockActor(Block $block): array
	{
		return [
			'@context' => self::CONTEXT,
			'id' => $this->blockId($block),
			'type' => 'Block',
			'actor' => $this->actorId($block->localUserId),
			'object' => $block->actorId,
			'to' => [$block->actorId],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function undoBlock(Block $block): array
	{
		return [
			'@context' => self::CONTEXT,
			'id' => $this->blockId($block) . '/undo',
			'type' => 'Undo',
			'actor' => $this->actorId($block->localUserId),
			'object' => $this->blockActor($block),
			'to' => [$block->actorId],
		];
	}
}
