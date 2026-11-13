/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { flushPromises, mount } from '@vue/test-utils';
import {
	createAlohaUi,
	FakeHostBridge,
	PostComposer,
} from '../src/index.js';
import { post } from './support.js';

function mountComposer(bridge: FakeHostBridge, props: Record<string, unknown> = {}) {
	return mount(PostComposer, {
		props,
		global: { plugins: [createAlohaUi({ bridge })] },
	});
}

function createdResponse() {
	return {
		status: 201,
		headers: { 'content-type': 'application/json', location: '/api/v1/posts/new' },
		body: JSON.stringify({ post: post('new') }),
	};
}

describe('PostComposer', () => {
	it('submits content, visibility and a content warning', async () => {
		const bridge = new FakeHostBridge({
			user: { id: 'alice', handle: 'alice' },
			responses: {
				'POST /api/v1/posts': createdResponse(),
			},
		});
		const wrapper = mountComposer(bridge);
		await flushPromises();

		await wrapper.get('textarea').setValue('Hello world');
		await wrapper.get('select').setValue('followers');
		await wrapper.get('input[type="checkbox"]').setValue(true);
		await wrapper.get('input[type="text"]').setValue('spoilers');
		await wrapper.get('form').trigger('submit');
		await flushPromises();

		expect(bridge.calls).toHaveLength(1);
		expect(bridge.calls[0]).toMatchObject({
			method: 'POST',
			path: '/api/v1/posts',
			body: {
				content: 'Hello world',
				visibility: 'followers',
				content_warning: 'spoilers',
			},
		});
		expect(wrapper.emitted('created')).toHaveLength(1);
		expect(wrapper.emitted('created')?.[0]?.[0]).toMatchObject({ id: 'new' });
		expect(wrapper.text()).toContain('Posted.');
		expect((wrapper.get('textarea').element as HTMLTextAreaElement).value).toBe('');
	});

	it('keeps an empty draft from making a request', async () => {
		const bridge = new FakeHostBridge({
			user: { id: 'alice', handle: 'alice' },
			responses: {
				'POST /api/v1/posts': createdResponse(),
			},
		});
		const wrapper = mountComposer(bridge);
		await flushPromises();

		expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeDefined();
		await wrapper.get('form').trigger('submit');
		await flushPromises();

		expect(bridge.calls).toHaveLength(0);
		expect(wrapper.emitted('created')).toBeUndefined();
	});

	it('shows the core field errors and preserves the draft', async () => {
		const bridge = new FakeHostBridge({
			user: { id: 'alice', handle: 'alice' },
			responses: {
				'POST /api/v1/posts': {
					status: 422,
					body: JSON.stringify({
						error: {
							code: 'validation_failed',
							message: 'The request is not valid.',
							fields: { content: 'A post may be at most 5000 characters long.' },
						},
					}),
				},
			},
		});
		const wrapper = mountComposer(bridge);
		await flushPromises();

		await wrapper.get('textarea').setValue('Draft');
		await wrapper.get('form').trigger('submit');
		await flushPromises();

		expect(wrapper.get('[role="alert"]').text()).toContain('The request is not valid.');
		expect(wrapper.get('[role="alert"]').text()).toContain('A post may be at most 5000 characters long.');
		expect((wrapper.get('textarea').element as HTMLTextAreaElement).value).toBe('Draft');
		expect(wrapper.emitted('created')).toBeUndefined();
	});

	it('asks a signed-out visitor to sign in instead of showing a form', async () => {
		const bridge = new FakeHostBridge({ user: null });
		const wrapper = mountComposer(bridge);
		await flushPromises();

		expect(wrapper.find('form').exists()).toBe(false);
		expect(wrapper.text()).toContain('Sign in to post');
		expect(wrapper.get('a').attributes('href')).toContain('/login?return_to=');
		expect(bridge.calls).toHaveLength(0);
	});

	it('blocks an over-limit draft before the request', async () => {
		const bridge = new FakeHostBridge({
			user: { id: 'alice', handle: 'alice' },
			responses: {
				'POST /api/v1/posts': createdResponse(),
			},
		});
		const wrapper = mountComposer(bridge);
		await flushPromises();

		await wrapper.get('textarea').setValue('a'.repeat(5001));

		expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeDefined();
		expect(wrapper.get('textarea').attributes('aria-invalid')).toBe('true');
		expect(wrapper.text()).toContain('5001 / 5000');
	});

	it('sends a reply target when the host provides one', async () => {
		const bridge = new FakeHostBridge({
			user: { id: 'alice', handle: 'alice' },
			responses: {
				'POST /api/v1/posts': createdResponse(),
			},
		});
		const wrapper = mountComposer(bridge, { inReplyToId: 'parent' });
		await flushPromises();

		await wrapper.get('textarea').setValue('Reply');
		await wrapper.get('form').trigger('submit');
		await flushPromises();

		expect(bridge.calls[0]?.body).toMatchObject({
			content: 'Reply',
			in_reply_to_id: 'parent',
		});
	});
});
