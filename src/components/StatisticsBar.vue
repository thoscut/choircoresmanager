<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * A figure of a statistics table with a bar beside it. The figure is the
 * information (text, read by screen readers, printed); the bar is decoration
 * for a quick comparison and hidden from assistive technology. It is drawn
 * with a border in the text colour, so that it also prints in black and
 * white and follows the high-contrast settings.
 */
import { computed } from 'vue'
import { barWidth, formatNumber } from '../statistics.ts'

const props = withDefaults(defineProps<{
	value: number
	/** the largest figure of the table: the full bar */
	max: number
	/** text shown instead of the plain number */
	text?: string
}>(), {
	text: '',
})

const width = computed(() => barWidth(props.value, props.max))
</script>

<template>
	<span class="statistics-bar">
		<span class="statistics-bar__value">{{ text || formatNumber(value) }}</span>
		<span class="statistics-bar__track" aria-hidden="true">
			<span v-if="width > 0" class="statistics-bar__fill" :style="{ width: width + '%' }" />
		</span>
	</span>
</template>

<style scoped>
.statistics-bar {
	display: inline-flex;
	align-items: center;
	gap: calc(2 * var(--default-grid-baseline));
	width: 100%;
}

.statistics-bar__value {
	flex: 0 0 auto;
	min-width: 3.5ch;
	font-variant-numeric: tabular-nums;
	text-align: end;
}

.statistics-bar__track {
	display: block;
	flex: 1 1 auto;
	min-width: 2em;
	max-width: 12em;
}

.statistics-bar__fill {
	display: block;
	height: 0;
	/* a border, not a background: printed in black and white as well */
	border-top: 0.75em solid currentColor;
	color: var(--color-primary-element);
}

@media print {
	.statistics-bar__fill {
		color: #000;
	}
}
</style>
