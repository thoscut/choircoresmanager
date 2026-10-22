<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * Review of a transcription in the score view (milestone 0.6): the review
 * points of the version with their place in the score, the newest report
 * (review state, origin, rights) and the decisions. Selecting a point is
 * handled by the score view (measures, focus voice); this panel decides
 * points and says the result in its status line.
 *
 * Musical points are decided by the conductor, material and rights points
 * by the catalog librarian, who may decide every point. The server checks
 * every decision again.
 */
import type { PointFilter } from '../review.ts'
import type { ReviewDecision, ReviewPoint, VersionReview } from '../types.ts'

import { mdiPencilOutline, mdiPlay } from '@mdi/js'
import { n, t } from '../l10n.ts'
import { computed, ref, useId, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcRadioGroup from '@nextcloud/vue/components/NcRadioGroup'
import NcRadioGroupButton from '@nextcloud/vue/components/NcRadioGroupButton'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import { decideReviewPoint, errorMessage } from '../api.ts'
import { formatDate, formatDateTime } from '../format.ts'
import { roleLabel } from '../labels.ts'
import {
	allowedDecisions,
	decisionLabel,
	filterPoints,
	isUnresolved,
	pointMeasures,
	pointPlace,
	reviewCategoryLabel,
	reviewStatusLabel,
} from '../review.ts'

const props = defineProps<{
	review: VersionReview | null
	loading: boolean
	error: string
	/** id of the selected point */
	selectedId: number | null
	/** playback is allowed for this version */
	canPlay: boolean
	/** small corrections in the app are possible */
	canCorrect: boolean
	/** questions and tasks are switched on */
	tasksActive: boolean
}>()

const emit = defineEmits<{
	select: [point: ReviewPoint]
	play: [point: ReviewPoint]
	correct: [point: ReviewPoint]
	/** a point was decided: reload */
	changed: []
	retry: []
}>()

const headingId = useId()
const detailHeadingId = useId()

const filter = ref<PointFilter>('open')

const points = computed(() => props.review?.points ?? [])
const shown = computed(() => filterPoints(points.value, filter.value))
const selected = computed(() => points.value.find((point) => point.id === props.selectedId) ?? null)

/** The newest report that is not superseded. */
const report = computed(() => props.review?.reports.find((candidate) => candidate.superseded_by === null) ?? props.review?.reports[0] ?? null)
const earlierReports = computed(() => Math.max(0, (props.review?.reports.length ?? 0) - 1))

const countsText = computed(() => {
	const counts = props.review?.counts
	if (!counts) {
		return ''
	}
	const parts = [n('notenarchiv', '%n open', '%n open', counts.open)]
	if (counts.question > 0) {
		parts.push(n('notenarchiv', '%n question', '%n questions', counts.question))
	}
	if (counts.correct > 0) {
		parts.push(n('notenarchiv', '%n correction order', '%n correction orders', counts.correct))
	}
	const done = counts.confirmed + counts.corrected + counts.dismissed
	if (done > 0) {
		parts.push(n('notenarchiv', '%n decided', '%n decided', done))
	}
	return parts.join(' · ')
})

const reportTitle = computed(() => {
	const current = report.value
	if (!current) {
		return ''
	}
	const parts: string[] = []
	if (current.round) {
		parts.push(t('notenarchiv', 'round {round}', { round: current.round }))
	}
	parts.push(current.report_date ? formatDate(current.report_date) : formatDateTime(current.created))
	return t('notenarchiv', 'Report of the transcription ({details})', { details: parts.join(', ') })
})

const persons = computed(() => (report.value?.summary?.persons ?? [])
	.map((person) => {
		const details = [person.rolle, person.lebensdaten].filter((part): part is string => typeof part === 'string' && part.trim() !== '')
		return details.length > 0 ? `${person.name ?? ''} (${details.join(', ')})` : (person.name ?? '')
	})
	.filter((text) => text.trim() !== ''))

const rightsClass = computed(() => {
	switch (report.value?.rights_class) {
	case 'gemeinfrei':
		return t('notenarchiv', 'Public domain')
	case 'geschützt':
		return t('notenarchiv', 'Protected')
	case 'offen':
		return t('notenarchiv', 'Open')
	}
	return report.value?.rights_class ?? ''
})

// ---- decisions ------------------------------------------------------------

const decisions = computed<ReviewDecision[]>(() => (selected.value && props.review
	? allowedDecisions(selected.value, props.review.may, props.tasksActive)
	: []))

const decision = ref<ReviewDecision | null>(null)
const correction = ref('')
const reason = ref('')
const addressee = ref<'conductor' | 'librarian'>('conductor')
const busy = ref(false)
const formError = ref('')
const statusText = ref('')

// Another point starts with an empty form
watch(() => props.selectedId, () => {
	decision.value = null
	correction.value = selected.value?.correction ?? ''
	reason.value = ''
	addressee.value = selected.value?.role === 'conductor' ? 'conductor' : 'librarian'
	formError.value = ''
})

/**
 * Say something in the status line (read by screen readers).
 *
 * @param text - message
 */
function say(text: string): void {
	statusText.value = statusText.value === text ? text + ' ' : text
}

async function saveDecision(): Promise<void> {
	const point = selected.value
	if (!point || decision.value === null || busy.value) {
		return
	}
	if (decision.value === 'correct' && correction.value.trim() === '') {
		formError.value = t('notenarchiv', 'Please describe the correction.')
		return
	}
	if (decision.value === 'dismissed' && reason.value.trim() === '') {
		formError.value = t('notenarchiv', 'Please give a reason.')
		return
	}
	busy.value = true
	formError.value = ''
	try {
		const saved = await decideReviewPoint(point.id, {
			status: decision.value,
			correction: decision.value === 'correct' ? correction.value.trim() : null,
			reason: reason.value.trim() || null,
			addressee: decision.value === 'question' ? addressee.value : null,
		})
		say(t('notenarchiv', 'Saved: {place} is now "{status}".', { place: pointPlace(point), status: reviewStatusLabel(saved.status) }))
		decision.value = null
		reason.value = ''
		emit('changed')
	} catch (error) {
		formError.value = errorMessage(error)
	} finally {
		busy.value = false
	}
}

/**
 * Who decided a point and when.
 *
 * @param point - review point
 */
function decidedText(point: ReviewPoint): string {
	if (point.decided === null) {
		return ''
	}
	const when = formatDateTime(point.decided)
	return point.decided_by_name || point.decided_by
		? t('notenarchiv', '{name}, {date}', { name: point.decided_by_name || point.decided_by || '', date: when })
		: when
}

defineExpose({ say })
</script>

<template>
	<section class="review-panel" :aria-labelledby="headingId">
		<h3 :id="headingId" class="review-panel__heading">
			{{ t('notenarchiv', 'Review of the transcription') }}
		</h3>
		<p role="status" class="review-panel__status">
			{{ statusText }}
		</p>

		<p v-if="loading && !review" class="review-panel__loading">
			<NcLoadingIcon :size="20" />
			{{ t('notenarchiv', 'Loading the review …') }}
		</p>
		<NcNoteCard v-else-if="error !== ''" type="error">
			<p>{{ error }}</p>
			<NcButton @click="emit('retry')">
				{{ t('notenarchiv', 'Try again') }}
			</NcButton>
		</NcNoteCard>

		<template v-else-if="review">
			<p class="review-panel__counts">
				{{ countsText }}
			</p>

			<details v-if="report" class="review-panel__report">
				<summary>{{ reportTitle }}</summary>
				<dl class="review-panel__facts">
					<template v-if="report.review">
						<dt>{{ t('notenarchiv', 'Review state') }}</dt>
						<dd class="review-panel__long">
							{{ report.review }}
						</dd>
					</template>
					<template v-if="report.origin">
						<dt>{{ t('notenarchiv', 'Origin') }}</dt>
						<dd class="review-panel__long">
							{{ report.origin }}
						</dd>
					</template>
					<template v-if="report.summary?.template?.beschreibung">
						<dt>{{ t('notenarchiv', 'Template') }}</dt>
						<dd class="review-panel__long">
							{{ report.summary.template.beschreibung }}
						</dd>
					</template>
					<template v-if="rightsClass">
						<dt>{{ t('notenarchiv', 'Rights according to the report') }}</dt>
						<dd>{{ rightsClass }}</dd>
					</template>
					<template v-if="persons.length > 0">
						<dt>{{ t('notenarchiv', 'Persons involved') }}</dt>
						<dd>
							<ul class="review-panel__plain-list">
								<li v-for="(person, index) in persons" :key="index">
									{{ person }}
								</li>
							</ul>
						</dd>
					</template>
					<template v-if="report.reasoning">
						<dt>{{ t('notenarchiv', 'Reasoning on the rights') }}</dt>
						<dd class="review-panel__long">
							{{ report.reasoning }}
						</dd>
					</template>
					<template v-if="(report.summary?.warnings ?? []).length > 0">
						<dt>{{ t('notenarchiv', 'Notes on reading the report') }}</dt>
						<dd>
							<ul class="review-panel__plain-list">
								<li v-for="(warning, index) in report.summary?.warnings ?? []" :key="index">
									{{ warning }}
								</li>
							</ul>
						</dd>
					</template>
				</dl>
				<p v-if="earlierReports > 0" class="review-panel__hint">
					{{ n('notenarchiv', '%n earlier report of this copy.', '%n earlier reports of this copy.', earlierReports) }}
				</p>
				<p class="review-panel__hint">
					{{ t('notenarchiv', 'Rights and catalog fields of the report wait in the proposals inbox; the catalog only changes when they are accepted.') }}
				</p>
			</details>

			<NcRadioGroup v-model="filter" :label="t('notenarchiv', 'Show review points')">
				<NcRadioGroupButton value="open" :label="t('notenarchiv', 'To do')" />
				<NcRadioGroupButton value="all" :label="t('notenarchiv', 'All')" />
			</NcRadioGroup>

			<p v-if="points.length === 0" class="review-panel__hint">
				{{ t('notenarchiv', 'There are no review points for this version.') }}
			</p>
			<p v-else-if="shown.length === 0" class="review-panel__hint">
				{{ t('notenarchiv', 'Every review point has been decided.') }}
			</p>
			<ul v-else class="review-panel__list" :aria-label="t('notenarchiv', 'Review points')">
				<li v-for="point in shown" :key="point.id">
					<button type="button"
						class="review-panel__point"
						:class="{ 'review-panel__point--selected': point.id === selectedId, 'review-panel__point--done': !isUnresolved(point) }"
						:aria-pressed="point.id === selectedId ? 'true' : 'false'"
						@click="emit('select', point)">
						<span class="review-panel__place">{{ pointPlace(point) }}</span>
						<span class="review-panel__meta">
							{{ reviewCategoryLabel(point.category) }} · {{ reviewStatusLabel(point.status) }}
						</span>
						<span v-if="point.text" class="review-panel__text">{{ point.text }}</span>
					</button>
				</li>
			</ul>

			<section v-if="selected"
				class="review-panel__detail"
				:aria-labelledby="detailHeadingId">
				<h4 :id="detailHeadingId" class="review-panel__detail-heading">
					{{ pointPlace(selected) }}
				</h4>
				<dl class="review-panel__facts">
					<dt>{{ t('notenarchiv', 'Kind') }}</dt>
					<dd>{{ reviewCategoryLabel(selected.category) }}</dd>
					<dt>{{ t('notenarchiv', 'Status') }}</dt>
					<dd>{{ reviewStatusLabel(selected.status) }}</dd>
					<dt>{{ t('notenarchiv', 'Responsible') }}</dt>
					<dd>{{ roleLabel(selected.role) }}</dd>
					<template v-if="selected.text">
						<dt>{{ t('notenarchiv', 'Note of the transcription') }}</dt>
						<dd class="review-panel__long">
							{{ selected.text }}
						</dd>
					</template>
					<template v-if="selected.page">
						<dt>{{ t('notenarchiv', 'Page of the scan') }}</dt>
						<dd>{{ selected.page }}</dd>
					</template>
					<template v-if="selected.correction">
						<dt>{{ t('notenarchiv', 'Correction') }}</dt>
						<dd class="review-panel__long">
							{{ selected.correction }}
						</dd>
					</template>
					<template v-if="selected.decided !== null">
						<dt>{{ t('notenarchiv', 'Decision') }}</dt>
						<dd>
							{{ decidedText(selected) }}
							<template v-if="selected.decision_reason">
								<br>{{ selected.decision_reason }}
							</template>
						</dd>
					</template>
				</dl>

				<div class="review-panel__actions">
					<NcButton v-if="canPlay && pointMeasures(selected) !== null" @click="emit('play', selected)">
						<template #icon>
							<NcIconSvgWrapper :path="mdiPlay" />
						</template>
						{{ t('notenarchiv', 'Play this passage') }}
					</NcButton>
					<NcButton v-if="canCorrect && isUnresolved(selected) && pointMeasures(selected) !== null"
						aria-haspopup="dialog"
						@click="emit('correct', selected)">
						<template #icon>
							<NcIconSvgWrapper :path="mdiPencilOutline" />
						</template>
						{{ t('notenarchiv', 'Correct in the app …') }}
					</NcButton>
				</div>

				<form v-if="decisions.length > 0" class="review-panel__form" @submit.prevent="saveDecision">
					<fieldset class="review-panel__decisions">
						<legend>{{ t('notenarchiv', 'Decision') }}</legend>
						<NcCheckboxRadioSwitch v-for="option in decisions"
							:key="option"
							v-model="decision"
							type="radio"
							:value="option"
							:name="`review-decision-${selected.id}`">
							{{ decisionLabel(option) }}
						</NcCheckboxRadioSwitch>
					</fieldset>
					<NcTextArea v-if="decision === 'correct'"
						v-model="correction"
						:label="t('notenarchiv', 'What should be corrected? (required)')"
						required
						resize="vertical"
						rows="3" />
					<fieldset v-if="decision === 'question'" class="review-panel__decisions">
						<legend>{{ t('notenarchiv', 'Question to') }}</legend>
						<NcCheckboxRadioSwitch v-model="addressee"
							type="radio"
							value="conductor"
							:name="`review-addressee-${selected.id}`">
							{{ roleLabel('conductor') }}
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch v-model="addressee"
							type="radio"
							value="librarian"
							:name="`review-addressee-${selected.id}`">
							{{ roleLabel('librarian') }}
						</NcCheckboxRadioSwitch>
					</fieldset>
					<NcTextArea v-if="decision !== null"
						v-model="reason"
						:label="decision === 'dismissed' ? t('notenarchiv', 'Reason (required)') : (decision === 'question' ? t('notenarchiv', 'Question (optional; the point is quoted)') : t('notenarchiv', 'Reason (optional)'))"
						:required="decision === 'dismissed'"
						resize="vertical"
						rows="2" />
					<p v-if="formError" class="review-panel__error" role="alert">
						{{ formError }}
					</p>
					<NcButton class="review-panel__submit"
						type="submit"
						variant="primary"
						:disabled="decision === null || busy">
						<template v-if="busy" #icon>
							<NcLoadingIcon />
						</template>
						{{ t('notenarchiv', 'Save decision') }}
					</NcButton>
				</form>
				<p v-else-if="isUnresolved(selected)" class="review-panel__hint">
					{{ selected.role === 'librarian'
						? t('notenarchiv', 'The catalog librarian decides this point.')
						: t('notenarchiv', 'The conductor or the catalog librarian decides this point.') }}
				</p>
			</section>
		</template>
	</section>
</template>

<style scoped>
.review-panel {
	/* the facts below stack when the panel is narrow (beside score and scan) */
	container-type: inline-size;
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));
	min-width: 0;
	padding: calc(2 * var(--default-grid-baseline));
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-main-background);
}

