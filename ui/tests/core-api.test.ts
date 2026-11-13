/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import {
	CoreApi,
	FakeHostBridge,
	UiApiError,
	UiTransportError,
	normalizeTimelineLimit,
} from '../src/index.js';
import { errorResponse, post, timelineResponse } from './support.js';

describe('CoreApi', () => {
	it('reads the public timeline with a bounded limit and cursor', async () => {
		const bridge = new FakeHostBridge({
			responses: {
				'GET /api/v1/timelines/public': timelineResponse([post('one')]),
			},
		});
		const api = new CoreApi(bridge);

		const posts = await api.publicTimeline({ limit: 99, before: '2026-11-11T10:00:00Z' });

		expect(posts).toHaveLength(1);
		expect(bridge.calls[0]).toEqual({
			method: 'GET',
			path: '/api/v1/timelines/public',
			query: { limit: '40', before: '2026-11-11T10:00:00Z' },
		});
	});

	it('encodes a post id as one path segment', async () => {
		const bridge = new FakeHostBridge({
			responses: {
				'GET /api/v1/posts/a%2Fb': { status: 200, body: JSON.stringify({ post: post('a/b') }) },
			},
		});
		const api = new CoreApi(bridge);

		await expect(api.getPost('a/b')).resolves.toMatchObject({ id: 'a/b' });
		expect(bridge.calls[0]?.path).toBe('/api/v1/posts/a%2Fb');
	});

	it('preserves the core error code, message and fields', async () => {
		const bridge = new FakeHostBridge({
			responses: {
				'GET /api/v1/timelines/public': {
					status: 422,
					body: JSON.stringify({
						error: {
							code: 'validation_failed',
							message: 'The request is not valid.',
							fields: { before: 'before must be a timestamp.' },
						},
					}),
				},
			},
		});

		try {
			await new CoreApi(bridge).publicTimeline();
			expect.fail('The request should have failed.');
		} catch (error) {
			expect(error).toBeInstanceOf(UiApiError);
			expect(error).toMatchObject({
				status: 422,
				code: 'validation_failed',
				message: 'The request is not valid.',
				fields: { before: 'before must be a timestamp.' },
			});
		}
	});

	it('handles a non-JSON error without leaking the raw body', async () => {
		const bridge = new FakeHostBridge({
			responses: {
				'GET /api/v1/timelines/public': { status: 503, body: 'upstream secret' },
			},
		});

		try {
			await new CoreApi(bridge).publicTimeline();
			expect.fail('The request should have failed.');
		} catch (error) {
			expect(error).toBeInstanceOf(UiApiError);
			expect((error as UiApiError).code).toBe('http_error');
			expect((error as Error).message).not.toContain('upstream secret');
		}
	});

	it('rejects malformed successful JSON', async () => {
		const bridge = new FakeHostBridge({
			responses: {
				'GET /api/v1/timelines/public': { status: 200, body: '<html>' },
			},
		});

		await expect(new CoreApi(bridge).publicTimeline()).rejects.toMatchObject({
			status: 502,
			code: 'invalid_response',
		});
	});

	it('wraps a bridge transport failure in a UI error', async () => {
		const bridge = new FakeHostBridge({
			responses: {
				'GET /api/v1/timelines/public': () => {
					throw new Error('connection refused');
				},
			},
		});

		try {
			await new CoreApi(bridge).publicTimeline();
			expect.fail('The request should have failed.');
		} catch (error) {
			expect(error).toBeInstanceOf(UiTransportError);
			expect((error as Error).message).not.toContain('connection refused');
		}
	});

	it('rejects an empty post id before asking the bridge', async () => {
		const bridge = new FakeHostBridge();

		await expect(new CoreApi(bridge).getPost('')).rejects.toMatchObject({
			status: 400,
			code: 'invalid_post_id',
		});
		expect(bridge.calls).toHaveLength(0);
	});

	it('normalizes limits at both edges', () => {
		expect(normalizeTimelineLimit(undefined)).toBe(20);
		expect(normalizeTimelineLimit(Number.NaN)).toBe(20);
		expect(normalizeTimelineLimit(0)).toBe(1);
		expect(normalizeTimelineLimit(41)).toBe(40);
	});

	it('rejects a 401 without putting the response body in the message', async () => {
		const bridge = new FakeHostBridge({
			responses: {
				'GET /api/v1/posts/one': errorResponse(401, 'authentication_required', 'Authentication is required.'),
			},
		});

		try {
			await new CoreApi(bridge).getPost('one');
			expect.fail('The request should have failed.');
		} catch (error) {
			expect(error).toMatchObject({ status: 401, code: 'authentication_required' });
			expect((error as Error).message).not.toContain('token');
		}
	});

	it('surfaces a 429 rate-limit refusal with its stable code', async () => {
		const bridge = new FakeHostBridge({
			responses: {
				'GET /api/v1/timelines/public': errorResponse(429, 'rate_limited', 'Too many requests.'),
			},
		});

		await expect(new CoreApi(bridge).publicTimeline()).rejects.toMatchObject({
			status: 429,
			code: 'rate_limited',
			message: 'Too many requests.',
		});
	});

	it('keeps a missing post as a 404 error', async () => {
		const bridge = new FakeHostBridge({
			responses: {
				'GET /api/v1/posts/missing': errorResponse(404, 'not_found', 'Post not found.'),
			},
		});

		await expect(new CoreApi(bridge).getPost('missing')).rejects.toMatchObject({
			status: 404,
			code: 'not_found',
		});
	});
});
