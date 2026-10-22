<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * For the conductor: the works with several versions and none valid for the
 * choir, each version with its copies. The valid version is set right in
 * the list, with the same action as in the tab "Rights" of a copy (the
 * server checks the conductor role again). Afterwards the work leaves the
 * list.
 */
import type { OpenChoice } from '../catalog.ts'

import { mdiCheckCircleOutline, mdiCheckDecagramOutline } from '@mdi/js'
import { showSuccess } from '@nextcloud/dialogs'
import { n, t } from '../l10n.ts'
import { computed, ref, useTemplateRef } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import ActionDialog from './ActionDialog.vue'
import HelpTerm from './HelpTerm.vue'
import RightsBadge from './RightsBadge.vue'
import ViewHeader from './ViewHeader.vue'
import { errorMessage, setValidVersion } from '../api.ts'
import { isEntryConfirmed, openChoices } from '../catalog.ts'
import { focusAfterUpdate } from '../focus.ts'
import { repertoireClassLabel } from '../labels.ts'
import { entries, loadEntries, openEntry } from '../store.ts'

const onlyCurrent = ref(false)

const choices = computed(() => openChoices(entries.value)
	.filter((work) => !onlyCurrent.value || work.repertoireClass === 'current'))

/** The list, to give it the focus when a work has left it. */
const list = useTemplateRef<HTMLElement>('list')

// ---- setting the valid version ----

const choosing = ref<{ work: OpenChoice, versionId: number, shelfmarks: string } | null>(null)
const reason = ref('')
const busy = ref(false)
const error = ref('')

/**
 * Open the dialog for one version.
 *
 * @param work - the work
 * @param versionId - the version to set as valid
 */
function choose(work: OpenChoice, versionId: number): void {
	const version = work.versions.find((candidate) => candidate.versionId === versionId)
	choosing.value = { work, versionId, shelfmarks: (version?.copies ?? []).map((copy) => copy.shelfmark).join(', ') }
	reason.value = ''
	error.value = ''
}

async function confirm(): Promise<void> {
	const current = choosing.value
	if (!current) {
		return
	}
	busy.value = true
	error.value = ''
	try {
		const text = reason.value.trim()
		await setValidVersion(current.versionId, text === '' ? null : text)
		showSuccess(t('notenarchiv', 'Valid for the choir now: {shelfmarks}.', { shelfmarks: current.shelfmarks }))
		choosing.value = null
		await loadEntries()
		// The work has left the list: the focus goes to the list, or to the
		// message that nothing is left (WCAG 2.4.3)
		await focusAfterUpdate(() => list.value ?? document.querySelector('.valid-versions__content .empty-content'))
	} catch (caught) {
		error.value = errorMessage(caught)
	} finally {
		busy.value = false
	}
}
</script>

