/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Core route paths, not host controller paths. Keep the placeholders in the
 * pattern constants so a parity test can compare them with Routes::manifest().
 */
import { CORE_API_VERSION } from './version.js';

const apiBase = `/api/${CORE_API_VERSION}`;

export const coreRoutes = {
	publicTimeline: `${apiBase}/timelines/public`,
	hashtagTimelinePattern: `${apiBase}/timelines/tag/{tag}`,
	hashtagTimeline: (tag: string): string => `${apiBase}/timelines/tag/${encodeURIComponent(tag)}`,
	posts: `${apiBase}/posts`,
	postPattern: `${apiBase}/posts/{id}`,
	post: (id: string): string => `${apiBase}/posts/${encodeURIComponent(id)}`,
	accountPattern: `${apiBase}/accounts/{id}`,
	account: (id: string): string => `${apiBase}/accounts/${encodeURIComponent(id)}`,
	accountFollowPattern: `${apiBase}/accounts/{id}/follow`,
	accountFollow: (id: string): string => `${apiBase}/accounts/${encodeURIComponent(id)}/follow`,
	notifications: `${apiBase}/notifications`,
	notificationDismissPattern: `${apiBase}/notifications/{id}/dismiss`,
	notificationDismiss: (id: string): string => `${apiBase}/notifications/${encodeURIComponent(id)}/dismiss`,
	notificationsClear: `${apiBase}/notifications/clear`,
	notificationsUnreadCount: `${apiBase}/notifications/unread_count`,
} as const;
