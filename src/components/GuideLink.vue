<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Cantoria contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
/**
 * A link into the guides (src/docs.ts), opening in a new tab.
 */
import { mdiOpenInNew } from '@mdi/js'
import { t } from '../l10n.ts'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'

defineProps<{
	href: string
	/** visible text, e.g. the name of the section */
	label: string
}>()
</script>

<template>
	<a class="guide-link"
		:href="href"
		target="_blank"
		rel="noopener noreferrer">
		{{ label }}
		<NcIconSvgWrapper :path="mdiOpenInNew"
			:size="16"
			inline
			:name="t('cantoria', 'opens in a new tab')" />
	</a>
</template>

<style scoped>
.guide-link {
	text-decoration: underline;
	overflow-wrap: anywhere;
}

.guide-link:hover,
.guide-link:focus-visible {
	text-decoration-thickness: 2px;
}
</style>
