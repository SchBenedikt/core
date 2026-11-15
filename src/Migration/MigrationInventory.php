<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Migration;

use JsonException;

use function array_filter;
use function array_values;
use function json_encode;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Which source feature has which destination, before any data moves.
 *
 * This is the "no silent drop" contract of a cutover: every Social domain the
 * core knows about appears exactly once with an owner, a destination, whether
 * the neutral export can validate it, and how a rollback keeps identity. A
 * feature the core cannot store yet is listed as `gap` with the issue that
 * tracks it — it is never omitted, because an omitted feature is what would be
 * deleted without anybody deciding to.
 *
 * `retention` has no "drop" value on purpose: discarding data is a product
 * decision that cannot be expressed by this inventory. `convert` means the
 * destination derives the state from the source (thumbnails, hashtags) and
 * `preserve` means the data itself has to arrive.
 *
 * The export validators consume the same target names: `v1` marks a feature
 * covered by the version-1 parity checks, `v2` by the version-2 sections,
 * `inventory-only` by neither (the host still has to preserve it, but the
 * neutral validator cannot see it yet).
 */
final class MigrationInventory
{
	public const FORMAT = 'alohasocial.migration-inventory';

	public const VERSION = 1;

	public const OWNER_CORE = 'core';

	public const OWNER_HOST = 'host';

	public const STATUS_SUPPORTED = 'supported';

	public const STATUS_PARTIAL = 'partial';

	public const STATUS_GAP = 'gap';

	public const STATUS_HOST_ONLY = 'host-only';

	/**
	 * Every feature name the inventory must classify exactly once.
	 *
	 * @var list<string>
	 */
	public const DOMAINS = [
		'accounts',
		'account_moves',
		'profile_metadata',
		'posts',
		'post_revisions',
		'attachments',
		'media_processing',
		'favourites',
		'emoji_reactions',
		'boosts',
		'bookmarks',
		'followers',
		'following',
		'blocks',
		'mutes',
		'account_lists',
		'followed_hashtags',
		'recommendations',
		'notifications',
		'polls',
		'quotes',
		'scheduled_posts',
		'stories',
		'post_pins',
		'media_collections',
		'moderation',
		'keyword_filters',
		'direct_messages',
		'search_index',
		'atproto_publications',
		'atproto_inbound',
		'atproto_pds',
		'queue',
		'activity_log',
		'host_accounts_sessions',
		'admin_ui',
	];

