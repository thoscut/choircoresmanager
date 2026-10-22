<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * A list in a report (shelf marks, paths, warnings), folded when long.
 */
import { computed } from 'vue'

const props = withDefaults(defineProps<{
	/** Summary text including the number of items */
	summary: string
	items: string[]
	/** Up to this many items the list is open from the start */
	openUpTo?: number
	/** Show items inline, separated by commas (for shelf marks) */
	inline?: boolean
}>(), {
	openUpTo: 10,
	inline: false,
})

const open = computed(() => props.items.length <= props.openUpTo)
</script>

<template>
	<details class="report-list" :open="open">
		<summary>{{ summary }}</summary>
		<p v-if="inline" class="report-list__inline">
			{{ items.join(', ') }}
		</p>
		<ul v-else class="report-list__items">
			<li v-for="(item, index) in items" :key="index">
				{{ item }}
			</li>
		</ul>
	</details>
</template>

<style scoped>
.report-list summary {
	cursor: pointer;
	font-weight: bold;
	padding-block: var(--default-grid-baseline);
}

.report-list__items {
	max-height: 240px;
	overflow-y: auto;
	padding-inline-start: calc(5 * var(--default-grid-baseline));
	list-style: disc;
}

.report-list__items li,
.report-list__inline {
	overflow-wrap: anywhere;
}
</style>
