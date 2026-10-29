<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Tasks across copies, in shelf order (binder, number) as the server sorts
 * them, so that a printed list follows the shelf.
 */
import type { Task } from '../types.ts'

import TaskItem from './TaskItem.vue'

withDefaults(defineProps<{
	tasks: Task[]
	highlightedId?: number | null
}>(), {
	highlightedId: null,
})

const emit = defineEmits<{
	changed: []
}>()
</script>

<template>
	<ol class="task-list">
		<li v-for="task in tasks" :key="task.id">
			<TaskItem :task="task"
				show-copy
				can-show-score
				:highlighted="task.id === highlightedId"
				@changed="emit('changed')" />
		</li>
	</ol>
</template>

<style scoped>
.task-list {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(min(100%, 26em), 1fr));
	gap: calc(2 * var(--default-grid-baseline));
	margin: 0;
	padding: 0;
	list-style: none;
}

@media print {
	.task-list {
		display: block;
	}

	.task-list li {
		margin-bottom: 3mm;
	}
}
</style>
