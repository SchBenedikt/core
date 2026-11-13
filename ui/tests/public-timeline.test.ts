/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { flushPromises, mount } from '@vue/test-utils';
import {
	createAlohaUi,
	FakeHostBridge,
	PublicTimeline,
} from '../src/index.js';
import { errorResponse, post, timelineResponse } from './support.js';

function mountTimeline(bridge: FakeHostBridge, limit = 20) {
	return mount(PublicTimeline, {
		props: { limit },
		global: { plugins: [createAlohaUi({ bridge })] },
	});
}

describe('PublicTimeline', () => {
	it('renders the loading state before the first response', async () => {
		let resolveResponse: ((response: ReturnType<typeof timelineResponse>) => void) | undefined;
		const pending = new Promise<ReturnType<typeof timelineResponse>>((resolve) => {
			resolveResponse = resolve;
		});
		const bridge = new FakeHostBridge({
			responses: {
				'GET /api/v1/timelines/public': () => pending,
			},
		});

		const wrapper = mountTimeline(bridge);

		expect(wrapper.get('[role="status"]').text()).toContain('Loading public timeline');

		resolveResponse?.(timelineResponse([]));
		await flushPromises();

		expect(wrapper.get('[role="status"]').text()).toContain('No posts yet.');
	});

	it('renders posts, content warnings, times and attachment alt text', async () => {
		const bridge = new FakeHostBridge({
			responses: {
				'GET /api/v1/timelines/public': timelineResponse([
					post('one', {
						content: 'Visible <script>alert(1)</script>',
						content_warning: 'Spoilers',
						attachments: [{
							id: 'media-1',
							media_type: 'image',
							mime_type: 'image/png',
							width: 10,
							height: 10,
							byte_size: 100,
							alt_text: 'A cat',
							created_at: '2026-11-11T09:00:00Z',
							url: 'https://social.example/original.png',
							thumbnail_url: 'https://social.example/thumb.png',
						}],
					}),
					post('two', { content: 'Second post', published_at: '2026-11-11T09:59:00Z' }),
				]),
			},
		});

		const wrapper = mountTimeline(bridge);
		await flushPromises();

		expect(wrapper.findAll('article')).toHaveLength(2);
		expect(wrapper.text()).toContain('Spoilers');
		expect(wrapper.find('script').exists()).toBe(false);
		expect(wrapper.get('time').attributes('datetime')).toBe('2026-11-11T10:00:00Z');
		expect(wrapper.get('img').attributes('alt')).toBe('A cat');
		expect(wrapper.get('img').attributes('src')).toBe('https://social.example/thumb.png');
		expect(wrapper.get('[aria-label="Posts"]')).toBeTruthy();
		expect(wrapper.find('[role="status"]').exists()).toBe(false);
	});

	it('renders the empty state', async () => {
		const bridge = new FakeHostBridge({
			responses: {
				'GET /api/v1/timelines/public': timelineResponse([]),
			},
		});
		const wrapper = mountTimeline(bridge);
		await flushPromises();

		expect(wrapper.get('[role="status"]').text()).toContain('No posts yet.');
		expect(wrapper.findAll('article')).toHaveLength(0);
	});

	it('surfaces a core error and recovers through retry', async () => {
		let fail = true;
		const bridge = new FakeHostBridge({
			responses: {
				'GET /api/v1/timelines/public': () => fail
					? errorResponse(503, 'timeline_unavailable', 'The timeline is temporarily unavailable.')
					: timelineResponse([post('one')]),
			},
		});
		const wrapper = mountTimeline(bridge);
		await flushPromises();

		expect(wrapper.get('[role="alert"]').text()).toContain('temporarily unavailable');
		expect(wrapper.findAll('article')).toHaveLength(0);

		fail = false;
		await wrapper.get('button').trigger('click');
		await flushPromises();

		expect(wrapper.findAll('article')).toHaveLength(1);
		expect(wrapper.find('[role="alert"]').exists()).toBe(false);
	});

	it('handles a 401 without redirecting or leaking a token', async () => {
		const bridge = new FakeHostBridge({
			responses: {
				'GET /api/v1/timelines/public': errorResponse(401, 'authentication_required', 'Authentication is required.'),
			},
		});
		const wrapper = mountTimeline(bridge);
		await flushPromises();

		expect(wrapper.get('[role="alert"]').text()).toContain('Authentication is required.');
		expect(wrapper.text()).not.toContain('token');
		expect(bridge.navigations).toHaveLength(0);
	});

	it('loads the next page with the last published timestamp as cursor', async () => {
		const first = post('one', { published_at: '2026-11-11T10:00:00Z' });
		const second = post('two', { published_at: '2026-11-11T09:59:00Z' });
		const third = post('three', { published_at: '2026-11-11T09:58:00Z' });
		const bridge = new FakeHostBridge({
			responses: {
				'GET /api/v1/timelines/public': (request) => request.query?.before === undefined
					? timelineResponse([first, second])
					: timelineResponse([third]),
			},
		});
		const wrapper = mountTimeline(bridge, 2);
		await flushPromises();

		expect(wrapper.findAll('article')).toHaveLength(2);
		await wrapper.get('button').trigger('click');
		await flushPromises();

		expect(bridge.calls[1]?.query).toEqual({
			limit: '2',
			before: '2026-11-11T09:59:00Z',
		});
		expect(wrapper.findAll('article')).toHaveLength(3);
		expect(wrapper.find('button').exists()).toBe(false);
	});

	it('hides the load-more button when an exact page has no successor', async () => {
		const first = post('one');
		const second = post('two', { published_at: '2026-11-11T09:59:00Z' });
		const bridge = new FakeHostBridge({
			responses: {
				'GET /api/v1/timelines/public': (request) => request.query?.before === undefined
					? timelineResponse([first, second])
					: timelineResponse([]),
			},
		});
		const wrapper = mountTimeline(bridge, 2);
		await flushPromises();

		await wrapper.get('button').trigger('click');
		await flushPromises();

		expect(wrapper.findAll('article')).toHaveLength(2);
		expect(wrapper.find('button').exists()).toBe(false);
	});

	it('does not load a page when the first page is shorter than the limit', async () => {
		const bridge = new FakeHostBridge({
			responses: {
				'GET /api/v1/timelines/public': timelineResponse([post('one')]),
			},
		});
		const wrapper = mountTimeline(bridge, 2);
		await flushPromises();

		expect(wrapper.find('button').exists()).toBe(false);
		expect(bridge.calls).toHaveLength(1);
	});
});
