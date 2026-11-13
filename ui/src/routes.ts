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
	postPattern: '/api/v1/posts/{id}',
	post: (id: string): string => `/api/v1/posts/${encodeURIComponent(id)}`,
} as const;
