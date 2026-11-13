/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { UiApiError, UiTransportError, type UiErrorFields } from './api-error.js';
import type { HostBridge } from './host-bridge.js';
import { coreRoutes } from './routes.js';
import type {
	Account,
	CreatePostInput,
	Notification,
	NotificationPage,
	Post,
	Relationship,
	UiResponse,
} from './types.js';

export const DEFAULT_TIMELINE_LIMIT = 20;

export const MAX_TIMELINE_LIMIT = 40;

export const DEFAULT_NOTIFICATION_LIMIT = 20;

export const MAX_NOTIFICATION_LIMIT = 40;

/** Mirrors PostService::MAX_CONTENT_LENGTH for an early, friendly error. */
export const MAX_POST_LENGTH = 5000;

/** Mirrors PostService::MAX_CONTENT_WARNING_LENGTH. */
export const MAX_CONTENT_WARNING_LENGTH = 200;

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

export function normalizeNotificationLimit(limit: number | undefined): number {
	if (limit === undefined || !Number.isFinite(limit)) {
		return DEFAULT_NOTIFICATION_LIMIT;
	}

	return Math.min(MAX_NOTIFICATION_LIMIT, Math.max(1, Math.trunc(limit)));
}

/**
 * Typed reads over the core routes. The bridge is the only transport; this
 * class never calls fetch() and never reads a host global.
 */
export class CoreApi {
	constructor(private readonly bridge: HostBridge) {}

	async publicTimeline(options: PublicTimelineOptions = {}): Promise<Post[]> {
		return this.timeline(coreRoutes.publicTimeline, options);
	}