	/**
	 * @return list<array{
	 *     feature: string,
	 *     social_source: string,
	 *     owner: string,
	 *     destination: string,
	 *     status: string,
	 *     retention: string,
	 *     url_identity: bool,
	 *     rollback: string,
	 *     validation: string,
	 *     gap_issue: ?string
	 * }>
	 */
	public static function entries(): array
	{
		return [
			self::entry(
				'accounts',
				'oc_social_account / user identity',
				self::OWNER_HOST,
				'host accounts, local_user_id + ActorDocumentService',
				self::STATUS_SUPPORTED,
				'v1',
			),
			self::entry(
				'account_moves',
				'ActivityPub Move and alsoKnownAs follower redirection',
				self::OWNER_CORE,
				'social_account_move rows with the stable activity id, destination and processed follow ids; actor URLs and keys stay untouched',
				self::STATUS_SUPPORTED,
				'inventory-only',
			),
			self::entry(
				'profile_metadata',
				'profile fields, display name, bio, avatar, banner',
				self::OWNER_CORE,
				'LocalActorProfileProvider -> actor document and Mastodon account; avatar and banner bytes travel as attachments',
				self::STATUS_SUPPORTED,
				'inventory-only',
			),
			self::entry(
				'posts',
				'oc_social_post / outbox items, including the reversible archived_at flag',
				self::OWNER_CORE,
				'social_post rows, note and activity ids derived from the post id',
				self::STATUS_SUPPORTED,
				'v1',
			),
			self::entry(
				'post_revisions',
				'edit history of a post',
				self::OWNER_CORE,
				'social_post_revision plus the live revision/edited_at columns',
				self::STATUS_SUPPORTED,
				'v2',
			),
			self::entry(
				'attachments',
				'media metadata and link rows',
				self::OWNER_CORE,
				'social_attachment, social_post_attachment and the BlobStore port, including image, video and audio metadata',
				self::STATUS_SUPPORTED,
				'v2',
			),
			self::entry(
				'media_processing',
				'generated previews and thumbnails',
				self::OWNER_CORE,
				'derived at upload time by the ImageProcessor port',
				self::STATUS_SUPPORTED,
				'inventory-only',
				retention: 'convert',
			),
							self::entry(
				'favourites',
				'local likes and their remote counterparts',
				self::OWNER_CORE,
				'social_favourite for local posts plus social_reaction inbound Likes; outbound Like/Undo for remote posts',
				self::STATUS_SUPPORTED,
				'v2',
			),
							self::entry(
				'emoji_reactions',
				'ActivityPub EmojiReact',
				self::OWNER_CORE,
				'social_reaction emoji rows with separate counts; outbound EmojiReact/Undo for remote posts',
				self::STATUS_SUPPORTED,
				'v2',
			),
							self::entry(
				'boosts',
				'local reblogs and Announce activities',
				self::OWNER_CORE,
				'social_boost rows plus the queued Announce/Undo',
				self::STATUS_SUPPORTED,
				'inventory-only',
			),
			self::entry(
				'bookmarks',
				'private bookmarks',
				self::OWNER_CORE,
				'social_bookmark rows',
				self::STATUS_SUPPORTED,
				'inventory-only',
			),
			self::entry(
				'followers',
				'incoming follower edges',
				self::OWNER_CORE,
				'social_follower rows with inbox URL and shared inbox',
				self::STATUS_SUPPORTED,
				'v1',
			),
			self::entry(
				'following',
				'outgoing follow edges and their lifecycle',
				self::OWNER_CORE,
				'social_following rows with Follow/Undo activity ids',
				self::STATUS_SUPPORTED,
				'v1',
			),
			self::entry(
				'blocks',
				'per-user blocks',
				self::OWNER_CORE,
				'social_block rows',
				self::STATUS_SUPPORTED,
				'inventory-only',
			),
			self::entry(
				'mutes',
				'per-user mutes',
				self::OWNER_CORE,
				'social_mute rows with their expiry',
				self::STATUS_SUPPORTED,
				'inventory-only',
			),
			self::entry(
				'account_lists',
				'private account lists',
				self::OWNER_CORE,
				'social_account_list and social_account_list_member',
				self::STATUS_SUPPORTED,
				'inventory-only',
			),
			self::entry(
				'followed_hashtags',
				'private followed tags',
				self::OWNER_CORE,
				'social_followed_hashtag rows',
				self::STATUS_SUPPORTED,
				'inventory-only',
			),
			self::entry(
				'recommendations',
				'For You preferences, explicit feedback and consented interaction signals',
				self::OWNER_CORE,
				'social_recommendation_preference, social_recommendation_feedback and social_recommendation_signal rows; snapshots are a short-lived cache and are not migrated',
				self::STATUS_SUPPORTED,
				'inventory-only',
			),
			self::entry(
				'notifications',
				'local notification rows, sender policy and held requests',
				self::OWNER_CORE,
				'social_notification, social_notification_policy, social_notification_sender and social_conversation_mute',
				self::STATUS_SUPPORTED,
				'inventory-only',
			),
			self::entry(
				'polls',
				'Polls and local votes',
				self::OWNER_CORE,
				'social_poll for local polls plus social_remote_poll/social_remote_poll_vote for imported Questions and signed votes',
				self::STATUS_SUPPORTED,
				'inventory-only',
			),
			self::entry(
				'quotes',
				'quote posts and FEP-044f authorization',
				self::OWNER_CORE,
				'social_post quote columns and social_quote_grant; pending/accepted/rejected/revoked with a dereferenceable authorization',
				self::STATUS_SUPPORTED,
				'inventory-only',
			),
			self::entry(
				'scheduled_posts',
				'durable scheduled publishing',
				self::OWNER_CORE,
				'social_scheduled_post rows with a reserved post id and the queued publisher job',
				self::STATUS_SUPPORTED,
				'v2',
			),
			self::entry(
				'stories',
				'ephemeral stories/shorts',
				self::OWNER_CORE,
				'social_story, social_story_view, social_story_reaction, social_story_reply and social_story_tombstone with expiry, private receipts and Pixelfed Add/Delete',
				self::STATUS_SUPPORTED,
				'v2',
			),
			self::entry(
				'post_pins',
				'profile pinned posts / featured collection',
				self::OWNER_CORE,
				'social_post_pin rows with explicit one-based position; the actor featured OrderedCollection is derived, not stored separately',
				self::STATUS_SUPPORTED,
				'inventory-only',
			),
			self::entry(
				'media_collections',
				'owner-curated media collections/albums',
				self::OWNER_CORE,
				'social_media_collection and social_media_collection_item with audience, order and per-item post references; member posts stay in posts',
				self::STATUS_SUPPORTED,
				'inventory-only',
			),
			self::entry(
				'moderation',
				'reports, domain policy and review queue',
				self::OWNER_CORE,
				'social_report, social_moderation_action and social_held_post with the host-neutral review and actor/domain policy services',
				self::STATUS_SUPPORTED,
				'inventory-only',
			),
			self::entry(
				'keyword_filters',
				'per-reader keyword filters',
				self::OWNER_CORE,
				'social_filter rows with owner, action, contexts and keyword list',
				self::STATUS_SUPPORTED,
				'v2',
			),
			self::entry(
				'direct_messages',
				'persistent conversations',
				self::OWNER_CORE,
				'open: conversation model and remote delivery (#19)',
				self::STATUS_GAP,
				'inventory-only',
				gapIssue: '#19',
			),
			self::entry(
				'search_index',
				'local search plus bounded remote discovery',
				self::OWNER_CORE,
				'social_search_index and social_search_trend; local post search remains available',
				self::STATUS_SUPPORTED,
				'inventory-only',
			),
			self::entry(
				'atproto_publications',
				'published AT Protocol records',
				self::OWNER_CORE,
				'social_atproto_publication rows, keyed by local user and post with a deterministic record key',
				self::STATUS_SUPPORTED,
				'inventory-only',
			),
			self::entry(
				'atproto_inbound',
				'relay commits, Jetstream events and AppView reconciliation',
				self::OWNER_CORE,
				'social_atproto_sync_checkpoint, social_atproto_remote_record (opaque records and tombstones) and social_atproto_remote_account; the host owns the event transport',
				self::STATUS_SUPPORTED,
				'inventory-only',
			),
			self::entry(
				'atproto_pds',
				'optional native PDS identity and repository',
				self::OWNER_CORE,
				'social_pds_identity, social_pds_key (sealed private keys), social_pds_record, social_pds_commit, social_pds_block and social_pds_plc_operation; the host owns the HTTP process, account auth and secret-box key',
				self::STATUS_SUPPORTED,
				'inventory-only',
			),
			self::entry(
				'queue',
				'durable delivery queue',
				self::OWNER_CORE,
				'social_job rows with type, key id, attempts and dead_at',
				self::STATUS_SUPPORTED,
				'v1',
			),
			self::entry(
				'activity_log',
				'inbound activity replay log',
				self::OWNER_CORE,
				'social_activity_log rows',
				self::STATUS_SUPPORTED,
				'v1',
			),
			self::entry(
				'host_accounts_sessions',
				'Nextcloud users, sessions, roles, app passwords',
				self::OWNER_HOST,
				'host authentication; the core only sees a local_user_id',
				self::STATUS_HOST_ONLY,
				'inventory-only',
			),
			self::entry(
				'admin_ui',
				'admin settings and moderation pages',
				self::OWNER_HOST,
				'host admin surface; never part of the core',
				self::STATUS_HOST_ONLY,
				'inventory-only',
			),
		];
	}

