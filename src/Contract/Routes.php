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
use AlohaSocial\Core\Api\CreatePostHandler;
use AlohaSocial\Core\Api\DeletePostHandler;
use AlohaSocial\Core\Api\FollowHandler;
use AlohaSocial\Core\Api\FollowRemoteActorHandler;
use AlohaSocial\Core\Api\FollowersCollectionHandler;
use AlohaSocial\Core\Api\FollowingCollectionHandler;
use AlohaSocial\Core\Api\FollowersHandler;
use AlohaSocial\Core\Api\HealthHandler;
use AlohaSocial\Core\Api\HomeTimelineHandler;
use AlohaSocial\Core\Api\InboxHandler;
use AlohaSocial\Core\Api\InstanceHandler;
use AlohaSocial\Core\Api\OutboxHandler;
use AlohaSocial\Core\Api\PublicTimelineHandler;
use AlohaSocial\Core\Api\PublishAtprotoHandler;
use AlohaSocial\Core\Api\ShowMediaHandler;
use AlohaSocial\Core\Api\ShowPostHandler;
use AlohaSocial\Core\Api\ShowRemoteThreadHandler;
use AlohaSocial\Core\Api\UnblockActorHandler;
use AlohaSocial\Core\Api\UploadMediaHandler;
use AlohaSocial\Core\Api\UnfollowHandler;
use AlohaSocial\Core\Api\UnfollowRemoteActorHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonAccountStatusesHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonCreateStatusHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonDeleteStatusHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonFavouriteStatusHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonInstanceHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonMediaUploadHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonPublicTimelineHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonStatusHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonUnfavouriteStatusHandler;
use AlohaSocial\Core\Api\Mastodon\MastodonVerifyCredentialsHandler;
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
				name: 'atproto.publish',
				method: 'POST',
				path: $base . '/posts/{id}/atproto',
				handler: PublishAtprotoHandler::class,
				requiresAuth: true,
				rateLimit: '10/900',
				summary: 'Publish one public post to the signed-in account\'s AT Protocol PDS.',
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
				name: 'posts.delete',
				method: 'DELETE',
				path: $base . '/posts/{id}',
				handler: DeletePostHandler::class,
				requiresAuth: true,
				summary: 'Delete a post you wrote.',
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
				name: 'timelines.home',
				method: 'GET',
				path: $base . '/timelines/home',
				handler: HomeTimelineHandler::class,
				requiresAuth: true,
				summary: 'The signed-in account\'s home timeline.',
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
				summary: 'Follow a remote actor and deliver a signed Follow to its inbox.',
			),
			new Route(
				name: 'following.unfollow',
				method: 'DELETE',
				path: $base . '/following/{id}',
				handler: UnfollowRemoteActorHandler::class,
				requiresAuth: true,
				rateLimit: '60/900',
				summary: 'Remove a followed actor and deliver a signed Undo to its inbox.',
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
				summary: 'Mastodon-compatible instance description.',
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
				name: 'mastodon.statuses.create',
				method: 'POST',
				path: $mastodon . '/statuses',
				handler: MastodonCreateStatusHandler::class,
				requiresAuth: true,
				rateLimit: '30/900',
				requiredScopes: ['write:statuses'],
				summary: 'Write a local status through the Mastodon shape.',
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
				name: 'mastodon.accounts.statuses',
				method: 'GET',
				path: $mastodon . '/accounts/{id}/statuses',
				handler: MastodonAccountStatusesHandler::class,
				requiresAuth: false,
				rateLimit: '60/60',
				summary: 'Public statuses of one local account in the Mastodon shape.',
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
