<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * One A4 sheet of shelf labels (Avery 3653 and compatible: 2 columns × 7
 * rows of 105 × 42.3 mm). Measures in millimetres, so that the printout
 * fits the labels at 100 % scale.
 */
import type { ShelfLabel } from '../types.ts'

import { t } from '../l10n.ts'

defineProps<{
	/** 14 places; null = empty place */
	places: (ShelfLabel | null)[]
}>()

/**
 * Arranger line: the recorded persons, otherwise the text of the catalog.
 *
 * @param label - label data
 */
function arrangerText(label: ShelfLabel): string {
	if (label.arrangers.length > 0) {
		return t('notenarchiv', 'Arr. {names}', { names: label.arrangers.join(', ') })
	}
	return label.arranger_text ?? ''
}
</script>

<template>
	<div class="label-page">
		<div v-for="(label, index) in places"
			:key="index"
			class="label-page__place"
			:class="{ 'label-page__place--empty': label === null }">
			<template v-if="label">
				<span class="label-page__shelfmark">{{ label.label }}</span>
				<span class="label-page__title">{{ label.title }}</span>
				<span v-if="label.composer" class="label-page__line">{{ label.composer }}</span>
				<span v-if="arrangerText(label)" class="label-page__line label-page__line--small">{{ arrangerText(label) }}</span>
			</template>
		</div>
	</div>
</template>

<style scoped>
.label-page {
	display: grid;
	grid-template-columns: 105mm 105mm;
	grid-template-rows: repeat(7, 42.3mm);
	box-sizing: border-box;
	width: 210mm;
	height: 297mm;
	/* 297 mm − 7 × 42.3 mm = 0.9 mm, split above and below */
	padding-top: 0.45mm;
	overflow: hidden;
	background: #fff;
	color: #000;
	font-family: var(--font-face, sans-serif);
}

.label-page__place {
	display: flex;
	flex-direction: column;
	justify-content: center;
	gap: 0.8mm;
	box-sizing: border-box;
	min-width: 0;
	height: 42.3mm;
	/* stays inside the printable area of most printers */
	padding: 4.5mm 7mm;
	overflow: hidden;
	line-height: 1.2;
}

.label-page__shelfmark {
	font-size: 22pt;
	font-weight: bold;
	letter-spacing: 0.02em;
}

.label-page__title {
	display: -webkit-box;
	overflow: hidden;
	font-size: 12pt;
	font-weight: bold;
	-webkit-box-orient: vertical;
	-webkit-line-clamp: 2;
	line-clamp: 2;
}

.label-page__line {
	overflow: hidden;
	font-size: 10pt;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.label-page__line--small {
	font-size: 9pt;
}
</style>