/* In a panel of fixed height the panel scrolls as a whole; its parts keep their size */
.review-panel > * {
	flex-shrink: 0;
}

.review-panel__heading {
	margin: 0;
	font-size: 1.1em;
	font-weight: bold;
}

.review-panel__detail-heading {
	margin: 0;
	font-weight: bold;
}

.review-panel__status:empty {
	display: none;
}

.review-panel__status {
	margin: 0;
	font-weight: bold;
}

.review-panel__loading {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline);
	margin: 0;
}

.review-panel__counts,
.review-panel__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.review-panel__report summary {
	cursor: pointer;
	font-weight: bold;
}

.review-panel__report summary:focus-visible,
.review-panel__point:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.review-panel__facts {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 2px calc(2 * var(--default-grid-baseline));
	margin: var(--default-grid-baseline) 0;
}

.review-panel__facts dt {
	color: var(--color-text-maxcontrast);
}

.review-panel__facts dd {
	min-width: 0;
	margin: 0;
	overflow-wrap: anywhere;
}

.review-panel__long {
	white-space: pre-wrap;
}

@container (max-width: 30em) {
	.review-panel__facts {
		grid-template-columns: minmax(0, 1fr);
		gap: 0;
	}

	.review-panel__facts dt {
		font-size: var(--font-size-small, 13px);
	}

	.review-panel__facts dd + dt {
		margin-top: var(--default-grid-baseline);
	}
}

