<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Mastodon;

use AlohaSocial\Core\Domain\Federation\LocalActorDirectory;
use AlohaSocial\Core\Domain\Media\Attachment;
use AlohaSocial\Core\Domain\Notification\Notification;
use AlohaSocial\Core\Domain\Post\FavouriteRepository;
use AlohaSocial\Core\Domain\Post\Poll;
use AlohaSocial\Core\Domain\Post\PollOption;
use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Exceptions\ConfigurationException;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;
use AlohaSocial\Core\Service\InstanceInfo;
use AlohaSocial\Core\Service\LocalRelationshipService;
use AlohaSocial\Core\Service\MediaService;
use DateTimeImmutable;

/**
 * Maps core posts and accounts to Mastodon API shapes.
 *
 * This is a read-side adapter only: it never creates a second post model. The
 * account is intentionally minimal because user profiles belong to the host;
 * the id, handle, URL and counts are the fields a Mastodon client needs to
 * render a status.
 */
final class MastodonPresenter
{
	public function __construct(
		private readonly InstanceInfo $instance,
		private readonly ActivityFactory $activities,
		private readonly ?MediaService $media = null,
		private readonly ?FavouriteRepository $favourites = null,
		private readonly ?LocalRelationshipService $relationships = null,
		private readonly ?LocalActorDirectory $localActors = null,
		private readonly ?Clock $clock = null,
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function account(string $userId): array
	{
		$url = $this->baseUrl() . '/@' . rawurlencode($userId);

		return $this->accountShape(
			$userId,
			$userId,
			$userId,
			$url,
			$this->relationships?->followersCount($userId) ?? 0,
			$this->relationships?->followingCount($userId) ?? 0,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function notification(Notification $notification, ?Post $status = null): array
	{
		$result = [
			'id' => $notification->id,
			'type' => $notification->type->value,
			'created_at' => $notification->createdAt->format('Y-m-d\TH:i:s.000\Z'),
			'account' => $this->notificationAccount($notification->actorId),
		];

		if ($status !== null) {
			$result['status'] = $this->status($status, $notification->recipientId);
		}

		return $result;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function status(
		Post $post,
		?string $viewerId = null,
		?int $favouritesCount = null,
		?bool $favourited = null,
	): array {
		$attachments = [];
		$presented = $this->media === null ? [] : ($this->media->presentPost($post)['attachments'] ?? []);
		foreach ($presented as $attachment) {
			if (!is_array($attachment)) {
				continue;
			}

			$attachments[] = $this->mediaAttachment($attachment);
		}

		$url = $this->baseUrl() . '/api/v1/posts/' . rawurlencode($post->id);

		return [
			'id' => $post->id,
			'created_at' => $post->publishedAt->format('Y-m-d\TH:i:s.000\Z'),
			'in_reply_to_id' => null,
			'in_reply_to_account_id' => null,
			'sensitive' => $post->contentWarning !== null,
			'spoiler_text' => $post->contentWarning ?? '',
			'visibility' => $this->visibility($post->visibility),
			'language' => null,
			'uri' => $url,
			'url' => $url,
			'replies_count' => 0,
			'reblogs_count' => 0,
			'favourites_count' => $favouritesCount ?? ($this->favourites?->countForPost($post->id) ?? 0),
			'edited_at' => null,
			'content' => $this->html($post->content),
			'reblog' => null,
			'application' => null,
			'account' => $this->account($post->authorId),
			'media_attachments' => $attachments,
			'mentions' => [],
			'tags' => [],
			'emojis' => [],
			'card' => null,
			'poll' => $this->poll($post),
			'favourited' => $favourited
				?? ($viewerId !== null && ($this->favourites?->exists($viewerId, $post->id) ?? false)),
			'reblogged' => false,
			'muted' => false,
			'bookmarked' => false,
			'pinned' => false,
		];
	}

	/**
	 * The Mastodon poll entity, or null for a status without a poll.
	 *
	 * The poll id is derived from the post id, so a page can be rendered
	 * without a second id table and the id stays stable across retries.
	 *
	 * @return array<string, mixed>|null
	 */
	private function poll(Post $post): ?array
	{
		$poll = $post->poll;
		if ($poll === null) {
			return null;
		}

		$now = $this->clock?->now() ?? new DateTimeImmutable('now');

		return [
			'id' => 'poll-' . $post->id,
			'expires_at' => $poll->expiresAt->format('Y-m-d\TH:i:s.000\Z'),
			'expired' => $poll->isExpired($now),
			'multiple' => $poll->multiple,
			'votes_count' => $poll->votesCount,
			'voters_count' => $poll->votersCount,
			'voted' => false,
			'own_votes' => [],
			'options' => array_map(
				static fn (PollOption $option): array => $option->toArray(),
				$poll->options,
			),
			'emojis' => [],
		];
	}

	/**
	 * @param list<Post> $posts
	 *
	 * @return list<array<string, mixed>>
	 */
	public function statuses(array $posts, ?string $viewerId = null): array
	{
		$postIds = array_map(static fn (Post $post): string => $post->id, $posts);
		$counts = $this->favourites?->countsForPosts($postIds) ?? [];
		$favourited = $viewerId === null
			? []
			: ($this->favourites?->existsForPosts($viewerId, $postIds) ?? []);

		return array_map(
			fn (Post $post): array => $this->status(
				$post,
				$viewerId,
				$counts[$post->id] ?? 0,
				$favourited[$post->id] ?? false,
			),
			$posts,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function notificationAccount(string $actorId): array
	{
		if ($this->localActors?->has($actorId) === true) {
			return $this->account($actorId);
		}

		$parts = parse_url($actorId);
		$host = is_array($parts) && isset($parts['host']) ? (string) $parts['host'] : '';
		$path = is_array($parts) && isset($parts['path']) ? (string) $parts['path'] : '';
		$username = $path === '' ? $host : basename($path);
		$username = ltrim(rawurldecode($username), '@');
		if ($username === '') {
			$username = $actorId;
		}

		return $this->accountShape(
			$actorId,
			$username,
			$host === '' ? $username : $username . '@' . $host,
			$actorId,
			0,
			0,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function accountShape(
		string $id,
		string $username,
		string $acct,
		string $url,
		int $followersCount,
		int $followingCount,
	): array {
		return [
			'id' => $id,
			'username' => $username,
			'acct' => $acct,
			'display_name' => $username,
			'locked' => false,
			'bot' => false,
			'discoverable' => true,
			'group' => false,
			'created_at' => '1970-01-01T00:00:00.000Z',
			'note' => '',
			'url' => $url,
			'avatar' => '',
			'avatar_static' => '',
			'header' => '',
			'header_static' => '',
			'followers_count' => $followersCount,
			'following_count' => $followingCount,
			'statuses_count' => 0,
			'last_status_at' => null,
			'noindex' => false,
			'emojis' => [],
			'fields' => [],
		];
	}

	public function instance(): array
	{
		$description = $this->instance->describe();
		$base = $this->baseUrl();
		$host = parse_url($base, PHP_URL_HOST);

		return [
			'uri' => is_string($host) && $host !== '' ? $host : $base,
			'title' => $description['name'],
			'short_description' => $description['description'],
			'description' => $description['description'],
			'email' => '',
			'version' => '4.2.0 (compatible; Aloha Social ' . $description['version'] . ')',
			'urls' => ['streaming_api' => ''],
			'stats' => [
				'user_count' => 0,
				'status_count' => 0,
				'domain_count' => 0,
			],
			'thumbnail' => '',
			'languages' => [],
			'registrations' => false,
			'approval_required' => true,
			'invites_enabled' => false,
			'configuration' => [
				'statuses' => [
					'max_characters' => 5000,
					'max_media_attachments' => Post::MAX_ATTACHMENTS,
					'characters_reserved_per_url' => 23,
				],
				'media_attachments' => [
					'supported_mime_types' => Attachment::ALLOWED_MIME_TYPES,
					'image_size_limit' => Attachment::MAX_BYTES,
					'image_matrix_limit' => Attachment::MAX_PIXELS,
				],
				'polls' => [
					'max_options' => Poll::MAX_OPTIONS,
					'max_characters_per_option' => PollOption::MAX_TITLE_LENGTH,
					'min_expiration' => Poll::MIN_EXPIRY_SECONDS,
					'max_expiration' => Poll::MAX_EXPIRY_SECONDS,
				],
			],
			'contact_account' => null,
			'rules' => [],
		];
	}

	/**
	 * @param array<string, mixed> $attachment a presented attachment from MediaService
	 *
	 * @return array<string, mixed>
	 */
	public function media(array $attachment): array
	{
		return $this->mediaAttachment($attachment);
	}

	/**
	 * @param array<string, mixed> $attachment
	 *
	 * @return array<string, mixed>
	 */
	private function mediaAttachment(array $attachment): array
	{
		$width = (int) ($attachment['width'] ?? 0);
		$height = (int) ($attachment['height'] ?? 0);

		return [
			'id' => (string) ($attachment['id'] ?? ''),
			'type' => 'image',
			'url' => (string) ($attachment['url'] ?? ''),
			'preview_url' => (string) ($attachment['thumbnail_url'] ?? ''),
			'remote_url' => null,
			'preview_remote_url' => null,
			'text_url' => null,
			'meta' => [
				'original' => $this->mediaMeta($width, $height),
				'small' => $this->mediaMeta($width, $height),
			],
			'description' => $attachment['alt_text'] ?? null,
			'blurhash' => null,
		];
	}

	/**
	 * @return array{width: int, height: int, size: string, aspect: float}
	 */
	private function mediaMeta(int $width, int $height): array
	{
		return [
			'width' => $width,
			'height' => $height,
			'size' => $width . 'x' . $height,
			'aspect' => $height > 0 ? $width / $height : 1.0,
		];
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

	private function html(string $content): string
	{
		$escaped = htmlspecialchars(
			str_replace(["\r\n", "\r"], "\n", $content),
			ENT_QUOTES | ENT_SUBSTITUTE,
			'UTF-8',
		);

		return '<p>' . nl2br($escaped, false) . '</p>';
	}

	public function baseUrl(): string
	{
		try {
			return $this->activities->baseUrl();
		} catch (ConfigurationException) {
			return '';
		}
	}
}
