<script setup lang="ts">
/**
 * Start page "Due": what needs the user's roles now, as cards with a number
 * that lead to the filtered view, and the tasks for these roles below.
 */
import type { DueCard, DueResult } from '../types.ts'

import { mdiAlertCircleOutline, mdiCheckCircleOutline } from '@mdi/js'
import { t } from '../l10n.ts'
import { computed, onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import TaskList from './TaskList.vue'
import ViewHeader from './ViewHeader.vue'
import { errorMessage, fetchDue } from '../api.ts'
import { dueCardLabel } from '../dueLabels.ts'
import { dueTarget } from '../dueTargets.ts'
import { navigate, releaseFilter, showCatalog, taskFilters } from '../navigation.ts'
import { canSeeProposals } from '../store.ts'

const due = ref<DueResult | null>(null)
const loading = ref(false)
const error = ref('')

async function load(): Promise<void> {
	loading.value = true
	error.value = ''
	try {
		due.value = await fetchDue()
	} catch (caught) {
		error.value = errorMessage(caught)
	} finally {
		loading.value = false
	}
}

onMounted(load)

/**
 * Cards of the start page with their label in singular or plural. A card
 * leading to a view the roles do not include (the inbox for an admin without
 * a role) is left out; the server does not send it either.
 */
const cards = computed(() => (due.value?.cards ?? [])
	.filter((card) => dueTarget(card.key).view !== 'proposals' || canSeeProposals.value)
	.map((card) => ({ ...card, label: dueCardLabel(card.key, card.count, card.label) })))

/**
 * Open the view a card stands for.
 *
 * @param card - card of the start page
 */
function openCard(card: DueCard): void {
	const target = dueTarget(card.key)
	if (target.view === 'catalog') {
		showCatalog(target.catalog ?? {})
		return
	}
	if (target.view === 'tasks') {
		taskFilters.scope = 'mine'
		taskFilters.status = target.taskStatus ?? ''
	}
	if (target.view === 'portal') {
		releaseFilter.value = target.releaseStatus ?? ''
	}
	navigate(target.view)
}
</script>

<template>
	<div class="due-view">
		<ViewHeader :title="t('notenarchiv', 'Due')"
			:description="t('notenarchiv', 'What needs your roles now. The tasks are sorted by archive binder, so a printed list follows the shelf.')"
			printable
			reloadable
			:loading="loading"
			@reload="load" />

		<div class="due-view__content">
			<NcEmptyContent v-if="!due && loading" :name="t('notenarchiv', 'Loading …')">
				<template #icon>
					<NcLoadingIcon :size="64" />
				</template>
			</NcEmptyContent>

			<NcEmptyContent v-else-if="!due && error"
				:name="t('notenarchiv', 'The start page could not be loaded')"
				:description="error">
				<template #icon>
					<NcIconSvgWrapper :path="mdiAlertCircleOutline" />
				</template>
				<template #action>
					<NcButton @click="load">
						{{ t('notenarchiv', 'Try again') }}
					</NcButton>
				</template>
			</NcEmptyContent>

			<template v-else-if="due">
				<section v-if="cards.length > 0" aria-labelledby="due-cards-heading">
					<h3 id="due-cards-heading" class="hidden-visually">
						{{ t('notenarchiv', 'Overview') }}
					</h3>
					<ul class="due-view__cards">
						<li v-for="card in cards" :key="card.key">
							<!-- The visible text is the accessible name (WCAG 2.5.3) -->
							<button type="button"
								class="due-card"
								@click="openCard(card)">
								<span class="due-card__count">{{ card.count }}</span>
								<span class="due-card__label">{{ card.label }}</span>
							</button>
						</li>
					</ul>
				</section>

				<NcEmptyContent v-if="cards.length === 0 && due.tasks.length === 0"
					:name="t('notenarchiv', 'Nothing is due')"
					:description="t('notenarchiv', 'There are no open tasks or other things to do for your roles.')">
					<template #icon>
						<NcIconSvgWrapper :path="mdiCheckCircleOutline" />
					</template>
				</NcEmptyContent>

				<section v-if="due.tasks.length > 0" class="due-view__tasks" aria-labelledby="due-tasks-heading">
					<h3 id="due-tasks-heading">
						{{ t('notenarchiv', 'Tasks for your roles') }}
					</h3>
					<TaskList :tasks="due.tasks" @changed="load" />
				</section>
			</template>
		</div>
	</div>
</template>

<style scoped>
.due-view__content {
	display: flex;
	flex-direction: column;
	gap: calc(4 * var(--default-grid-baseline));
	padding: calc(3 * var(--default-grid-baseline));
}

.due-view__cards {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(min(100%, 14em), 1fr));
	gap: calc(2 * var(--default-grid-baseline));
	margin: 0;
	padding: 0;
	list-style: none;
}

.due-card {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: var(--default-grid-baseline);
	width: 100%;
	height: 100%;
	margin: 0;
	padding: calc(3 * var(--default-grid-baseline));
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	font: inherit;
	text-align: start;
	cursor: pointer;
}

.due-card:hover {
	background-color: var(--color-background-hover);
}

.due-card:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.due-card__count {
	font-size: 2em;
	font-weight: bold;
	line-height: 1;
	font-variant-numeric: tabular-nums;
}

.due-view__tasks h3 {
	margin: 0 0 calc(2 * var(--default-grid-baseline));
	font-size: 1.2em;
	font-weight: bold;
}

@media print {
	.due-view__content {
		padding: 0;
	}

	.due-view__cards {
		display: none;
	}
}
</style>
