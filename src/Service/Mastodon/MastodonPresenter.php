<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Mastodon;

use AlohaSocial\Core\Domain\Federation\LocalActorDirectory;
use AlohaSocial\Core\Domain\Federation\LocalActorProfile;
use AlohaSocial\Core\Domain\Federation\LocalActorProfileProvider;
use AlohaSocial\Core\Domain\Federation\RemotePost;
use AlohaSocial\Core\Domain\Media\Attachment;
use AlohaSocial\Core\Domain\Notification\Notification;
use AlohaSocial\Core\Domain\Post\BookmarkRepository;
use AlohaSocial\Core\Domain\Post\Boost;
use AlohaSocial\Core\Domain\Post\BoostRepository;
use AlohaSocial\Core\Domain\Post\FavouriteRepository;
use AlohaSocial\Core\Domain\Post\Poll;
use AlohaSocial\Core\Domain\Post\PollOption;
use AlohaSocial\Core\Domain\Post\PollVote;
use AlohaSocial\Core\Domain\Post\PollVoteRepository;
use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Post\PostPinRepository;
use AlohaSocial\Core\Domain\Post\PostRepository;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Exceptions\ConfigurationException;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;
use AlohaSocial\Core\Service\InstanceInfo;
use AlohaSocial\Core\Service\LocalRelationshipService;
use AlohaSocial\Core\Service\MediaService;
use AlohaSocial\Core\Service\MuteService;
use AlohaSocial\Core\Service\ReactionService;
use DateTimeImmutable;

