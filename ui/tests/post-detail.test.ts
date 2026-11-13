/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { flushPromises, mount } from '@vue/test-utils';
import {
	createAlohaUi,
	FakeHostBridge,
	PostDetail,
} from '../src/index.js';
import { errorResponse, post } from './support.js';

function mountDetail(bridge: FakeHostBridge, postId = 'one') {
	return mount(PostDetail, {
		props: { postId },
		global: { plugins: [createAlohaUi({ bridge })] },
	});
}

function postResponse(id: string) {
	return {
		status: 200,
		headers: { 'content-type': 'application/json' },
		body: JSON.stringify({ post: post(id) }),
	};
}

describe('PostDetail', () => {
	it('renders the loading state before the post response', async () => {
		let resolveResponse: ((response: ReturnType<typeof postResponse>) => void) | undefined;
		const pending = new Promise<ReturnType<typeof postResponse>>((resolve) => {
			resolveResponse = resolve;
		});
		const bridge = new FakeHostBridge({
			responses: {
				'GET /api/v1/posts/one': () => pending,
			},
		});

		const wrapper = mountDetail(bridge);

		expect(wrapper.get('[role="status"]').text()).toContain('Loading post');
		expect(wrapper.find('article').exists()).toBe(false);

		resolveResponse?.(postResponse('one'));
		await flushPromises();

		expect(wrapper.findAll('article')).toHaveLength(1);
		expect(wrapper.text()).toContain('Post one');
		expect(wrapper.find('[role="status"]').exists()).toBe(false);
		expect(bridge.calls[0]?.path).toBe('/api/v1/posts/one');
	});

	it('surfaces a core error and recovers through retry', async () => {
		let fail = true;
		const bridge = new FakeHostBridge({
			responses: {
				'GET /api/v1/posts/one': () => fail
					? errorResponse(503, 'post_unavailable', 'The post is temporarily unavailable.')
					: postResponse('one'),
			},
		});
		const wrapper = mountDetail(bridge);
		await flushPromises();

		expect(wrapper.get('[role="alert"]').text()).toContain('temporarily unavailable');
		expect(wrapper.findAll('article')).toHaveLength(0);

		fail = false;
		await wrapper.get('button').trigger('click');
		await flushPromises();

		expect(wrapper.findAll('article')).toHaveLength(1);
		expect(wrapper.find('[role="alert"]').exists()).toBe(false);
		expect(bridge.calls).toHaveLength(2);
	});

	it('keeps a 404 as a clear not-found alert', async () => {
		const bridge = new FakeHostBridge({
			responses: {
				'GET /api/v1/posts/missing': errorResponse(404, 'not_found', 'Post not found.'),
			},
		});
		const wrapper = mountDetail(bridge, 'missing');
		await flushPromises();

		expect(wrapper.get('[role="alert"]').text()).toContain('Post not found.');
		expect(wrapper.find('article').exists()).toBe(false);
	});

	it('does not navigate or leak a token on 401', async () => {
		const bridge = new FakeHostBridge({
			responses: {
				'GET /api/v1/posts/one': errorResponse(401, 'authentication_required', 'Authentication is required.'),
			},
		});
		const wrapper = mountDetail(bridge);
		await flushPromises();

		expect(wrapper.get('[role="alert"]').text()).toContain('Authentication is required.');
		expect(wrapper.text()).not.toContain('token');
		expect(bridge.navigations).toHaveLength(0);
	});

	it('validates an empty post id before asking the bridge', async () => {
		const bridge = new FakeHostBridge();
		const wrapper = mountDetail(bridge, '');
		await flushPromises();

		expect(wrapper.get('[role="alert"]').text()).toContain('A post id is required.');
		expect(bridge.calls).toHaveLength(0);
	});

	it('reloads when the post id changes and ignores a late previous response', async () => {
		const resolvers: Array<(response: ReturnType<typeof postResponse>) => void> = [];
		const bridge = new FakeHostBridge({
			responses: {
				'GET /api/v1/posts/one': () => new Promise((resolve) => {
					resolvers.push(resolve);
				}),
				'GET /api/v1/posts/two': () => new Promise((resolve) => {
					resolvers.push(resolve);
				}),
			},
		});
		const wrapper = mountDetail(bridge);
		await flushPromises();

		await wrapper.setProps({ postId: 'two' });
		await flushPromises();

		resolvers.at(-1)?.(postResponse('two'));
		await flushPromises();
		expect(wrapper.text()).toContain('Post two');

		resolvers[0]?.(postResponse('one'));
		await flushPromises();
		expect(wrapper.text()).toContain('Post two');
		expect(wrapper.text()).not.toContain('Post one');
		expect(bridge.calls.map((call) => call.path)).toEqual([
			'/api/v1/posts/one',
			'/api/v1/posts/two',
		]);
	});
});
