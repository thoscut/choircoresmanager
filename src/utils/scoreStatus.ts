// Rights and publication status of a score and their labels

import type { Score } from '@/api/generated/openapi/data-contracts'

import { t } from '@/utils/l10n'

export type RightsStatus = Score['rightsStatus']
export type PublicationStatus = Score['publicationStatus']

export const RIGHTS_STATUS_VALUES: RightsStatus[] = ['public_domain', 'licensed', 'protected', 'open']

export const PUBLICATION_STATUS_VALUES: PublicationStatus[] = ['approved', 'to_clarify', 'blocked', 'internal_preservation_copy']

/** Rights statuses that allow showing a score on the public page */
const PUBLISHABLE_RIGHTS_STATUSES: RightsStatus[] = ['public_domain', 'licensed']

/**
 * Translated labels for each rights status, e.g. for AG Grid's refData
 */
export function rightsStatusLabels(): Record<RightsStatus, string> {
	return {
		public_domain: t('Public domain'),
		licensed: t('Licensed'),
		protected: t('Protected'),
		open: t('Unresolved'),
	}
}

/**
 * Translated labels for each publication status, e.g. for AG Grid's refData
 */
export function publicationStatusLabels(): Record<PublicationStatus, string> {
	return {
		approved: t('Approved'),
		to_clarify: t('To clarify'),
		blocked: t('Blocked'),
		internal_preservation_copy: t('Internal preservation copy'),
	}
}

/**
 * Whether a score is shown on the public page (if that page is enabled):
 * approved for publication, public domain or licensed, and the rights confirmed by a person.
 *
 * This mirrors the server-side filter and is only used for hints in the UI.
 *
 * @param score - The score
 */
export function isPublishable(score: Score): boolean {
	return score.publicationStatus === 'approved'
		&& PUBLISHABLE_RIGHTS_STATUSES.includes(score.rightsStatus)
		&& score.rightsConfirmedAt !== null
}

/**
 * Whether a score is an internal preservation copy, whose notes are never rendered or played back
 *
 * @param score - The score
 */
export function isInternalPreservationCopy(score: Score): boolean {
	return score.publicationStatus === 'internal_preservation_copy'
}
