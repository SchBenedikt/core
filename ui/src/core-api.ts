/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { UiApiError, UiTransportError, type UiErrorFields } from './api-error.js';
import type { HostBridge } from './host-bridge.js';
import { coreRoutes } from './routes.js';
import type { Post, UiResponse } from './types.js';

export const DEFAULT_TIMELINE_LIMIT = 20;

export const MAX_TIMELINE_LIMIT = 40;

export interface PublicTimelineOptions {
	limit?: number;
	before?: string;
}

/**
 * Clamp like the core route does. The backend remains authoritative; this
 * keeps a component's default and the API contract aligned without creating
 * a second validation model.
 */
export function normalizeTimelineLimit(limit: number | undefined): number {
	if (limit === undefined || !Number.isFinite(limit)) {
		return DEFAULT_TIMELINE_LIMIT;
	}

	return Math.min(MAX_TIMELINE_LIMIT, Math.max(1, Math.trunc(limit)));
}

/**
 * Typed reads over the core routes. The bridge is the only transport; this
 * class never calls fetch() and never reads a host global.
 */
export class CoreApi {
	constructor(private readonly bridge: HostBridge) {}

	async publicTimeline(options: PublicTimelineOptions = {}): Promise<Post[]> {
		const query: Record<string, string> = {
			limit: String(normalizeTimelineLimit(options.limit)),
		};

		if (options.before !== undefined && options.before !== '') {
			query.before = options.before;
		}

		const data = await this.request<{ posts?: unknown }>({
			method: 'GET',
			path: coreRoutes.publicTimeline,
			query,
		});

		if (!data || !Array.isArray(data.posts)) {
			throw new UiApiError(502, 'invalid_response', 'The timeline response has no posts array.');
		}

		return data.posts as Post[];
	}

	async getPost(id: string): Promise<Post> {
		if (id === '') {
			throw new UiApiError(400, 'invalid_post_id', 'A post id is required.');
		}

		const data = await this.request<{ post?: unknown }>({
			method: 'GET',
			path: coreRoutes.post(id),
		});

		if (!data || typeof data.post !== 'object' || data.post === null) {
			throw new UiApiError(502, 'invalid_response', 'The post response has no post object.');
		}

		return data.post as Post;
	}

	private async request<T>(request: {
		method: 'GET' | 'POST' | 'DELETE';
		path: string;
		query?: Record<string, string>;
		body?: unknown;
	}): Promise<T> {
		let response: UiResponse;

		try {
			response = await this.bridge.request(request);
		} catch {
			// A host transport error can contain a URL or header detail. The UI
			// gets one stable message and never receives the host's exception.
			throw new UiTransportError();
		}

		if (response.status < 200 || response.status >= 300) {
			throw parseErrorResponse(response);
		}

		if (response.body === '') {
			return undefined as T;
		}

		let decoded: unknown;
		try {
			decoded = JSON.parse(response.body);
		} catch {
			throw new UiApiError(502, 'invalid_response', 'The server returned invalid JSON.');
		}

		return decoded as T;
	}
}

function parseErrorResponse(response: UiResponse): UiApiError {
	let body: unknown;

	try {
		body = JSON.parse(response.body);
	} catch {
		return new UiApiError(response.status, 'http_error', `The request failed with status ${response.status}.`);
	}

	const error = isRecord(body) && isRecord(body.error) ? body.error : null;
	const code = typeof error?.code === 'string' && error.code !== '' ? error.code : 'http_error';
	const message = typeof error?.message === 'string' && error.message !== ''
		? error.message
		: `The request failed with status ${response.status}.`;
	const fields = isRecord(error?.fields) ? stringFields(error.fields) : {};

	return new UiApiError(response.status, code, message, fields);
}

function stringFields(value: Record<string, unknown>): UiErrorFields {
	const fields: UiErrorFields = {};

	for (const [key, item] of Object.entries(value)) {
		if (typeof item === 'string') {
			fields[key] = item;
		}
	}

	return fields;
}

function isRecord(value: unknown): value is Record<string, unknown> {
	return typeof value === 'object' && value !== null && !Array.isArray(value);
}
