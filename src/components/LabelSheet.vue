<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Shelf labels for printing on Avery 3653 and compatible sheets (2 × 7
 * labels of 105 × 42.3 mm on A4): choice of the first free place of a
 * partly used sheet, a preview and the print. Only the sheets are printed:
 * they are rendered outside the app and shown only while printing labels.
 */
import type { ShelfLabel } from '../types.ts'

import { mdiPrinterOutline } from '@mdi/js'
import { n, t } from '../l10n.ts'
import { computed, onBeforeUnmount, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import LabelSheetPage from './LabelSheetPage.vue'
import { LABELS_PER_SHEET, labelSheets } from '../maintenance.ts'

const props = defineProps<{
	labels: ShelfLabel[]
}>()

/** First free place on the first sheet (1 … 14). */
const start = ref(1)

const sheets = computed(() => labelSheets(props.labels, start.value))

const places = Array.from({ length: LABELS_PER_SHEET }, (_, index) => index + 1)

/**
 * Accessible name of a place on the sheet.
 *
 * @param place - 1 … 14
 */
function placeName(place: number): string {
	const row = Math.ceil(place / 2)
	return place % 2 === 1
		? t('notenarchiv', 'Place {place}: row {row}, left', { place, row })
		: t('notenarchiv', 'Place {place}: row {row}, right', { place, row })
}

const BODY_CLASS = 'notenarchiv-print-labels'
let pageStyle: HTMLStyleElement | null = null

function cleanUp(): void {
	document.body.classList.remove(BODY_CLASS)
	pageStyle?.remove()
	pageStyle = null
}

/** Print only the label sheets, without page margins. */
function print(): void {
	cleanUp()
	// A page rule added last wins over the app's A4 margins
	pageStyle = document.createElement('style')
	pageStyle.textContent = '@page { size: A4 portrait; margin: 0; }'
	document.head.appendChild(pageStyle)
	document.body.classList.add(BODY_CLASS)
	window.addEventListener('afterprint', cleanUp, { once: true })
	window.print()
}

onBeforeUnmount(() => {
	window.removeEventListener('afterprint', cleanUp)
	cleanUp()
})
</script>

<template>
	<div class="label-sheet">
		<fieldset class="label-sheet__start">
			<legend>{{ t('notenarchiv', 'First free place on the sheet') }}</legend>
			<p class="label-sheet__hint">
				{{ t('notenarchiv', 'For a sheet that has already been used in part.') }}
			</p>
			<div class="label-sheet__places">
				<label v-for="place in places"
					:key="place"
					:for="`label-sheet-place-${place}`"
					class="label-sheet__place"
					:class="{ 'label-sheet__place--used': place < start, 'label-sheet__place--start': place === start }">
					<input :id="`label-sheet-place-${place}`"
						v-model="start"
						type="radio"
						name="label-sheet-start"
						:value="place"
						:aria-label="placeName(place)">
					<span aria-hidden="true">{{ place }}</span>
				</label>
			</div>
		</fieldset>

		<div class="label-sheet__summary">
			<p>
				{{ n('notenarchiv', '%n label', '%n labels', labels.length) }} ·
				{{ n('notenarchiv', '%n sheet', '%n sheets', sheets.length) }}
			</p>
			<NcButton variant="primary" :disabled="labels.length === 0" @click="print">
				<template #icon>
					<NcIconSvgWrapper :path="mdiPrinterOutline" />
				</template>
				{{ t('notenarchiv', 'Print labels') }}
			</NcButton>
		</div>
		<p class="label-sheet__hint">
			{{ t('notenarchiv', 'For Avery 3653 and compatible sheets (2 × 7 labels of 105 × 42.3 mm on A4). Print at actual size (100 %), without fitting to the page and without headers and footers.') }}
		</p>

		<div v-if="sheets.length > 0"
			class="label-sheet__preview"
			role="img"
			:aria-label="t('notenarchiv', 'Preview of the first sheet')">
			<LabelSheetPage :places="sheets[0]" />
		</div>

		<Teleport to="body">
			<div class="notenarchiv-label-print">
				<LabelSheetPage v-for="(sheet, index) in sheets"
					:key="index"
					class="notenarchiv-label-print__page"
					:places="sheet" />
			</div>
		</Teleport>
	</div>
</template>

<style scoped>
.label-sheet {
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));
}

.label-sheet__start {
	margin: 0;
	padding: 0;
	border: none;
}

.label-sheet__start legend {
	padding: 0;
	font-weight: bold;
}

.label-sheet__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.label-sheet__places {
	display: grid;
	grid-template-columns: repeat(2, 5em);
	gap: 2px;
	margin-top: var(--default-grid-baseline);
}

.label-sheet__place {
	position: relative;
	display: flex;
	align-items: center;
	justify-content: center;
	min-height: var(--default-clickable-area);
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-small, 4px);
	cursor: pointer;
	font-variant-numeric: tabular-nums;
}

.label-sheet__place input {
	position: absolute;
	inset: 0;
	margin: 0;
	opacity: 0;
	cursor: pointer;
}

/* used places: hatched, not only lighter */
.label-sheet__place--used {
	background: repeating-linear-gradient(-45deg, transparent 0 4px, var(--color-background-darker) 4px 8px);
	color: var(--color-text-maxcontrast);
	text-decoration: line-through;
}

.label-sheet__place--start {
	border: 2px solid var(--color-primary-element);
	font-weight: bold;
}

.label-sheet__place:has(input:focus-visible) {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.label-sheet__summary {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: calc(2 * var(--default-grid-baseline));
}

.label-sheet__summary p {
	margin: 0;
}

.label-sheet__preview {
	align-self: flex-start;
	max-width: 100%;
	overflow: hidden;
	border: 1px solid var(--color-border-maxcontrast);
	/* the sheet at about 40 % */
	zoom: 0.4;
}
</style>

<style>
/* The printed sheets live outside the app and appear only when printing labels */
.notenarchiv-label-print {
	display: none;
}

@media print {
	body.notenarchiv-print-labels {
		margin: 0 !important;
		padding: 0 !important;
		background: #fff !important;
	}

	body.notenarchiv-print-labels > :not(.notenarchiv-label-print) {
		display: none !important;
	}

	body.notenarchiv-print-labels > .notenarchiv-label-print {
		display: block !important;
	}

	.notenarchiv-label-print__page {
		/* a hair below 297 mm, so that rounding never adds an empty page */
		height: 296.6mm !important;
		break-after: page;
		break-inside: avoid;
	}

	.notenarchiv-label-print__page:last-child {
		break-after: auto;
	}
}
</style>
