<?php

declare(strict_types=1);

namespace OCA\Notenarchiv\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * A version of a work: a specific setting (original, arrangement, new
 * engraving, …). Rights and MusicXML belong to the version, not to the work.
 *
 * @method int getWorkId()
 * @method void setWorkId(int $workId)
 * @method string|null getKind()
 * @method void setKind(?string $kind)
 * @method string|null getArrangerText()
 * @method void setArrangerText(?string $arrangerText)
 * @method string|null getRelation()
 * @method void setRelation(?string $relation)
 * @method string|null getNotes()
 * @method void setNotes(?string $notes)
 * @method string|null getRightsAssessment()
 * @method void setRightsAssessment(?string $rightsAssessment)
 * @method string|null getRightsStatusImport()
 * @method void setRightsStatusImport(?string $rightsStatusImport)
 * @method string|null getRightsStatus()
 * @method void setRightsStatus(?string $rightsStatus)
 * @method string|null getRightsConfirmed()
 * @method void setRightsConfirmed(?string $rightsConfirmed)
 * @method string|null getPortalSince()
 * @method void setPortalSince(?string $portalSince)
 * @method string|null getPublisher()
 * @method void setPublisher(?string $publisher)
 * @method string|null getLicense()
 * @method void setLicense(?string $license)
 * @method string|null getProofOfPurchase()
 * @method void setProofOfPurchase(?string $proofOfPurchase)
 * @method bool|null getPreservationCopy()
 * @method void setPreservationCopy(?bool $preservationCopy)
 * @method string|null getMusicxmlFile()
 * @method void setMusicxmlFile(?string $musicxmlFile)
 * @method string|null getMusicxmlValid()
 * @method void setMusicxmlValid(?string $musicxmlValid)
 * @method string|null getMusicxmlDate()
 * @method void setMusicxmlDate(?string $musicxmlDate)
 * @method string|null getMusicxmlOrigin()
 * @method void setMusicxmlOrigin(?string $musicxmlOrigin)
 * @method string|null getMusicxmlReview()
 * @method void setMusicxmlReview(?string $musicxmlReview)
 * @method string|null getPublication()
 * @method void setPublication(?string $publication)
 * @method string|null getPublicationReason()
 * @method void setPublicationReason(?string $publicationReason)
 * @method string|null getValidSince()
 * @method void setValidSince(?string $validSince)
 * @method string|null getValidSetBy()
 * @method void setValidSetBy(?string $validSetBy)
 * @method string|null getValidReason()
 * @method void setValidReason(?string $validReason)
 * @method string|null getConfirmedBy()
 * @method void setConfirmedBy(?string $confirmedBy)
 * @method string|null getConfirmedByName()
 * @method void setConfirmedByName(?string $confirmedByName)
 * @method string|null getConfirmedAt()
 * @method void setConfirmedAt(?string $confirmedAt)
 * @method string|null getConfirmedReason()
 * @method void setConfirmedReason(?string $confirmedReason)
 * @method string|null getConfirmedEvidence()
 * @method void setConfirmedEvidence(?string $confirmedEvidence)
 * @method string|null getPortalRemovedAt()
 * @method void setPortalRemovedAt(?string $portalRemovedAt)
 * @method string|null getPortalRemovedReason()
 * @method void setPortalRemovedReason(?string $portalRemovedReason)
 * @method string|null getPublicationStatus()
 * @method void setPublicationStatus(?string $publicationStatus)
 * @method string|null getReviewStatus()
 * @method void setReviewStatus(?string $reviewStatus)
 * @method string|null getRule1Status()
 * @method void setRule1Status(?string $rule1Status)
 * @method int|null getRule1Count()
 * @method void setRule1Count(?int $rule1Count)
 * @method string|null getRule1Checked()
 * @method void setRule1Checked(?string $rule1Checked)
 * @method string|null getKeySignature()
 * @method void setKeySignature(?string $keySignature)
 * @method string|null getScoring()
 * @method void setScoring(?string $scoring)
 * @method string|null getLanguage()
 * @method void setLanguage(?string $language)
 * @method int|null getDuration()
 * @method void setDuration(?int $duration)
 * @method int getCreated()
 * @method void setCreated(int $created)
 * @method int getModified()
 * @method void setModified(int $modified)
 */
class WorkVersion extends Entity implements JsonSerializable {
	use FieldAccess;

	protected int $workId = 0;
	protected ?string $kind = null;
	protected ?string $arrangerText = null;
	protected ?string $relation = null;
	protected ?string $notes = null;
	protected ?string $rightsAssessment = null;
	protected ?string $rightsStatusImport = null;
	protected ?string $rightsStatus = null;
	protected ?string $rightsConfirmed = null;
	protected ?string $portalSince = null;
	protected ?string $publisher = null;
	protected ?string $license = null;
	protected ?string $proofOfPurchase = null;
	protected ?bool $preservationCopy = false;
	protected ?string $musicxmlFile = null;
	protected ?string $musicxmlValid = null;
	protected ?string $musicxmlDate = null;
	protected ?string $musicxmlOrigin = null;
	protected ?string $musicxmlReview = null;
	protected ?string $publication = null;
	protected ?string $publicationReason = null;
	protected ?string $validSince = null;
	protected ?string $validSetBy = null;
	protected ?string $validReason = null;
	protected ?string $confirmedBy = null;
	protected ?string $confirmedByName = null;
	protected ?string $confirmedAt = null;
	protected ?string $confirmedReason = null;
	protected ?string $confirmedEvidence = null;
	protected ?string $portalRemovedAt = null;
	protected ?string $portalRemovedReason = null;
	protected ?string $publicationStatus = null;
	protected ?string $reviewStatus = null;
	protected ?string $rule1Status = null;
	protected ?int $rule1Count = null;
	protected ?string $rule1Checked = null;
	protected ?string $keySignature = null;
	protected ?string $scoring = null;
	protected ?string $language = null;
	protected ?int $duration = null;
	protected int $created = 0;
	protected int $modified = 0;

	public function __construct() {
		foreach (['workId', 'rule1Count', 'duration', 'created', 'modified'] as $field) {
			$this->addType($field, 'integer');
		}
		foreach (['kind', 'arrangerText', 'relation', 'notes', 'rightsAssessment', 'rightsStatusImport',
			'rightsStatus', 'rightsConfirmed', 'portalSince', 'publisher', 'license', 'proofOfPurchase',
			'musicxmlFile', 'musicxmlValid', 'musicxmlDate', 'musicxmlOrigin', 'musicxmlReview', 'publication',
			'publicationReason', 'validSince', 'validSetBy', 'validReason', 'confirmedBy', 'confirmedByName',
			'confirmedAt', 'confirmedReason', 'confirmedEvidence', 'portalRemovedAt', 'portalRemovedReason',
			'publicationStatus', 'reviewStatus', 'rule1Status', 'rule1Checked', 'keySignature', 'scoring',
			'language'] as $field) {
			$this->addType($field, 'string');
		}
		$this->addType('preservationCopy', 'boolean');
	}

	public function isConfirmed(): bool {
		return $this->confirmedAt !== null;
	}
}