<template>
	<div class="valid-versions">
		<ViewHeader :title="t('notenarchiv', 'Choose valid versions')"
			:description="t('notenarchiv', 'Works with several versions of which none is valid for the choir yet. Choose the version the choir sings; you can change it later in the tab Rights of a copy.')"
			printable>
			<template #actions>
				<HelpTerm term="validVersion" />
			</template>
		</ViewHeader>

		<div class="valid-versions__filters no-print">
			<NcCheckboxRadioSwitch v-model="onlyCurrent" type="switch">
				{{ t('notenarchiv', 'Only the current repertoire') }}
			</NcCheckboxRadioSwitch>
		</div>

		<div class="valid-versions__content">
			<NcEmptyContent v-if="choices.length === 0"
				:name="t('notenarchiv', 'Nothing to choose')"
				:description="onlyCurrent
					? t('notenarchiv', 'Every work of the current repertoire with several versions has a valid version.')
					: t('notenarchiv', 'Every work with several versions has a valid version.')">
				<template #icon>
					<NcIconSvgWrapper :path="mdiCheckCircleOutline" />
				</template>
				<template v-if="onlyCurrent" #action>
					<NcButton @click="onlyCurrent = false">
						{{ t('notenarchiv', 'Show all works') }}
					</NcButton>
				</template>
			</NcEmptyContent>

			<template v-else>
				<p class="valid-versions__count" aria-live="polite">
					{{ n('notenarchiv', '%n work to decide', '%n works to decide', choices.length) }}
				</p>
				<ul ref="list" class="valid-versions__works" tabindex="-1">
					<li v-for="work in choices" :key="work.workId" class="valid-versions__work">
						<h3 :id="`valid-versions-work-${work.workId}`">
							{{ work.title || t('notenarchiv', '(no title)') }}
							<span v-if="work.composer" class="valid-versions__muted">{{ work.composer }}</span>
						</h3>
						<p class="valid-versions__muted">
							{{ repertoireClassLabel(work.repertoireClass) }}
						</p>
						<ul class="valid-versions__versions" :aria-labelledby="`valid-versions-work-${work.workId}`">
							<li v-for="version in work.versions" :key="version.versionId" class="valid-versions__version">
								<div class="valid-versions__copies">
									<NcButton v-for="copy in version.copies"
										:key="copy.id"
										variant="tertiary"
										:aria-label="t('notenarchiv', 'Open {shelfmark}', { shelfmark: copy.shelfmark })"
										@click="openEntry(copy.id, 'rights')">
										<span class="valid-versions__shelfmark">{{ copy.shelfmark }}</span>
									</NcButton>
								</div>
								<span v-if="version.copies[0]?.arranger_text" class="valid-versions__detail">{{ version.copies[0].arranger_text }}</span>
								<RightsBadge :rights-status="version.copies[0]?.rights_status"
									:rights-confirmed="version.copies[0] && isEntryConfirmed(version.copies[0]) ? true : null"
									:preservation-copy="version.copies[0]?.preservation_copy" />
								<span v-if="version.copies.some((copy) => copy.has_musicxml)" class="valid-versions__tag">MusicXML</span>
								<NcButton class="valid-versions__set no-print"
									:aria-label="t('notenarchiv', 'Set {shelfmarks} as valid for the choir', { shelfmarks: version.copies.map((copy) => copy.shelfmark).join(', ') })"
									@click="choose(work, version.versionId)">
									<template #icon>
										<NcIconSvgWrapper :path="mdiCheckDecagramOutline" />
									</template>
									{{ t('notenarchiv', 'Set as valid') }}
								</NcButton>
							</li>
						</ul>
					</li>
				</ul>
			</template>
		</div>

		<ActionDialog v-if="choosing"
			:name="t('notenarchiv', 'Set as valid for the choir')"
			:submit-label="t('notenarchiv', 'Set as valid')"
			:busy="busy"
			:error="error"
			@submit="confirm"
			@close="choosing = null">
			<p>
				{{ t('notenarchiv', '{shelfmarks} becomes the version of “{title}” valid for the choir. It says nothing about the rights.', { shelfmarks: choosing.shelfmarks, title: choosing.work.title || t('notenarchiv', '(no title)') }) }}
			</p>
			<NcTextArea v-model="reason"
				:label="t('notenarchiv', 'Reason (optional)')"
				resize="vertical"
				rows="3" />
		</ActionDialog>
	</div>
</template>

<style scoped>
.valid-versions__filters {
	padding: calc(2 * var(--default-grid-baseline)) calc(3 * var(--default-grid-baseline));
}

.valid-versions__content {
	padding: calc(3 * var(--default-grid-baseline));
}

.valid-versions__count {
	margin: 0 0 calc(2 * var(--default-grid-baseline));
	color: var(--color-text-maxcontrast);
}

.valid-versions__works {
	display: flex;
	flex-direction: column;
	gap: calc(3 * var(--default-grid-baseline));
	margin: 0;
	padding: 0;
	list-style: none;
}

.valid-versions__work {
	padding: calc(2 * var(--default-grid-baseline)) calc(3 * var(--default-grid-baseline));
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-large, 8px);
}

.valid-versions__work h3 {
	margin: 0;
	font-size: 1.1em;
	font-weight: bold;
}

.valid-versions__work > p {
	margin: 0 0 var(--default-grid-baseline);
}

.valid-versions__muted {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}

.valid-versions__versions {
	display: flex;
	flex-direction: column;
	margin: 0;
	padding: 0;
	list-style: none;
}

.valid-versions__version {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--default-grid-baseline) calc(2 * var(--default-grid-baseline));
	padding-block: var(--default-grid-baseline);
	border-top: 1px solid var(--color-border);
}

.valid-versions__copies {
	display: flex;
	flex-wrap: wrap;
	gap: 2px;
}

.valid-versions__shelfmark {
	font-weight: bold;
	font-variant-numeric: tabular-nums;
}

.valid-versions__detail {
	overflow-wrap: anywhere;
}

.valid-versions__tag {
	padding: 0 var(--default-grid-baseline);
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-small, 4px);
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 13px);
}

.valid-versions__set {
	margin-inline-start: auto;
}

@media print {
	.valid-versions__content {
		padding: 0;
	}
}
</style>