.review-panel__plain-list {
	margin: 0;
	padding: 0;
	list-style: none;
}

.review-panel__list {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	max-height: 40vh;
	margin: 0;
	padding: 2px;
	overflow: auto;
	list-style: none;
}

.review-panel__point {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 2px;
	width: 100%;
	min-height: var(--default-clickable-area);
	margin: 0;
	padding: var(--default-grid-baseline) calc(2 * var(--default-grid-baseline));
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-element, 8px);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	font-weight: normal;
	text-align: start;
	cursor: pointer;
}

.review-panel__point:hover {
	background-color: var(--color-background-hover);
}

/* selected: a thick border and the pressed state, not only a color */
.review-panel__point--selected {
	border: 2px solid var(--color-primary-element);
	background-color: var(--color-primary-element-light);
}

.review-panel__point--done .review-panel__place {
	font-weight: normal;
}

.review-panel__place {
	font-weight: bold;
}

.review-panel__meta {
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 13px);
}

.review-panel__text {
	overflow-wrap: anywhere;
}

.review-panel__detail {
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));
	padding-top: calc(2 * var(--default-grid-baseline));
	border-top: 1px solid var(--color-border);
}

.review-panel__actions {
	display: flex;
	flex-wrap: wrap;
	gap: calc(2 * var(--default-grid-baseline));
}

.review-panel__form {
	display: flex;
	flex-direction: column;
	gap: calc(2 * var(--default-grid-baseline));
}

.review-panel__submit {
	align-self: flex-start;
}

.review-panel__decisions {
	margin: 0;
	padding: 0;
	border: none;
}

.review-panel__decisions legend {
	margin-bottom: var(--default-grid-baseline);
	font-weight: bold;
}

.review-panel__error {
	margin: 0;
	color: var(--color-error-text, var(--color-error));
}
</style>
