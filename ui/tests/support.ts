/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { Post, UiResponse } from '../src/index.js';

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

export function errorResponse(status: number, code: string, message: string): UiResponse {
	return {
		status,
		headers: { 'content-type': 'application/json' },
		body: JSON.stringify({ error: { code, message } }),
	};
}
