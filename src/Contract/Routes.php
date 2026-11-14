<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Contract;

use AlohaSocial\Core\Api\ActorHandler;
use AlohaSocial\Core\Api\BlockActorHandler;
use AlohaSocial\Core\Api\BlocksHandler;
use AlohaSocial\Core\Api\ClearNotificationsHandler;
use AlohaSocial\Core\Api\BoostPostHandler;
use AlohaSocial\Core\Api\CreatePostHandler;
use AlohaSocial\Core\Api\CreateFilterHandler;
use AlohaSocial\Core\Api\CreateScheduledPostHandler;
use AlohaSocial\Core\Api\DeleteFilterHandler;
use AlohaSocial\Core\Api\FiltersHandler;
use AlohaSocial\Core\Api\DeleteScheduledPostHandler;
use AlohaSocial\Core\Api\ScheduledPostsHandler;
use AlohaSocial\Core\Api\ShowFilterHandler;
use AlohaSocial\Core\Api\ShowScheduledPostHandler;
use AlohaSocial\Core\Api\UpdateFilterHandler;
use AlohaSocial\Core\Api\UpdateScheduledPostHandler;
use AlohaSocial\Core\Api\DeletePostHandler;
use AlohaSocial\Core\Api\DismissNotificationHandler;
use AlohaSocial\Core\Api\FollowHandler;
use AlohaSocial\Core\Api\FollowedHashtagTimelineHandler;
use AlohaSocial\Core\Api\FollowedTagsHandler;
use AlohaSocial\Core\Api\FollowHashtagHandler;
use AlohaSocial\Core\Api\FollowLocalAccountHandler;
use AlohaSocial\Core\Api\FollowRemoteActorHandler;
use AlohaSocial\Core\Api\FollowersCollectionHandler;
use AlohaSocial\Core\Api\FollowingCollectionHandler;
use AlohaSocial\Core\Api\FollowersHandler;
use AlohaSocial\Core\Api\HealthHandler;
use AlohaSocial\Core\Api\HashtagTimelineHandler;
use AlohaSocial\Core\Api\HomeTimelineHandler;
use AlohaSocial\Core\Api\InboxHandler;
use AlohaSocial\Core\Api\InstanceHandler;
use AlohaSocial\Core\Api\LikeRemotePostHandler;
use AlohaSocial\Core\Api\NotificationUnreadCountHandler;
use AlohaSocial\Core\Api\NotificationsHandler;
use AlohaSocial\Core\Api\OutboxHandler;
use AlohaSocial\Core\Api\PublicTimelineHandler;
use AlohaSocial\Core\Api\PublishAtprotoHandler;
use AlohaSocial\Core\Api\ReactToRemotePostHandler;
use AlohaSocial\Core\Api\RemotePollHandler;
use AlohaSocial\Core\Api\ShowRemotePostHandler;
use AlohaSocial\Core\Api\ShowAtprotoPublicationHandler;
use AlohaSocial\Core\Api\ShowLocalAccountHandler;
use AlohaSocial\Core\Api\UpdatePostHandler;
use AlohaSocial\Core\Api\ShowMediaHandler;
use AlohaSocial\Core\Api\ShowPostHandler;
use AlohaSocial\Core\Api\ShowRemoteThreadHandler;
use AlohaSocial\Core\Api\UnblockActorHandler;
use AlohaSocial\Core\Api\UnboostPostHandler;
use AlohaSocial\Core\Api\UnlikeRemotePostHandler;
use AlohaSocial\Core\Api\VoteRemotePollHandler;
use AlohaSocial\Core\Api\UnreactFromRemotePostHandler;
use AlohaSocial\Core\Api\UnfollowHashtagHandler;
use AlohaSocial\Core\Api\UploadMediaHandler;
use AlohaSocial\Core\Api\UnfollowHandler;
use AlohaSocial\Core\Api\UnfollowLocalAccountHandler;
use AlohaSocial\Core\Api\UnfollowRemoteActorHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonAccountFollowersHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonAccountFollowingHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonAccountHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonAccountStatusesHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonAddListAccountsHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonBookmarkStatusHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonBookmarksHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonClearNotificationsHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonCreateListHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonCreateStatusHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonDeleteListHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonDeleteMediaHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonCreateFilterHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonDeleteFilterHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonDeleteScheduledStatusHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonFiltersHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonShowFilterHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonUpdateFilterHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonScheduledStatusHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonScheduledStatusesHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonUpdateScheduledStatusHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonDeleteStatusHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonDismissNotificationHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonFavouriteStatusHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonFollowAccountHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonInstanceHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonListAccountsHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonListHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonListsHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonListTimelineHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonMediaUploadHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonMuteAccountHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonMutesHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonNotificationUnreadCountHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonNotificationsHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonPollHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonPublicTimelineHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonReblogStatusHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonRelationshipsHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonRemoveListAccountsHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonSearchHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonStatusContextHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonStatusHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonUnfollowAccountHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonUnmuteAccountHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonUnreblogStatusHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonUnbookmarkStatusHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonUnfavouriteStatusHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonUpdateListHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonUpdateMediaHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonUpdateStatusHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonVerifyCredentialsHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonVotePollHandler;
use AlohaSocial\Core\Api\WebFingerHandler;
use AlohaSocial\Core\Http\ApiVersion;
use AlohaSocial\Core\Http\Route;
use AlohaSocial\Core\Http\RouteManifest;
use AlohaSocial\Core\Service\Mastodon\MastodonApiConfig;

