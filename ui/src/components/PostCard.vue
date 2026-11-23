<script setup lang="ts">
import { computed } from 'vue';
import type { Post } from '../types.js';

const props = defineProps<{ post: Post }>();

const formattedDate = computed(() => formatDate(props.post.published_at));

function formatDate(value: string): string {
	const date = new Date(value);
	if (Number.isNaN(date.getTime())) {
		return value;
	}

	return new Intl.DateTimeFormat(undefined, {
		year: 'numeric',
		month: 'short',
		day: 'numeric',
		hour: '2-digit',
		minute: '2-digit',
	}).format(date);
}
</script>

<template>
	<article class="aloha-post-card" :aria-label="`Post by ${post.author_id}`">
		<header class="aloha-post-card__header">
			<span class="aloha-post-card__author">@{{ post.author_id }}</span>
			<time class="aloha-post-card__time" :datetime="post.published_at">{{ formattedDate }}</time>
		</header>

		<details v-if="post.content_warning" class="aloha-post-card__warning">
			<summary>Content warning: {{ post.content_warning }}</summary>
			<p class="aloha-post-card__content">{{ post.content }}</p>
		</details>
		<p v-else class="aloha-post-card__content">{{ post.content }}</p>

		<fieldset v-if="post.poll" class="aloha-post-card__poll">
			<legend>{{ post.poll.multiple ? 'Poll (multiple choice)' : 'Poll' }}</legend>
			<ul>
				<li v-for="(option, index) in post.poll.options" :key="index">
					{{ option.title }} <span>({{ option.votes_count }} votes)</span>
				</li>
			</ul>
		</fieldset>

		<ul v-if="post.attachments?.length" class="aloha-post-card__attachments" aria-label="Attachments">
			<li v-for="attachment in post.attachments" :key="attachment.id">
				<img
					:src="attachment.thumbnail_url ?? attachment.url"
					:alt="attachment.alt_text ?? ''"
					loading="lazy"
					decoding="async"
				/>
			</li>
		</ul>
	</article>
</template>

<style scoped>
.aloha-post-card {
	background: var(--aloha-post-background, transparent);
	border: 1px solid var(--aloha-border-color, #d9dee7);
	border-radius: 0.5rem;
	padding: 1rem;
}

.aloha-post-card__header {
	display: flex;
	flex-wrap: wrap;
	gap: 0.5rem 1rem;
	align-items: baseline;
	margin-bottom: 0.75rem;
}

.aloha-post-card__author {
	font-weight: 700;
	overflow-wrap: anywhere;
}

.aloha-post-card__time {
	color: var(--aloha-muted-text, #5f6b7a);
	font-size: 0.875rem;
}

.aloha-post-card__content {
	margin: 0;
	overflow-wrap: anywhere;
	white-space: pre-wrap;
}

.aloha-post-card__warning {
	margin: 0;
}

.aloha-post-card__warning summary {
	cursor: pointer;
	font-weight: 600;
}

.aloha-post-card__warning .aloha-post-card__content {
	margin-top: 0.75rem;
}

.aloha-post-card__poll {
	margin: 1rem 0 0;
	padding: 0.75rem;
	border: 1px solid var(--aloha-border-color, #d9dee7);
	border-radius: 0.5rem;
}

.aloha-post-card__poll ul {
	margin: 0;
	padding-left: 1.25rem;
}

.aloha-post-card__attachments {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr));
	gap: 0.5rem;
	list-style: none;
	margin: 1rem 0 0;
	padding: 0;
}

.aloha-post-card__attachments img {
	display: block;
	width: 100%;
	height: auto;
	border-radius: 0.5rem;
}
</style>
