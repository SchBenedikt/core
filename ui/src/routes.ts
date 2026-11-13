/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Core route paths, not host controller paths. Keep the placeholders in the
 * pattern constants so a parity test can compare them with Routes::manifest().
 */
export const coreRoutes = {
	publicTimeline: '/api/v1/timelines/public',
	hashtagTimelinePattern: '/api/v1/timelines/tag/{tag}',
	hashtagTimeline: (tag: string): string => `/api/v1/timelines/tag/${encodeURIComponent(tag)}`,
	posts: '/api/v1/posts',
	postPattern: '/api/v1/posts/{id}',
	post: (id: string): string => `/api/v1/posts/${encodeURIComponent(id)}`,
	accountPattern: '/api/v1/accounts/{id}',
	account: (id: string): string => `/api/v1/accounts/${encodeURIComponent(id)}`,
	accountFollowPattern: '/api/v1/accounts/{id}/follow',
	accountFollow: (id: string): string => `/api/v1/accounts/${encodeURIComponent(id)}/follow`,
	notifications: '/api/v1/notifications',
	notificationDismissPattern: '/api/v1/notifications/{id}/dismiss',
	notificationDismiss: (id: string): string => `/api/v1/notifications/${encodeURIComponent(id)}/dismiss`,
	notificationsClear: '/api/v1/notifications/clear',
	notificationsUnreadCount: '/api/v1/notifications/unread_count',
} as const;