/**
 * Maps core posts and accounts to Mastodon API shapes.
 *
 * This is a read-side adapter only: it never creates a second post model. The
 * account is intentionally minimal because profile detail fields belong to the
 * host; the local id, handle, URL and counts are the fields a Mastodon client
 * needs to render a status or a local profile.
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
		private readonly ?PollVoteRepository $pollVotes = null,
		private readonly ?PostRepository $posts = null,
		private readonly ?BoostRepository $boosts = null,
		private readonly ?BookmarkRepository $bookmarks = null,
		private readonly ?MuteService $mutes = null,
		private readonly ?ReactionService $reactions = null,
		private readonly ?LocalActorProfileProvider $profiles = null,
		private readonly ?PostPinRepository $pins = null,
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function account(string $userId): array
	{
		$url = $this->baseUrl() . '/@' . rawurlencode($userId);
		$profile = $this->profiles?->profile($userId);

		return $this->accountShape(
			$userId,
			$userId,
			$userId,
			$url,
			$this->relationships?->followersCount($userId) ?? 0,
			$this->relationships?->followingCount($userId) ?? 0,
			$profile,
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
			'unread' => !$notification->isRead(),
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
		?PollVote $viewerVote = null,
		bool $viewerVoteLoaded = false,
		?int $repliesCount = null,
		?int $reblogsCount = null,
		?bool $reblogged = null,
		?bool $bookmarked = null,
		?bool $muted = null,
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
		$parent = $post->inReplyToId === null ? null : $this->posts?->find($post->inReplyToId);

		return [
			'id' => $post->id,
			'created_at' => $post->publishedAt->format('Y-m-d\TH:i:s.000\Z'),
			'in_reply_to_id' => $post->inReplyToId,
			'in_reply_to_account_id' => $parent?->authorId,
			'sensitive' => $post->contentWarning !== null,
			'spoiler_text' => $post->contentWarning ?? '',
			'visibility' => $this->visibility($post->visibility),
			'language' => null,
			'uri' => $url,
			'url' => $url,
			'replies_count' => $repliesCount ?? ($this->posts?->replyCount($post->id) ?? 0),
			'reblogs_count' => $reblogsCount ?? ($this->boosts?->countForPost($post->id) ?? 0),
			'favourites_count' => $favouritesCount
				?? ($this->reactions?->favouritesCountFor($post) ?? ($this->favourites?->countForPost($post->id) ?? 0)),
			'edited_at' => $post->editedAt?->format('Y-m-d\TH:i:s.000\Z'),
			'content' => $this->html($post->content),
			'reblog' => null,
			'application' => null,
			'account' => $this->account($post->authorId),
			'media_attachments' => $attachments,
			'mentions' => $this->mentions($post),
			'tags' => [],
			'emojis' => [],
			'card' => null,
			'poll' => $this->poll($post, $viewerId, $viewerVote, $viewerVoteLoaded),
			'quote' => $this->quote($post, $viewerId),
			'favourited' => $favourited
				?? ($viewerId !== null && ($this->favourites?->exists($viewerId, $post->id) ?? false)),
			'reblogged' => $reblogged
				?? ($viewerId !== null && ($this->boosts?->exists($viewerId, $post->id) ?? false)),
			'muted' => $muted
				?? ($viewerId !== null && ($this->mutes?->isMuted($viewerId, $post->authorId) ?? false)),
			'bookmarked' => $bookmarked
				?? ($viewerId !== null && ($this->bookmarks?->exists($viewerId, $post->id) ?? false)),
			'pinned' => $this->pins?->find($post->authorId, $post->id) !== null,
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
	public function poll(
		Post $post,
		?string $viewerId = null,
		?PollVote $viewerVote = null,
		bool $viewerVoteLoaded = false,
	): ?array {
		$poll = $post->poll;
		if ($poll === null) {
			return null;
		}

		if (!$viewerVoteLoaded && $viewerId !== null) {
			try {
				$viewerVote = $this->pollVotes?->find($post->id, $viewerId);
			} catch (DatabaseException) {
				// A host that has not created the vote table can still serve
				// statuses; the write route reports the missing capability.
				$viewerVote = null;
			}
		}

		$now = $this->clock?->now() ?? new DateTimeImmutable('now');

		return [
			'id' => 'poll-' . $post->id,
			'expires_at' => $poll->expiresAt->format('Y-m-d\TH:i:s.000\Z'),
			'expired' => $poll->isExpired($now),
			'multiple' => $poll->multiple,
			'votes_count' => $poll->votesCount,
			'voters_count' => $poll->votersCount,
			'voted' => $viewerVote !== null,
			'own_votes' => $viewerVote?->positions ?? [],
			'options' => array_map(
				static fn (PollOption $option): array => $option->toArray(),
				$poll->options,
			),
			'emojis' => [],
		];
	}

	/**
	 * The Mastodon quote entity.
	 *
	 * Only an accepted quote exposes the nested status, and only while the
	 * quoted post is publicly visible. A private, deleted or revoked target
	 * becomes a null preview, so a quote cannot leak what its author hid.
	 *
	 * @return array<string, mixed>|null
	 */
	private function quote(Post $post, ?string $viewerId): ?array
	{
		if ($post->quotedObjectId === null || $post->quoteState === null) {
			return null;
		}

		$state = $post->quoteState->value;
		$quotedPostId = $this->activities->postIdFromUrl($post->quotedObjectId);
		$target = $quotedPostId === null ? null : $this->posts?->find($quotedPostId);

		if ($target === null || $target->isDeleted()) {
			return ['state' => 'deleted', 'quoted_status' => null];
		}

		if (!$post->quoteState->isAccepted()) {
			return ['state' => $state, 'quoted_status' => null];
		}

		if (!$target->visibility->isPubliclyVisible()) {
			return ['state' => 'unauthorized', 'quoted_status' => null];
		}

		return ['state' => $state, 'quoted_status' => $this->status($target, $viewerId)];
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
		$replies = $this->posts?->replyCountsForPosts($postIds) ?? [];
		$reblogs = $this->boosts?->countsForPosts($postIds) ?? [];
		$reblogged = $viewerId === null
			? []
			: ($this->boosts?->existsForPosts($viewerId, $postIds) ?? []);
		$bookmarked = $viewerId === null
			? []
			: ($this->bookmarks?->existsForPosts($viewerId, $postIds) ?? []);
		$muted = $viewerId === null
			? []
			: array_fill_keys($this->mutes?->actorIds($viewerId) ?? [], true);
		$pollVotes = [];
		if ($viewerId !== null) {
			try {
				$pollVotes = $this->pollVotes?->findForPosts($viewerId, $postIds) ?? [];
			} catch (DatabaseException) {
				// Keep status reads usable before the host migration.
				$pollVotes = [];
			}
		}

		return array_map(
			fn (Post $post): array => $this->status(
				$post,
				$viewerId,
				$counts[$post->id] ?? 0,
				$favourited[$post->id] ?? false,
				$pollVotes[$post->id] ?? null,
				true,
				$replies[$post->id] ?? 0,
				$reblogs[$post->id] ?? 0,
				$reblogged[$post->id] ?? false,
				$bookmarked[$post->id] ?? false,
				isset($muted[$post->authorId]),
			),
			$posts,
		);
	}

	/**
	 * Projects a cached, followed remote post for Mastodon status reads and
	 * remote favourite actions. Remote counts and imported media are not
	 * represented here; the local viewer's favourite state is exact.
	 *
	 * @return array<string, mixed>
	 */
	public function remoteStatus(RemotePost $post, bool $favourited): array
	{
		return [
			'id' => $post->objectId,
			'created_at' => $post->publishedAt->format('Y-m-d\\TH:i:s.000\\Z'),
			'in_reply_to_id' => $post->inReplyToId,
			'in_reply_to_account_id' => null,
			'sensitive' => $post->contentWarning !== null,
			'spoiler_text' => $post->contentWarning ?? '',
			'visibility' => 'public',
			'language' => null,
			'uri' => $post->objectId,
			'url' => $post->objectId,
			'replies_count' => 0,
			'reblogs_count' => 0,
			'favourites_count' => 0,
			'edited_at' => $post->updatedAt?->format('Y-m-d\\TH:i:s.000\\Z'),
			'content' => $this->html($post->content),
			'reblog' => null,
			'application' => null,
			'account' => $this->actorAccount($post->objectAuthorId ?? $post->actorId),
			'media_attachments' => [],
			'mentions' => [],
			'tags' => [],
			'emojis' => [],
			'card' => null,
			'poll' => null,
			'quote' => null,
			'favourited' => $favourited,
			'reblogged' => false,
			'bookmarked' => false,
			'muted' => false,
		];
	}

	/**
	 * The wrapper status Mastodon returns for a reblog.
	 *
	 * The boost is the outer status: its id and timestamp are the Announced
	 * activity, its account is the booster, and the original post is nested
	 * under `reblog`. Counts on the wrapper are zero, exactly as Mastodon's
	 * own projection reports them.
	 *
	 * @return array<string, mixed>
	 */
	public function boostStatus(Boost $boost, Post $post, ?string $viewerId = null): array
	{
		$original = $this->status($post, $viewerId);

		return array_merge($original, [
			'id' => $boost->id,
			'created_at' => $boost->createdAt->format('Y-m-d\TH:i:s.000\Z'),
			'in_reply_to_id' => null,
			'in_reply_to_account_id' => null,
			'sensitive' => $original['sensitive'],
			'spoiler_text' => $original['spoiler_text'],
			'visibility' => $original['visibility'],
			'uri' => $this->activities->boostId($boost),
			'url' => $original['url'],
			'replies_count' => 0,
			'reblogs_count' => 0,
			'favourites_count' => 0,
			'content' => '',
			'reblog' => $original,
			'account' => $this->account($boost->userId),
			'media_attachments' => [],
			'mentions' => [],
			'poll' => null,
			'favourited' => false,
			'reblogged' => false,
			'bookmarked' => false,
			'muted' => $viewerId !== null
				&& ($this->mutes?->isMuted($viewerId, $boost->userId) ?? false),
		]);
	}

	/**
	 * Projects one account from a profile relation without exposing its origin.
	 *
	 * The follow graph stores remote targets as actor URLs and local targets as
	 * local ids. Both become the same minimal account shape; a local id is
	 * resolved through the host directory before it is trusted.
	 *
	 * @return array<string, mixed>
	 */
	public function actorAccount(string $actorId): array
	{
		if ($this->localActors?->has($actorId) === true) {
			return $this->account($actorId);
		}

		$localId = $this->localActorId($actorId);

		return $localId === null ? $this->notificationAccount($actorId) : $this->account($localId);
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
	private function localActorId(string $actorId): ?string
	{
		$prefix = rtrim($this->baseUrl(), '/') . '/@';
		if (!str_starts_with($actorId, $prefix)) {
			return null;
		}

		$candidate = rawurldecode(substr($actorId, strlen($prefix)));

		return $candidate !== '' && $this->localActors?->has($candidate) === true ? $candidate : null;
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
		?LocalActorProfile $profile = null,
	): array {
		$fields = [];
		foreach ($profile?->fields ?? [] as $field) {
			$fields[] = [
				'name' => $field->name,
				'value' => $this->html($field->value),
				'verified_at' => $field->verified
					? $profile?->updatedAt->format('Y-m-d\\TH:i:s.000\\Z')
					: null,
			];
		}

		return [
			'id' => $id,
			'username' => $username,
			'acct' => $acct,
			'display_name' => $profile?->displayName ?? $username,
			'locked' => $profile?->locked ?? false,
			'bot' => false,
			'discoverable' => true,
			'group' => false,
			'created_at' => $profile?->createdAt?->format('Y-m-d\\TH:i:s.000\\Z')
				?? '1970-01-01T00:00:00.000Z',
			'note' => $this->html($profile?->summary ?? ''),
			'url' => $url,
			'avatar' => $profile?->avatarUrl ?? '',
			'avatar_static' => $profile?->avatarUrl ?? '',
			'header' => $profile?->headerUrl ?? '',
			'header_static' => $profile?->headerUrl ?? '',
			'followers_count' => $followersCount,
			'following_count' => $followingCount,
			'statuses_count' => 0,
			'last_status_at' => null,
			'noindex' => false,
			'emojis' => [],
			'fields' => $fields,
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
			'email' => (string) ($description['email'] ?? ''),
			'version' => '4.2.0 (compatible; Aloha Social ' . $description['version'] . ')',
			'urls' => ['streaming_api' => ''],
			'stats' => is_array($description['stats'] ?? null) ? $description['stats'] : $this->instance->stats(),
			'thumbnail' => (string) ($description['thumbnail'] ?? ''),
			'languages' => is_array($description['languages'] ?? null) ? $description['languages'] : [],
			'registrations' => (bool) ($description['registrations'] ?? false),
			'approval_required' => (bool) ($description['approval_required'] ?? true),
			'invites_enabled' => (bool) ($description['invites_enabled'] ?? false),
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
			'contact_account' => $this->contactAccount($description['contact_account'] ?? null),
			'rules' => [],
		];
	}

	/**
	 * Projects the configured contact account only when it is a known local id.
	 *
	 * A stale configuration value must not make the public endpoint advertise
	 * an account that cannot be opened. Without a local directory the host has
	 * deliberately left account validation to itself, so the configured value
	 * is still projected as a minimal account.
	 *
	 * @return array<string, mixed>|null
	 */
	private function contactAccount(mixed $userId): ?array
	{
		if (!is_string($userId) || trim($userId) === '') {
			return null;
		}

		$userId = trim($userId);
		if ($this->localActors !== null && !$this->localActors->has($userId)) {
			return null;
		}

		return $this->account($userId);
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
		$duration = (int) ($attachment['duration_seconds'] ?? 0);
		$type = match ((string) ($attachment['media_type'] ?? 'image')) {
			'gifv' => 'gifv',
			'video' => 'video',
			'audio' => 'audio',
			default => 'image',
		};
		$preview = $attachment['thumbnail_url'] ?? null;

		return [
			'id' => (string) ($attachment['id'] ?? ''),
			'type' => $type,
			'url' => (string) ($attachment['url'] ?? ''),
			'preview_url' => is_string($preview) && $preview !== '' ? $preview : null,
			'remote_url' => null,
			'preview_remote_url' => null,
			'text_url' => null,
			'meta' => [
				'original' => $this->mediaMeta($width, $height, $duration),
				'small' => $this->mediaMeta($width, $height, $duration),
			],
			'description' => $attachment['alt_text'] ?? null,
			'blurhash' => null,
		];
	}

	/**
	 * @return array<string, int|float|string>
	 */
	private function mediaMeta(int $width, int $height, int $duration = 0): array
	{
		$meta = [
			'width' => $width,
			'height' => $height,
			'size' => $width . 'x' . $height,
			'aspect' => $height > 0 ? $width / $height : 1.0,
		];

		if ($duration > 0) {
			$meta['duration'] = $duration;
		}

		return $meta;
	}

	/**
	 * Direct recipients are the only structured mentions the core stores.
	 * A reply that is not direct has no recipient list, so it reports none
	 * rather than inventing a mention that was never in the text.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function mentions(Post $post): array
	{
		return array_map(function (string $userId): array {
			$account = $this->account($userId);

			return [
				'id' => $userId,
				'username' => $account['username'],
				'url' => $account['url'],
				'acct' => $account['acct'],
			];
		}, $post->recipientIds);
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
