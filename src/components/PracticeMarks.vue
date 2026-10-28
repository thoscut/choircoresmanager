<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Passages to practise (milestone 0.13): remember the shown measures with a
 * name, and show and loop a remembered passage with one click. Remembered
 * in this browser only (score/marks.ts).
 */
import type { Mark } from '../score/marks.ts'

import { mdiBookmarkOutline, mdiClose } from '@mdi/js'
import { t } from '../l10n.ts'
import { ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { addMark, MAX_NAME, storedMarks, storeMarks } from '../score/marks.ts'

const props = defineProps<{
	/** key of the piece the passages belong to */
	piece: string
	/** printed numbers of the shown measures */
	from: string
	to: string
}>()

const emit = defineEmits<{
	/** show and loop a remembered passage */
	apply: [mark: Mark]
	/** a text for the screen reader */
	announce: [text: string]
}>()

const marks = ref<Mark[]>(storedMarks(props.piece))
const name = ref('')

watch(() => props.piece, (piece) => {
	marks.value = storedMarks(piece)
})

/**
 * The measures of a passage, e.g. "m. 5–12".
 *
 * @param mark - the passage
 */
function measuresOf(mark: Pick<Mark, 'from' | 'to'>): string {
	return mark.from === mark.to
		? t('notenarchiv', 'm. {measure}', { measure: mark.from })
		: t('notenarchiv', 'm. {from}–{to}', { from: mark.from, to: mark.to })
}

function remember(): void {
	const measures = measuresOf(props)
	marks.value = addMark(marks.value, { name: name.value, from: props.from, to: props.to }, measures)
	storeMarks(props.piece, marks.value)
	name.value = ''
	emit('announce', t('notenarchiv', 'Passage {measures} remembered in this browser.', { measures }))
}

/**
 * Name of the button that forgets a passage.
 *
 * @param mark - the passage
 */
function removeLabel(mark: Mark): string {
	return t('notenarchiv', 'Remove passage "{name}"', { name: mark.name })
}

/**
 * Forget a passage.
 *
 * @param mark - the passage
 */
function forget(mark: Mark): void {
	marks.value = marks.value.filter((other) => other !== mark)
	storeMarks(props.piece, marks.value)
	emit('announce', t('notenarchiv', 'Passage "{name}" removed.', { name: mark.name }))
}
</script>

<template>
	<div class="practice-marks">
		<form class="practice-marks__new" @submit.prevent="remember">
			<div class="practice-marks__name">
				<NcTextField v-model="name"
					:label="t('notenarchiv', 'Name of the passage')"
					:maxlength="MAX_NAME" />
			</div>
			<NcButton type="submit">
				<template #icon>
					<NcIconSvgWrapper :path="mdiBookmarkOutline" />
				</template>
				{{ t('notenarchiv', 'Remember {measures}', { measures: measuresOf(props) }) }}
			</NcButton>
		</form>
		<ul v-if="marks.length > 0"
			class="practice-marks__list"
			:aria-label="t('notenarchiv', 'Remembered passages')">
			<li v-for="mark in marks" :key="`${mark.from}-${mark.to}`">
				<NcButton variant="secondary" @click="emit('apply', mark)">
					{{ mark.name }}
					<template v-if="mark.name !== measuresOf(mark)">
						({{ measuresOf(mark) }})
					</template>
				</NcButton>
				<NcButton variant="tertiary"
					:aria-label="removeLabel(mark)"
					@click="forget(mark)">
					<template #icon>
						<NcIconSvgWrapper :path="mdiClose" />
					</template>
				</NcButton>
			</li>
		</ul>
		<p class="practice-marks__hint">
			{{ t('notenarchiv', 'A remembered passage is shown and played in a loop. The passages are kept in this browser only.') }}
		</p>
	</div>
</template>

<style scoped>
.practice-marks {
	display: flex;
	flex-direction: column;
	gap: 4px;
	flex-basis: 100%;
}

.practice-marks__new {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: flex-end;
}

.practice-marks__name {
	width: 16em;
	max-width: 100%;
}

.practice-marks__list {
	display: flex;
	flex-wrap: wrap;
	gap: 4px 12px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.practice-marks__list li {
	display: flex;
	align-items: center;
}

.practice-marks__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 13px);
}
</style>