	/**
	 * Features that have no complete core destination yet.
	 *
	 * @return list<array{feature: string, status: string, gap_issue: ?string}>
	 */
	public static function gaps(): array
	{
		return array_values(array_map(
			static fn (array $entry): array => [
				'feature' => $entry['feature'],
				'status' => $entry['status'],
				'gap_issue' => $entry['gap_issue'],
			],
			array_filter(
				self::entries(),
				static fn (array $entry): bool => $entry['status'] === self::STATUS_GAP
					|| $entry['status'] === self::STATUS_PARTIAL,
			),
		));
	}

	/**
	 * @return array{format: string, version: int, entries: list<array<string, mixed>>}
	 */
	public static function toArray(): array
	{
		return [
			'format' => self::FORMAT,
			'version' => self::VERSION,
			'entries' => self::entries(),
		];
	}

	/**
	 * @throws JsonException if an entry cannot be encoded
	 */
	public static function toJson(): string
	{
		return json_encode(
			self::toArray(),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
		);
	}

	/**
	 * @return array{
	 *     feature: string,
	 *     social_source: string,
	 *     owner: string,
	 *     destination: string,
	 *     status: string,
	 *     retention: string,
	 *     url_identity: bool,
	 *     rollback: string,
	 *     validation: string,
	 *     gap_issue: ?string
	 * }
	 */
	private static function entry(
		string $feature,
		string $socialSource,
		string $owner,
		string $destination,
		string $status,
		string $validation,
		string $retention = 'preserve',
		?string $gapIssue = null,
	): array {
		return [
			'feature' => $feature,
			'social_source' => $socialSource,
			'owner' => $owner,
			'destination' => $destination,
			'status' => $status,
			'retention' => $retention,
			'url_identity' => $owner === self::OWNER_CORE,
			'rollback' => $owner === self::OWNER_HOST
				? 'The host keeps its own rows; the core stores nothing to roll back.'
				: 'Keep the exported local_user_id and post ids: actor, note and activity ids are derived from them, '
					. 'so a rollback re-imports the same identities instead of creating new ones.',
			'validation' => $validation,
			'gap_issue' => $gapIssue,
		];
	}
}
