/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { flushPromises, mount } from '@vue/test-utils';
import {
	createAlohaUi,
	FakeHostBridge,
	NotificationList,
} from '../src/index.js';
import {
	errorResponse,
	notification,
	notificationPageResponse,
} from './support.js';

function mountList(bridge: FakeHostBridge) {
	return mount(NotificationList, {
		global: { plugins: [createAlohaUi({ bridge })] },
	});
}

function buttonByText(wrapper: ReturnType<typeof mountList>, text: string) {
	const button = wrapper.findAll('button').find((candidate) => candidate.text() === text);
	if (button === undefined) {
		throw new Error(`No button with text ${text}.`);
	}

	return button;
}

describe('NotificationList', () => {
	it('renders notifications, dismisses one and emits post navigation', async () => {
		const bridge = new FakeHostBridge({
			user: { id: 'alice', handle: 'alice' },
			responses: {
				'GET /api/v1/notifications': notificationPageResponse([
					notification('n1'),
					notification('n2', {
						type: 'mention',
						actor_id: 'carol',
						post_id: 'post-1',
					}),
				], 2),
				'POST /api/v1/notifications/n1/dismiss': {
					status: 200,
					body: JSON.stringify({ dismissed: true }),
				},
			},
		});
		const wrapper = mountList(bridge);
		await flushPromises();

		expect(wrapper.text()).toContain('2 unread');
		expect(wrapper.findAll('li')).toHaveLength(2);
		expect(wrapper.text()).toContain('@bob followed you');
		expect(wrapper.text()).toContain('@carol mentioned you');

		await buttonByText(wrapper, 'View post').trigger('click');
		expect(wrapper.emitted('open-post')?.[0]).toEqual(['post-1']);

		await wrapper.get('[aria-label="Dismiss notification from bob"]').trigger('click');
		await flushPromises();

		expect(bridge.calls[1]).toMatchObject({
			method: 'POST',
			path: '/api/v1/notifications/n1/dismiss',
		});
		expect(wrapper.findAll('li')).toHaveLength(1);
		expect(wrapper.text()).toContain('1 unread');
		expect(wrapper.text()).not.toContain('@bob followed you');
	});

	it('clears all notifications and shows the empty state', async () => {
		const bridge = new FakeHostBridge({
			user: { id: 'alice', handle: 'alice' },
			responses: {
				'GET /api/v1/notifications': notificationPageResponse([notification('n1')], 1),
				'POST /api/v1/notifications/clear': {
					status: 200,
					body: JSON.stringify({ cleared: 1 }),
				},
			},
		});
		const wrapper = mountList(bridge);
		await flushPromises();

		await buttonByText(wrapper, 'Clear all').trigger('click');
		await flushPromises();

		expect(bridge.calls[1]).toMatchObject({
			method: 'POST',
			path: '/api/v1/notifications/clear',
		});
		expect(wrapper.text()).toContain('No notifications yet.');
		expect(wrapper.findAll('li')).toHaveLength(0);
		expect(wrapper.text()).toContain('0 unread');
	});

	it('does not request notifications for a signed-out visitor', async () => {
		const bridge = new FakeHostBridge({ user: null });
		const wrapper = mountList(bridge);
		await flushPromises();

		expect(wrapper.text()).toContain('Sign in to see notifications');
		expect(wrapper.get('a').attributes('href')).toContain('/login?return_to=');
		expect(bridge.calls).toHaveLength(0);
	});

	it('turns a 401 into the sign-in state', async () => {
		const bridge = new FakeHostBridge({
			user: { id: 'alice', handle: 'alice' },
			responses: {
				'GET /api/v1/notifications': errorResponse(
					401,
					'authentication_required',
					'Authentication is required.',
				),
			},
		});
		const wrapper = mountList(bridge);
		await flushPromises();

		expect(wrapper.text()).toContain('Sign in to see notifications');
		expect(wrapper.find('[role="alert"]').exists()).toBe(false);
	});

	it('shows an empty page', async () => {
		const bridge = new FakeHostBridge({
			user: { id: 'alice', handle: 'alice' },
			responses: {
				'GET /api/v1/notifications': notificationPageResponse([], 0),
			},
		});
		const wrapper = mountList(bridge);
		await flushPromises();

		expect(wrapper.text()).toContain('No notifications yet.');
		expect(wrapper.text()).toContain('0 unread');
	});

	it('loads the next page with the last notification id', async () => {
		const first = Array.from({ length: 20 }, (_, index) => notification(`n${index}`));
		const bridge = new FakeHostBridge({
			user: { id: 'alice', handle: 'alice' },
			responses: {
				'GET /api/v1/notifications': notificationPageResponse(first, 21),
				'GET /api/v1/notifications?before=n19&limit=20': notificationPageResponse(
					[notification('n20')],
					21,
				),
			},
		});
		const wrapper = mountList(bridge);
		await flushPromises();

		expect(wrapper.findAll('li')).toHaveLength(20);
		await buttonByText(wrapper, 'Load more').trigger('click');
		await flushPromises();

		expect(bridge.calls[1]).toMatchObject({
			method: 'GET',
			path: '/api/v1/notifications',
			query: { limit: '20', before: 'n19' },
		});
		expect(wrapper.findAll('li')).toHaveLength(21);
		expect(wrapper.text()).not.toContain('Load more');
	});
});
