// Copyright and publication status of a score and their labels

import { t } from '@/utils/l10n'

export type RightsStatus = 'public_domain' | 'licensed' | 'protected' | 'open'

export type PublicationStatus = 'free' | 'to_clarify' | 'blocked' | 'internal_copy'

export const RIGHTS_STATUS_VALUES: RightsStatus[] = ['public_domain', 'licensed', 'protected', 'open']

export const PUBLICATION_STATUS_VALUES: PublicationStatus[] = ['free', 'to_clarify', 'blocked', 'internal_copy']

/**
 * Rights statuses that allow publishing a score, must match RightsStatus::publishableValues() on the server
 */
const PUBLISHABLE_RIGHTS: RightsStatus[] = ['public_domain', 'licensed']

/**
 * Translated labels for each rights status, e.g. for AG Grid's refData
 */
export function rightsStatusLabels(): Record<RightsStatus, string> {
	return {
		public_domain: t('Public domain'),
		licensed: t('Licensed'),
		protected: t('Protected'),
		open: t('Undetermined'),
	}
}

/**
 * Translated labels for each publication status, e.g. for AG Grid's refData
 */
export function publicationStatusLabels(): Record<PublicationStatus, string> {
	return {
		free: t('Free'),
		to_clarify: t('To clarify'),
		blocked: t('Blocked'),
		internal_copy: t('Internal preservation copy'),
	}
}

/**
 * Whether a score is shown on the public page: released for publication and rights that allow it
 *
 * @param score - The score
 * @param score.rightsStatus - Its copyright status
 * @param score.publicationStatus - Its publication status
 */
export function isPublic(score: { rightsStatus: RightsStatus, publicationStatus: PublicationStatus }): boolean {
	return score.publicationStatus === 'free' && PUBLISHABLE_RIGHTS.includes(score.rightsStatus)
}