/**
 * The HTTP contract both hosts serve.
 *
 * Adding a feature means adding its route here; a host that does not serve it
 * fails the parity test instead of silently shipping a different API. See
 * ADR 0003.
 */
final class Routes
{
	public static function manifest(?string $mastodonPrefix = null): RouteManifest
	{
		$base = ApiVersion::BASE_PATH;
		$mastodon = MastodonApiConfig::normalizePrefix($mastodonPrefix ?? MastodonApiConfig::DEFAULT_PREFIX);

		return new RouteManifest(
			new Route(
				name: 'health',
				method: 'GET',
				path: '/healthz',
				handler: HealthHandler::class,
				requiresAuth: false,
				summary: 'Liveness probe; does not touch the database.',
			),
			new Route(
				name: 'instance.get',
				method: 'GET',
				path: $base . '/instance',
				handler: InstanceHandler::class,
				requiresAuth: false,
				summary: 'Public description of this instance.',
			),
			new Route(
				name: 'posts.create',
				method: 'POST',
				path: $base . '/posts',
				handler: CreatePostHandler::class,
				requiresAuth: true,
				rateLimit: '30/900',
				summary: 'Write a post.',
			),
			new Route(
				name: 'posts.get',
				method: 'GET',
				path: $base . '/posts/{id}',
				handler: ShowPostHandler::class,
				requiresAuth: false,
				summary: 'Read one post.',
			),
			new Route(
				name: 'posts.update',
				method: 'PATCH',
				path: $base . '/posts/{id}',
				handler: UpdatePostHandler::class,
				requiresAuth: true,
				rateLimit: '30/900',
				summary: 'Edit a post you wrote; only the author may, and an edit cannot widen its audience.',
			),
			new Route(
				name: 'atproto.publish',
				method: 'POST',
				path: $base . '/posts/{id}/atproto',
				handler: PublishAtprotoHandler::class,
				requiresAuth: true,
				rateLimit: '10/900',
				summary: 'Publish one public post to the signed-in account\'s AT Protocol PDS.',
			),
			new Route(
				name: 'atproto.publication',
				method: 'GET',
				path: $base . '/posts/{id}/atproto',
				handler: ShowAtprotoPublicationHandler::class,
				requiresAuth: true,
				rateLimit: '120/60',
				summary: 'Read the stored AT Protocol publication of one of the signed-in account\'s posts.',
			),
			new Route(
				name: 'threads.get',
				method: 'GET',
				path: $base . '/remote-posts/{id}/thread',
				handler: ShowRemoteThreadHandler::class,
				requiresAuth: true,
				rateLimit: '60/60',
				summary: 'Fetch the bounded ancestors and direct replies of a remote post.',
			),
			new Route(
				name: 'remote-posts.get',
				method: 'GET',
				path: $base . '/remote-posts/{id}',
				handler: ShowRemotePostHandler::class,
				requiresAuth: true,
				rateLimit: '120/60',
				summary: 'Read one stored remote post with the signed-in account\'s reaction state.',
			),
			new Route(
				name: 'remote-polls.get',
				method: 'GET',
				path: $base . '/remote-polls/{id}',
				handler: RemotePollHandler::class,
				requiresAuth: true,
				rateLimit: '120/60',
				summary: 'Read an imported remote poll with the viewer\'s own choices.',
			),
			new Route(
				name: 'remote-polls.vote',
				method: 'POST',
				path: $base . '/remote-polls/{id}/votes',
				handler: VoteRemotePollHandler::class,
				requiresAuth: true,
				rateLimit: '30/900',
				summary: 'Vote in a remote poll; stores the choice optimistically and queues signed vote Notes.',
			),
			new Route(
				name: 'remote-posts.like',
				method: 'POST',
				path: $base . '/remote-posts/{id}/like',
				handler: LikeRemotePostHandler::class,
				requiresAuth: true,
				rateLimit: '30/900',
				summary: 'Like a remote post from a followed actor; queues a signed Like and is idempotent.',
			),
			new Route(
				name: 'remote-posts.unlike',
				method: 'DELETE',
				path: $base . '/remote-posts/{id}/like',
				handler: UnlikeRemotePostHandler::class,
				requiresAuth: true,
				rateLimit: '60/900',
				summary: 'Remove the signed-in account\'s like from a remote post and queue Undo(Like).',
			),
			new Route(
				name: 'remote-posts.react',
				method: 'POST',
				path: $base . '/remote-posts/{id}/reactions',
				handler: ReactToRemotePostHandler::class,
				requiresAuth: true,
				rateLimit: '30/900',
				summary: 'Add a Unicode emoji reaction to a remote post and queue an EmojiReact.',
			),
			new Route(
				name: 'remote-posts.unreact',
				method: 'DELETE',
				path: $base . '/remote-posts/{id}/reactions/{emoji}',
				handler: UnreactFromRemotePostHandler::class,
				requiresAuth: true,
				rateLimit: '60/900',
				summary: 'Remove the signed-in account\'s emoji reaction and queue Undo(EmojiReact).',
			),
			new Route(
				name: 'posts.delete',
				method: 'DELETE',
				path: $base . '/posts/{id}',
				handler: DeletePostHandler::class,
				requiresAuth: true,
				summary: 'Delete a post you wrote.',
			),
			new Route(
				name: 'scheduled-posts.list',
				method: 'GET',
				path: $base . '/scheduled-posts',
				handler: ScheduledPostsHandler::class,
				requiresAuth: true,
				rateLimit: '60/60',
				summary: 'List the signed-in account\'s scheduled posts, newest first.',
			),
			new Route(
				name: 'scheduled-posts.create',
				method: 'POST',
				path: $base . '/scheduled-posts',
				handler: CreateScheduledPostHandler::class,
				requiresAuth: true,
				rateLimit: '30/900',
				summary: 'Store a post and publish it at a future time; nothing is delivered before then.',
			),
			new Route(
				name: 'scheduled-posts.get',
				method: 'GET',
				path: $base . '/scheduled-posts/{id}',
				handler: ShowScheduledPostHandler::class,
				requiresAuth: true,
				rateLimit: '120/60',
				summary: 'Read one of the signed-in account\'s scheduled posts.',
			),
			new Route(
				name: 'scheduled-posts.update',
				method: 'PATCH',
				path: $base . '/scheduled-posts/{id}',
				handler: UpdateScheduledPostHandler::class,
				requiresAuth: true,
				rateLimit: '30/900',
				summary: 'Move a pending scheduled post to a new time.',
			),
			new Route(
				name: 'scheduled-posts.delete',
				method: 'DELETE',
				path: $base . '/scheduled-posts/{id}',
				handler: DeleteScheduledPostHandler::class,
				requiresAuth: true,
				rateLimit: '30/900',
				summary: 'Cancel a pending scheduled post without publishing it.',
			),
			new Route(
				name: 'filters.list',
				method: 'GET',
				path: $base . '/filters',
				handler: FiltersHandler::class,
				requiresAuth: true,
				rateLimit: '60/60',
				summary: 'List the signed-in reader\'s keyword filters, newest first.',
			),
			new Route(
				name: 'filters.create',
				method: 'POST',
				path: $base . '/filters',
				handler: CreateFilterHandler::class,
				requiresAuth: true,
				rateLimit: '30/900',
				summary: 'Create a private keyword filter with hide or warn behaviour and optional expiry.',
			),
			new Route(
				name: 'filters.get',
				method: 'GET',
				path: $base . '/filters/{id}',
				handler: ShowFilterHandler::class,
				requiresAuth: true,
				rateLimit: '120/60',
				summary: 'Read one of the signed-in reader\'s keyword filters.',
			),
			new Route(
				name: 'filters.update',
				method: 'PATCH',
				path: $base . '/filters/{id}',
				handler: UpdateFilterHandler::class,
				requiresAuth: true,
				rateLimit: '30/900',
				summary: 'Replace a keyword filter\'s name, action, contexts, keywords and expiry.',
			),
			new Route(
				name: 'filters.delete',
				method: 'DELETE',
				path: $base . '/filters/{id}',
				handler: DeleteFilterHandler::class,
				requiresAuth: true,
				rateLimit: '30/900',
				summary: 'Delete one of the signed-in reader\'s keyword filters.',
			),
			new Route(
				name: 'posts.boost',
				method: 'POST',
				path: $base . '/posts/{id}/boost',
				handler: BoostPostHandler::class,
				requiresAuth: true,
				rateLimit: '30/900',
				summary: 'Share a visible public or unlisted post; idempotent per account and post.',
			),
			new Route(
				name: 'posts.unboost',
				method: 'DELETE',
				path: $base . '/posts/{id}/boost',
				handler: UnboostPostHandler::class,
				requiresAuth: true,
				rateLimit: '60/900',
				summary: 'Remove the signed-in account\'s share of a post.',
			),
			new Route(
				name: 'media.upload',
				method: 'POST',
				path: $base . '/media',
				handler: UploadMediaHandler::class,
				requiresAuth: true,
				rateLimit: '20/900',
				summary: 'Upload an image with optional alt text.',
			),
			new Route(
				name: 'media.get',
				method: 'GET',
				path: $base . '/media/{id}',
				handler: ShowMediaHandler::class,
				requiresAuth: false,
				rateLimit: '240/60',
				summary: 'Fetch an uploaded image, or its thumbnail with ?variant=thumbnail.',
			),
			new Route(
				name: 'timelines.public',
				method: 'GET',
				path: $base . '/timelines/public',
				handler: PublicTimelineHandler::class,
				requiresAuth: false,
				summary: 'Public timeline, newest first.',
			),
			new Route(
				name: 'timelines.tag',
				method: 'GET',
				path: $base . '/timelines/tag/{tag}',
				handler: HashtagTimelineHandler::class,
				requiresAuth: false,
				summary: 'Public posts carrying a hashtag, newest first.',
			),
			new Route(
				name: 'timelines.followed_tags',
				method: 'GET',
				path: $base . '/timelines/followed_tags',
				handler: FollowedHashtagTimelineHandler::class,
				requiresAuth: true,
				summary: 'Posts carrying any hashtag the signed-in account follows, newest first.',
			),
			new Route(
				name: 'timelines.home',
				method: 'GET',
				path: $base . '/timelines/home',
				handler: HomeTimelineHandler::class,
				requiresAuth: true,
				summary: 'The signed-in account\'s home timeline.',
			),
			new Route(
				name: 'followed_tags.list',
				method: 'GET',
				path: $base . '/followed_tags',
				handler: FollowedTagsHandler::class,
				requiresAuth: true,
				summary: 'The signed-in account\'s followed hashtags, newest first.',
			),
			new Route(
				name: 'followed_tags.follow',
				method: 'POST',
				path: $base . '/followed_tags',
				handler: FollowHashtagHandler::class,
				requiresAuth: true,
				summary: 'Follow a hashtag; following it again is a successful no-op.',
			),
			new Route(
				name: 'followed_tags.unfollow',
				method: 'DELETE',
				path: $base . '/followed_tags/{tag}',
				handler: UnfollowHashtagHandler::class,
				requiresAuth: true,
				summary: 'Unfollow a hashtag; an absent follow is a successful no-op.',
			),
			new Route(
				name: 'followers.list',
				method: 'GET',
				path: $base . '/followers',
				handler: FollowersHandler::class,
				requiresAuth: true,
				rateLimit: '120/60',
				summary: 'Actors that follow the signed-in account.',
			),
			new Route(
				name: 'followers.follow',
				method: 'POST',
				path: $base . '/followers',
				handler: FollowHandler::class,
				requiresAuth: true,
				rateLimit: '30/900',
				summary: 'Record a remote follower and its inbox; idempotent per actor.',
			),
			new Route(
				name: 'followers.unfollow',
				method: 'DELETE',
				path: $base . '/followers/{id}',
				handler: UnfollowHandler::class,
				requiresAuth: true,
				rateLimit: '60/900',
				summary: 'Forget a follower and stop delivering to it.',
			),
			new Route(
				name: 'blocks.list',
				method: 'GET',
				path: $base . '/blocks',
				handler: BlocksHandler::class,
				requiresAuth: true,
				rateLimit: '120/60',
				summary: 'Actors the signed-in account blocks.',
			),
			new Route(
				name: 'blocks.block',
				method: 'POST',
				path: $base . '/blocks',
				handler: BlockActorHandler::class,
				requiresAuth: true,
				rateLimit: '30/900',
				summary: 'Block a remote actor for the signed-in account; idempotent per actor.',
			),
			new Route(
				name: 'blocks.unblock',
				method: 'DELETE',
				path: $base . '/blocks/{id}',
				handler: UnblockActorHandler::class,
				requiresAuth: true,
				rateLimit: '60/900',
				summary: 'Remove one of the signed-in account\'s blocks.',
			),
			new Route(
				name: 'following.follow',
				method: 'POST',
				path: $base . '/following',
				handler: FollowRemoteActorHandler::class,
				requiresAuth: true,
				rateLimit: '30/900',
				summary: 'Store a pending follow and queue a signed Follow to its inbox.',
			),
			new Route(
				name: 'following.unfollow',
				method: 'DELETE',
				path: $base . '/following/{id}',
				handler: UnfollowRemoteActorHandler::class,
				requiresAuth: true,
				rateLimit: '60/900',
				summary: 'Remove a followed actor and queue a signed Undo to its inbox.',
			),
			new Route(
				name: 'accounts.show',
				method: 'GET',
				path: $base . '/accounts/{id}',
				handler: ShowLocalAccountHandler::class,
				requiresAuth: false,
				rateLimit: '120/60',
				summary: 'Public profile of one local account, with the viewer relationship when authenticated.',
			),
			new Route(
				name: 'accounts.follow',
				method: 'POST',
				path: $base . '/accounts/{id}/follow',
				handler: FollowLocalAccountHandler::class,
				requiresAuth: true,
				rateLimit: '30/900',
				summary: 'Follow one local account; idempotent per relationship.',
			),
			new Route(
				name: 'accounts.unfollow',
				method: 'DELETE',
				path: $base . '/accounts/{id}/follow',
				handler: UnfollowLocalAccountHandler::class,
				requiresAuth: true,
				rateLimit: '60/900',
				summary: 'Remove a local follow; an absent edge is a successful no-op.',
			),
			new Route(
				name: 'notifications.list',
				method: 'GET',
				path: $base . '/notifications',
				handler: NotificationsHandler::class,
				requiresAuth: true,
				rateLimit: '60/60',
				summary: 'Unread notifications of the signed-in account plus the unread count, newest first.',
			),
			new Route(
				name: 'notifications.unread_count',
				method: 'GET',
				path: $base . '/notifications/unread_count',
				handler: NotificationUnreadCountHandler::class,
				requiresAuth: true,
				rateLimit: '60/60',
				summary: 'Unread notification count of the signed-in account.',
			),
			new Route(
				name: 'notifications.dismiss',
				method: 'POST',
				path: $base . '/notifications/{id}/dismiss',
				handler: DismissNotificationHandler::class,
				requiresAuth: true,
				rateLimit: '30/900',
				summary: 'Mark one notification of the signed-in account read.',
			),
			new Route(
				name: 'notifications.clear',
				method: 'POST',
				path: $base . '/notifications/clear',
				handler: ClearNotificationsHandler::class,
				requiresAuth: true,
				rateLimit: '30/900',
				summary: 'Mark every unread notification of the signed-in account read.',
			),
			new Route(
				name: 'inbox',
				method: 'POST',
				path: '/inbox',
				handler: InboxHandler::class,
				requiresAuth: false,
				rateLimit: '120/60',
				summary: 'Receive a signed ActivityPub activity from a remote server.',
			),
			new Route(
				name: 'inbox.shared',
				method: 'POST',
				path: '/inbox/shared',
				handler: InboxHandler::class,
				requiresAuth: false,
				rateLimit: '120/60',
				summary: 'Receive a signed activity for any local actor through the shared inbox.',
			),
			new Route(
				name: 'actors.get',
				method: 'GET',
				path: '/@{handle}',
				handler: ActorHandler::class,
				requiresAuth: false,
				rateLimit: '120/60',
				summary: 'ActivityStreams actor document of a local account.',
			),
			new Route(
				name: 'actors.followers',
				method: 'GET',
				path: '/@{handle}/followers',
				handler: FollowersCollectionHandler::class,
				requiresAuth: false,
				rateLimit: '120/60',
				summary: 'OrderedCollection of the actors following a local account.',
			),
			new Route(
				name: 'actors.following',
				method: 'GET',
				path: '/@{handle}/following',
				handler: FollowingCollectionHandler::class,
				requiresAuth: false,
				rateLimit: '120/60',
				summary: 'OrderedCollection of the actors a local account follows.',
			),
			new Route(
				name: 'actors.outbox',
				method: 'GET',
				path: '/@{handle}/outbox',
				handler: OutboxHandler::class,
				requiresAuth: false,
				rateLimit: '120/60',
				summary: 'OrderedCollection of a local account\'s public posts as Create activities.',
			),
			new Route(
				name: 'mastodon.instance',
				method: 'GET',
				path: $mastodon . '/instance',
				handler: MastodonInstanceHandler::class,
				requiresAuth: false,
				rateLimit: '120/60',
				summary: 'Mastodon-compatible instance description with configured metadata and local stats.',
			),
			new Route(
				name: 'mastodon.media.upload',
				method: 'POST',
				path: $mastodon . '/media',
				handler: MastodonMediaUploadHandler::class,
				requiresAuth: true,
				rateLimit: '20/900',
				requiredScopes: ['write:media'],
				summary: 'Upload one image through the Mastodon shape.',
			),
			new Route(
				name: 'mastodon.media.update',
				method: 'PUT',
				path: $mastodon . '/media/{id}',
				handler: MastodonUpdateMediaHandler::class,
				requiresAuth: true,
				rateLimit: '30/900',
				requiredScopes: ['write:media'],
				summary: 'Update an unattached media description before it is used in a status.',
			),
			new Route(
				name: 'mastodon.media.delete',
				method: 'DELETE',
				path: $mastodon . '/media/{id}',
				handler: MastodonDeleteMediaHandler::class,
				requiresAuth: true,
				rateLimit: '30/900',
				requiredScopes: ['write:media'],
				summary: 'Delete an owned media attachment that is not attached to a status.',
			),
			new Route(
				name: 'mastodon.timelines.public',
				method: 'GET',
				path: $mastodon . '/timelines/public',
				handler: MastodonPublicTimelineHandler::class,
				requiresAuth: false,
				rateLimit: '60/60',
				summary: 'Public local statuses in the Mastodon shape.',
			),
			new Route(
				name: 'mastodon.statuses.show',
				method: 'GET',
				path: $mastodon . '/statuses/{id}',
				handler: MastodonStatusHandler::class,
				requiresAuth: false,
				rateLimit: '120/60',
				summary: 'Read one local status in the Mastodon shape.',
			),
			new Route(
				name: 'mastodon.statuses.context',
				method: 'GET',
				path: $mastodon . '/statuses/{id}/context',
				handler: MastodonStatusContextHandler::class,
				requiresAuth: false,
				rateLimit: '60/60',
				summary: 'Visible ancestors and direct replies of one local status.',
			),
			new Route(
				name: 'mastodon.statuses.create',
				method: 'POST',
				path: $mastodon . '/statuses',
				handler: MastodonCreateStatusHandler::class,
				requiresAuth: true,
				rateLimit: '30/900',
				requiredScopes: ['write:statuses'],
				summary: 'Write a local status through the Mastodon shape, optionally with a poll.',
			),
			new Route(
				name: 'mastodon.statuses.update',
				method: 'PUT',
				path: $mastodon . '/statuses/{id}',
				handler: MastodonUpdateStatusHandler::class,
				requiresAuth: true,
				rateLimit: '30/900',
				requiredScopes: ['write:statuses'],
				summary: 'Edit one of the signed-in account\'s statuses through the Mastodon shape.',
			),
			new Route(
				name: 'mastodon.statuses.delete',
				method: 'DELETE',
				path: $mastodon . '/statuses/{id}',
				handler: MastodonDeleteStatusHandler::class,
				requiresAuth: true,
				rateLimit: '30/900',
				requiredScopes: ['write:statuses'],
				summary: 'Delete one of the signed-in account\'s statuses.',
			),
			new Route(
				name: 'mastodon.scheduled_statuses.list',
				method: 'GET',
				path: $mastodon . '/scheduled_statuses',
				handler: MastodonScheduledStatusesHandler::class,
				requiresAuth: true,
				rateLimit: '60/60',
				requiredScopes: ['read:statuses'],
				summary: 'Scheduled statuses of the signed-in account in the Mastodon shape.',
			),
			new Route(
				name: 'mastodon.scheduled_statuses.show',
				method: 'GET',
				path: $mastodon . '/scheduled_statuses/{id}',
				handler: MastodonScheduledStatusHandler::class,
				requiresAuth: true,
				rateLimit: '120/60',
				requiredScopes: ['read:statuses'],
				summary: 'Read one scheduled status of the signed-in account.',
			),
			new Route(
				name: 'mastodon.scheduled_statuses.update',
				method: 'PUT',
				path: $mastodon . '/scheduled_statuses/{id}',
				handler: MastodonUpdateScheduledStatusHandler::class,
				requiresAuth: true,
				rateLimit: '30/900',
				requiredScopes: ['write:statuses'],
				summary: 'Move a scheduled status to a new time.',
			),
			new Route(
				name: 'mastodon.scheduled_statuses.delete',
				method: 'DELETE',
				path: $mastodon . '/scheduled_statuses/{id}',
				handler: MastodonDeleteScheduledStatusHandler::class,
				requiresAuth: true,
				rateLimit: '30/900',
				requiredScopes: ['write:statuses'],
				summary: 'Cancel a scheduled status without publishing it.',
			),
			new Route(
				name: 'mastodon.filters.list',
				method: 'GET',
				path: $mastodon . '/filters',
				handler: MastodonFiltersHandler::class,
				requiresAuth: true,
				rateLimit: '60/60',
				requiredScopes: ['read:filters'],
				summary: 'Keyword filters of the signed-in reader in the Mastodon v2 shape.',
			),
			new Route(
				name: 'mastodon.filters.create',
				method: 'POST',
				path: $mastodon . '/filters',
				handler: MastodonCreateFilterHandler::class,
				requiresAuth: true,
				rateLimit: '30/900',
				requiredScopes: ['write:filters'],
				summary: 'Create a keyword filter through the Mastodon v2 API.',
			),
			new Route(
				name: 'mastodon.filters.show',
				method: 'GET',
				path: $mastodon . '/filters/{id}',
				handler: MastodonShowFilterHandler::class,
				requiresAuth: true,
				rateLimit: '120/60',
				requiredScopes: ['read:filters'],
				summary: 'Read one keyword filter in the Mastodon v2 shape.',
			),
			new Route(
				name: 'mastodon.filters.update',
				method: 'PUT',
				path: $mastodon . '/filters/{id}',
				handler: MastodonUpdateFilterHandler::class,
				requiresAuth: true,
				rateLimit: '30/900',
				requiredScopes: ['write:filters'],
				summary: 'Replace a keyword filter through the Mastodon v2 API.',
			),
			new Route(
				name: 'mastodon.filters.delete',
				method: 'DELETE',
				path: $mastodon . '/filters/{id}',
				handler: MastodonDeleteFilterHandler::class,
				requiresAuth: true,
				rateLimit: '30/900',
				requiredScopes: ['write:filters'],
				summary: 'Delete a keyword filter through the Mastodon v2 API.',
			),
			new Route(
				name: 'mastodon.statuses.reblog',
				method: 'POST',
				path: $mastodon . '/statuses/{id}/reblog',
				handler: MastodonReblogStatusHandler::class,
				requiresAuth: true,
				rateLimit: '30/900',
				requiredScopes: ['write:statuses'],
				summary: 'Reblog a visible local status; idempotent per account and status.',
			),
			new Route(
				name: 'mastodon.statuses.unreblog',
				method: 'POST',
				path: $mastodon . '/statuses/{id}/unreblog',
				handler: MastodonUnreblogStatusHandler::class,
				requiresAuth: true,
				rateLimit: '30/900',
				requiredScopes: ['write:statuses'],
				summary: 'Remove the signed-in account\'s reblog of a visible local status.',
			),
			new Route(
				name: 'mastodon.statuses.favourite',
				method: 'POST',
				path: $mastodon . '/statuses/{id}/favourite',
				handler: MastodonFavouriteStatusHandler::class,
				requiresAuth: true,
				rateLimit: '30/900',
				requiredScopes: ['write:favourites'],
				summary: 'Favourite a visible local status; idempotent per account and status.',
			),
			new Route(
				name: 'mastodon.statuses.unfavourite',
				method: 'POST',
				path: $mastodon . '/statuses/{id}/unfavourite',
				handler: MastodonUnfavouriteStatusHandler::class,
				requiresAuth: true,
				rateLimit: '30/900',
				requiredScopes: ['write:favourites'],
				summary: 'Remove the signed-in account\'s favourite from a visible local status.',
			),
			new Route(
				name: 'mastodon.statuses.bookmark',
				method: 'POST',
				path: $mastodon . '/statuses/{id}/bookmark',
				handler: MastodonBookmarkStatusHandler::class,
				requiresAuth: true,
				rateLimit: '30/900',
				requiredScopes: ['write:bookmarks'],
				summary: 'Bookmark a visible local status privately; idempotent per account and status.',
			),
			new Route(
				name: 'mastodon.statuses.unbookmark',
				method: 'POST',
				path: $mastodon . '/statuses/{id}/unbookmark',
				handler: MastodonUnbookmarkStatusHandler::class,
				requiresAuth: true,
				rateLimit: '30/900',
				requiredScopes: ['write:bookmarks'],
				summary: 'Remove the signed-in account\'s bookmark from a visible local status.',
			),
			new Route(
				name: 'mastodon.bookmarks',
				method: 'GET',
				path: $mastodon . '/bookmarks',
				handler: MastodonBookmarksHandler::class,
				requiresAuth: true,
				rateLimit: '60/60',
				requiredScopes: ['read:bookmarks'],
				summary: 'List the signed-in account\'s private bookmarks, newest first.',
			),
			new Route(
				name: 'mastodon.lists.list',
				method: 'GET',
				path: $mastodon . '/lists',
				handler: MastodonListsHandler::class,
				requiresAuth: true,
				rateLimit: '60/60',
				requiredScopes: ['read:lists'],
				summary: 'List the signed-in account\'s private lists, newest first.',
			),
			new Route(
				name: 'mastodon.lists.create',
				method: 'POST',
				path: $mastodon . '/lists',
				handler: MastodonCreateListHandler::class,
				requiresAuth: true,
				rateLimit: '30/900',
				requiredScopes: ['write:lists'],
				summary: 'Create a private named list of local accounts.',
			),
			new Route(
				name: 'mastodon.lists.show',
				method: 'GET',
				path: $mastodon . '/lists/{id}',
				handler: MastodonListHandler::class,
				requiresAuth: true,
				rateLimit: '60/60',
				requiredScopes: ['read:lists'],
				summary: 'Read one list owned by the signed-in account.',
			),
			new Route(
				name: 'mastodon.lists.update',
				method: 'PUT',
				path: $mastodon . '/lists/{id}',
				handler: MastodonUpdateListHandler::class,
				requiresAuth: true,
				rateLimit: '30/900',
				requiredScopes: ['write:lists'],
				summary: 'Update the title, replies policy or exclusive flag of one owned list.',
			),
			new Route(
				name: 'mastodon.lists.delete',
				method: 'DELETE',
				path: $mastodon . '/lists/{id}',
				handler: MastodonDeleteListHandler::class,
				requiresAuth: true,
				rateLimit: '30/900',
				requiredScopes: ['write:lists'],
				summary: 'Delete one owned list and its membership.',
			),
			new Route(
				name: 'mastodon.lists.accounts',
				method: 'GET',
				path: $mastodon . '/lists/{id}/accounts',
				handler: MastodonListAccountsHandler::class,
				requiresAuth: true,
				rateLimit: '60/60',
				requiredScopes: ['read:lists'],
				summary: 'List the local accounts in one owned list, newest first.',
			),
			new Route(
				name: 'mastodon.lists.accounts.add',
				method: 'POST',
				path: $mastodon . '/lists/{id}/accounts',
				handler: MastodonAddListAccountsHandler::class,
				requiresAuth: true,
				rateLimit: '30/900',
				requiredScopes: ['write:lists'],
				summary: 'Add local accounts to one owned list; existing membership is idempotent.',
			),
			new Route(
				name: 'mastodon.lists.accounts.remove',
				method: 'DELETE',
				path: $mastodon . '/lists/{id}/accounts',
				handler: MastodonRemoveListAccountsHandler::class,
				requiresAuth: true,
				rateLimit: '30/900',
				requiredScopes: ['write:lists'],
				summary: 'Remove local accounts from one owned list; absent membership is a no-op.',
			),
			new Route(
				name: 'mastodon.timelines.list',
				method: 'GET',
				path: $mastodon . '/timelines/list/{list_id}',
				handler: MastodonListTimelineHandler::class,
				requiresAuth: true,
				rateLimit: '60/60',
				requiredScopes: ['read:lists'],
				summary: 'Statuses from the local accounts in one owned list, newest first.',
			),
			new Route(
				name: 'mastodon.polls.show',
				method: 'GET',
				path: $mastodon . '/polls/{id}',
				handler: MastodonPollHandler::class,
				requiresAuth: false,
				rateLimit: '120/60',
				summary: 'Read one local poll, including the viewer\'s own choices when authenticated.',
			),
			new Route(
				name: 'mastodon.polls.vote',
				method: 'POST',
				path: $mastodon . '/polls/{id}/votes',
				handler: MastodonVotePollHandler::class,
				requiresAuth: true,
				rateLimit: '30/900',
				requiredScopes: ['write:statuses'],
				summary: 'Vote in a visible local poll; an identical retry is idempotent.',
			),
			new Route(
					name: 'mastodon.accounts.relationships',
				method: 'GET',
				path: $mastodon . '/accounts/relationships',
				handler: MastodonRelationshipsHandler::class,
				requiresAuth: true,
				rateLimit: '60/60',
				requiredScopes: ['read:follows'],
				summary: 'Relationships between the signed-in account and local accounts.',
			),
			new Route(
				name: 'mastodon.accounts.follow',
				method: 'POST',
				path: $mastodon . '/accounts/{id}/follow',
				handler: MastodonFollowAccountHandler::class,
				requiresAuth: true,
				rateLimit: '30/900',
				requiredScopes: ['write:follows'],
				summary: 'Follow one local account; idempotent per relationship.',
			),
			new Route(
				name: 'mastodon.accounts.unfollow',
				method: 'POST',
				path: $mastodon . '/accounts/{id}/unfollow',
				handler: MastodonUnfollowAccountHandler::class,
				requiresAuth: true,
				rateLimit: '30/900',
				requiredScopes: ['write:follows'],
				summary: 'Unfollow one local account; idempotent per relationship.',
			),
			new Route(
				name: 'mastodon.accounts.mute',
				method: 'POST',
				path: $mastodon . '/accounts/{id}/mute',
				handler: MastodonMuteAccountHandler::class,
				requiresAuth: true,
				rateLimit: '30/900',
				requiredScopes: ['write:mutes'],
				summary: 'Mute a local account or remote actor, optionally including its notifications.',
			),
			new Route(
				name: 'mastodon.accounts.unmute',
				method: 'POST',
				path: $mastodon . '/accounts/{id}/unmute',
				handler: MastodonUnmuteAccountHandler::class,
				requiresAuth: true,
				rateLimit: '30/900',
				requiredScopes: ['write:mutes'],
				summary: 'Remove the signed-in account\'s mute; idempotent.',
			),
			new Route(
				name: 'mastodon.mutes',
				method: 'GET',
				path: $mastodon . '/mutes',
				handler: MastodonMutesHandler::class,
				requiresAuth: true,
				rateLimit: '60/60',
				requiredScopes: ['read:mutes'],
				summary: 'List the signed-in account\'s active mutes, newest first.',
			),
			new Route(
				name: 'mastodon.accounts.statuses',
				method: 'GET',
				path: $mastodon . '/accounts/{id}/statuses',
				handler: MastodonAccountStatusesHandler::class,
				requiresAuth: false,
				rateLimit: '60/60',
				summary: 'Statuses of one local account in the Mastodon shape, with viewer-scoped followers-only visibility.',
			),
			new Route(
				name: 'mastodon.accounts.show',
				method: 'GET',
				path: $mastodon . '/accounts/{id}',
				handler: MastodonAccountHandler::class,
				requiresAuth: false,
				rateLimit: '120/60',
				summary: 'Public profile of one local account in the Mastodon shape.',
			),
			new Route(
				name: 'mastodon.accounts.followers',
				method: 'GET',
				path: $mastodon . '/accounts/{id}/followers',
				handler: MastodonAccountFollowersHandler::class,
				requiresAuth: false,
				rateLimit: '60/60',
				summary: 'Public followers of one local account, newest first.',
			),
			new Route(
				name: 'mastodon.accounts.following',
				method: 'GET',
				path: $mastodon . '/accounts/{id}/following',
				handler: MastodonAccountFollowingHandler::class,
				requiresAuth: false,
				rateLimit: '60/60',
				summary: 'Public accounts followed by one local account, newest first.',
			),
			new Route(
				name: 'mastodon.notifications.list',
				method: 'GET',
				path: $mastodon . '/notifications',
				handler: MastodonNotificationsHandler::class,
				requiresAuth: true,
				rateLimit: '60/60',
				requiredScopes: ['read:notifications'],
				summary: 'Unread notifications for the signed-in local account, newest first.',
			),
			new Route(
				name: 'mastodon.notifications.unread_count',
				method: 'GET',
				path: $mastodon . '/notifications/unread_count',
				handler: MastodonNotificationUnreadCountHandler::class,
				requiresAuth: true,
				rateLimit: '60/60',
				requiredScopes: ['read:notifications'],
				summary: 'Capped unread notification count for the signed-in local account.',
			),
			new Route(
				name: 'mastodon.notifications.dismiss',
				method: 'POST',
				path: $mastodon . '/notifications/{id}/dismiss',
				handler: MastodonDismissNotificationHandler::class,
				requiresAuth: true,
				rateLimit: '30/900',
				requiredScopes: ['write:notifications'],
				summary: 'Mark one notification of the signed-in account read.',
			),
			new Route(
				name: 'mastodon.notifications.clear',
				method: 'POST',
				path: $mastodon . '/notifications/clear',
				handler: MastodonClearNotificationsHandler::class,
				requiresAuth: true,
				rateLimit: '30/900',
				requiredScopes: ['write:notifications'],
				summary: 'Mark every unread notification of the signed-in account read.',
			),
			new Route(
				name: 'mastodon.search',
				method: 'GET',
				path: $mastodon . '/search',
				handler: MastodonSearchHandler::class,
				requiresAuth: true,
				rateLimit: '60/60',
				requiredScopes: ['read:search'],
				summary: 'Search local accounts and public/unlisted statuses in the Mastodon v2 shape.',
			),
			new Route(
				name: 'mastodon.accounts.verify_credentials',
				method: 'GET',
				path: $mastodon . '/accounts/verify_credentials',
				handler: MastodonVerifyCredentialsHandler::class,
				requiresAuth: true,
				rateLimit: '60/60',
				requiredScopes: ['read:accounts'],
				summary: 'The signed-in account in the Mastodon shape.',
			),
			new Route(
				name: 'webfinger',
				method: 'GET',
				path: '/.well-known/webfinger',
				handler: WebFingerHandler::class,
				requiresAuth: false,
				rateLimit: '120/60',
				summary: 'Resolve an acct: handle to the actor document of a local account.',
			),
		);
	}
}
