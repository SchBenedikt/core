<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Contract;

use AlohaSocial\Core\Api\CreatePostHandler;
use AlohaSocial\Core\Api\DeletePostHandler;
use AlohaSocial\Core\Api\FollowHandler;
use AlohaSocial\Core\Api\FollowersHandler;
use AlohaSocial\Core\Api\HealthHandler;
use AlohaSocial\Core\Api\HomeTimelineHandler;
use AlohaSocial\Core\Api\InboxHandler;
use AlohaSocial\Core\Api\InstanceHandler;
use AlohaSocial\Core\Api\PublicTimelineHandler;
use AlohaSocial\Core\Api\ShowPostHandler;
use AlohaSocial\Core\Api\UnfollowHandler;
use AlohaSocial\Core\Http\ApiVersion;
use AlohaSocial\Core\Http\Route;
use AlohaSocial\Core\Http\RouteManifest;

/**
 * The HTTP contract both hosts serve.
 *
 * Adding a feature means adding its route here; a host that does not serve it
 * fails the parity test instead of silently shipping a different API. See
 * ADR 0003.
 */
final class Routes
{
	public static function manifest(): RouteManifest
	{
		$base = ApiVersion::BASE_PATH;

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
				name: 'posts.delete',
				method: 'DELETE',
				path: $base . '/posts/{id}',
				handler: DeletePostHandler::class,
				requiresAuth: true,
				summary: 'Delete a post you wrote.',
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
				name: 'inbox',
				method: 'POST',
				path: '/inbox',
				handler: InboxHandler::class,
				requiresAuth: false,
				rateLimit: '120/60',
				summary: 'Receive a signed ActivityPub activity from a remote server.',
			),
		);
	}
}
