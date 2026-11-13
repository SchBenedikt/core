/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { Account, Notification, Post, Relationship, UiResponse } from '../src/index.js';

export function post(id: string, overrides: Partial<Post> = {}): Post {
	return {
		id,
		author_id: 'alice',
		content: `Post ${id}`,
		content_warning: null,
		visibility: 'public',
		published_at: '2026-11-11T10:00:00Z',
		deleted_at: null,
		...overrides,
	};
}

export function timelineResponse(posts: Post[], status = 200): UiResponse {
	return {
		status,
		headers: { 'content-type': 'application/json' },
		body: JSON.stringify({ posts }),
	};
}

export function relationship(overrides: Partial<Relationship> = {}): Relationship {
	return {
		following: false,
		followed_by: false,
		blocking: false,
		muting: false,
		...overrides,
	};
}

export function account(id = 'bob', overrides: Partial<Account> = {}): Account {
	return {
		id,
		handle: id,
		display_name: id,
		url: `https://social.example/@${id}`,
		followers_count: 0,
		following_count: 0,
		relationship: relationship(),
		...overrides,
	};
}

export function accountResponse(value: Account): UiResponse {
	return {
		status: 200,
		headers: { 'content-type': 'application/json' },
		body: JSON.stringify({ account: value }),
	};
}

export function relationshipResponse(value: Relationship): UiResponse {
	return {
		status: 200,
		headers: { 'content-type': 'application/json' },
		body: JSON.stringify({ relationship: value }),
	};
}

export function notification(id: string, overrides: Partial<Notification> = {}): Notification {
	return {
		id,
		type: 'follow',
		actor_id: 'bob',
		post_id: null,
		created_at: '2026-11-13T10:00:00Z',
		read: false,
		...overrides,
	};
}

export function notificationPageResponse(
	notifications: Notification[],
	unreadCount = notifications.length,
): UiResponse {
	return {
		status: 200,
		headers: { 'content-type': 'application/json' },
		body: JSON.stringify({ notifications, unread_count: unreadCount }),
	};
}

export function errorResponse(status: number, code: string, message: string): UiResponse {
	return {
		status,
		headers: { 'content-type': 'application/json' },
		body: JSON.stringify({ error: { code, message } }),
	};
}