	async hashtagTimeline(tag: string, options: PublicTimelineOptions = {}): Promise<Post[]> {
		const normalized = tag.trim().replace(/^#/, '');
		if (normalized === '') {
			throw new UiApiError(400, 'invalid_tag', 'A hashtag is required.');
		}

		return this.timeline(coreRoutes.hashtagTimeline(normalized), options);
	}

	async getPost(id: string): Promise<Post> {
		if (id === '') {
			throw new UiApiError(400, 'invalid_post_id', 'A post id is required.');
		}

		const data = await this.request<{ post?: unknown }>({
			method: 'GET',
			path: coreRoutes.post(id),
		});

		if (!isRecord(data) || !isRecord(data.post)) {
			throw new UiApiError(502, 'invalid_response', 'The post response has no post object.');
		}

		return data.post as unknown as Post;
	}

	async getAccount(id: string): Promise<Account> {
		if (id.trim() === '') {
			throw new UiApiError(400, 'invalid_account_id', 'An account id is required.');
		}

		const data = await this.request<{ account?: unknown }>({
			method: 'GET',
			path: coreRoutes.account(id),
		});

		if (!isRecord(data) || !isRecord(data.account)) {
			throw new UiApiError(502, 'invalid_response', 'The account response has no account object.');
		}

		return data.account as unknown as Account;
	}

	async followAccount(id: string): Promise<Relationship> {
		if (id.trim() === '') {
			throw new UiApiError(400, 'invalid_account_id', 'An account id is required.');
		}

		const data = await this.request<{ relationship?: unknown }>({
			method: 'POST',
			path: coreRoutes.accountFollow(id),
		});

		return relationshipFrom(data);
	}

	async unfollowAccount(id: string): Promise<Relationship> {
		if (id.trim() === '') {
			throw new UiApiError(400, 'invalid_account_id', 'An account id is required.');
		}

		const data = await this.request<{ relationship?: unknown }>({
			method: 'DELETE',
			path: coreRoutes.accountFollow(id),
		});

		return relationshipFrom(data);
	}

	async notifications(options: { limit?: number; before?: string } = {}): Promise<NotificationPage> {
		const query: Record<string, string> = {
			limit: String(normalizeNotificationLimit(options.limit)),
		};
		if (options.before !== undefined && options.before !== '') {
			query.before = options.before;
		}

		const data = await this.request<{ notifications?: unknown; unread_count?: unknown }>({
			method: 'GET',
			path: coreRoutes.notifications,
			query,
		});

		if (!isRecord(data) || !Array.isArray(data.notifications) || typeof data.unread_count !== 'number') {
			throw new UiApiError(502, 'invalid_response', 'The notification response is incomplete.');
		}

		return {
			notifications: data.notifications as Notification[],
			unreadCount: data.unread_count,
		};
	}

	async dismissNotification(id: string): Promise<void> {
		if (id.trim() === '') {
			throw new UiApiError(400, 'invalid_notification_id', 'A notification id is required.');
		}

		await this.request<void>({
			method: 'POST',
			path: coreRoutes.notificationDismiss(id),
		});
	}

	async clearNotifications(): Promise<number> {
		const data = await this.request<{ cleared?: unknown }>({
			method: 'POST',
			path: coreRoutes.notificationsClear,
		});

		if (!isRecord(data) || typeof data.cleared !== 'number') {
			throw new UiApiError(502, 'invalid_response', 'The clear response has no count.');
		}

		return data.cleared;
	}

	async notificationUnreadCount(): Promise<number> {
		const data = await this.request<{ count?: unknown }>({
			method: 'GET',
			path: coreRoutes.notificationsUnreadCount,
		});

		if (!isRecord(data) || typeof data.count !== 'number') {
			throw new UiApiError(502, 'invalid_response', 'The unread-count response has no count.');
		}

		return data.count;
	}

	async createPost(input: CreatePostInput): Promise<Post> {
		const content = input.content.trim();
		const length = Array.from(content).length;
		if (length === 0) {
			throw new UiApiError(400, 'invalid_post', 'A post needs text.');
		}
		if (length > MAX_POST_LENGTH) {
			throw new UiApiError(
				400,
				'invalid_post',
				`A post may be at most ${MAX_POST_LENGTH} characters long.`,
			);
		}

		const warning = input.contentWarning?.trim() ?? '';
		if (Array.from(warning).length > MAX_CONTENT_WARNING_LENGTH) {
			throw new UiApiError(
				400,
				'invalid_post',
				`A content warning may be at most ${MAX_CONTENT_WARNING_LENGTH} characters long.`,
			);
		}

		const body: Record<string, unknown> = {
			content: input.content,
			visibility: input.visibility ?? 'public',
		};
		if (warning !== '') {
			body.content_warning = warning;
		}
		if (input.inReplyToId !== undefined && input.inReplyToId !== null && input.inReplyToId !== '') {
			body.in_reply_to_id = input.inReplyToId;
		}

		const data = await this.request<{ post?: unknown }>({
			method: 'POST',
			path: coreRoutes.posts,
			body,
		});

		if (!isRecord(data) || !isRecord(data.post)) {
			throw new UiApiError(502, 'invalid_response', 'The post response has no post object.');
		}

		return data.post as unknown as Post;
	}

	private async timeline(path: string, options: PublicTimelineOptions): Promise<Post[]> {
		const query: Record<string, string> = {
			limit: String(normalizeTimelineLimit(options.limit)),
		};

		if (options.before !== undefined && options.before !== '') {
			query.before = options.before;
		}

		const data = await this.request<{ posts?: unknown }>({
			method: 'GET',
			path,
			query,
		});

		if (!data || !Array.isArray(data.posts)) {
			throw new UiApiError(502, 'invalid_response', 'The timeline response has no posts array.');
		}

		return data.posts as Post[];
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

function relationshipFrom(data: unknown): Relationship {
	if (!isRecord(data) || !isRecord(data.relationship)) {
		throw new UiApiError(502, 'invalid_response', 'The relationship response is missing.');
	}

	const relationship = data.relationship;
	if (typeof relationship.following !== 'boolean'
		|| typeof relationship.followed_by !== 'boolean'
		|| typeof relationship.blocking !== 'boolean'
		|| typeof relationship.muting !== 'boolean'
	) {
		throw new UiApiError(502, 'invalid_response', 'The relationship response is incomplete.');
	}

	return {
		following: relationship.following,
		followed_by: relationship.followed_by,
		blocking: relationship.blocking,
		muting: relationship.muting,
	};
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
