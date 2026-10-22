<?php

namespace OCA\OrchestraScoresManager;

/**
 * @psalm-type OrchestraScoresManagerScoreBookInfo = array{
 *     id: int,
 *     index: int
 * }
 * @psalm-type OrchestraScoresManagerScore = array{
 *     id: int,
 *     title: string,
 *     titleShort: string|null,
 *     composer: string|null,
 *     arranger: string|null,
 *     publisher: string|null,
 *     year: int|null,
 *     difficulty: float|null,
 *     medleyContents: list<string>|null,
 *     defects: string|null,
 *     physicalCopiesStatus: string|null,
 *     digitalStatus: string|null,
 *     gemaIds: list<string>|null,
 *     duration: int|null,
 *     signature: string|null,
 *     lyricist: string|null,
 *     voicing: string|null,
 *     language: string|null,
 *     inventoryStatus: 'active'|'retired'|'missing'|'lent',
 *     lastPerformed: string|null,
 *     lastPerformedImported: string|null,
 *     repertoireClass: 'current'|'dormant'|'earlier'|'not_documented',
 *     rightsStatus: 'public_domain'|'licensed'|'protected'|'open',
 *     rightsNote: string|null,
 *     rightsConfirmedAt: string|null,
 *     rightsConfirmedBy: string|null,
 *     publicationStatus: 'approved'|'to_clarify'|'blocked'|'internal_preservation_copy',
 *     publicationNote: string|null,
 *     publicationBlockedUntil: string|null,
 *     musicxmlFile: string|null,
 *     musicxmlFileId: int|null,
 *     pdfFile: string|null,
 *     pdfFileId: int|null,
 *     updatedAt: string|null,
 *     tags: list<string>|null,
 *     scoreBook: OrchestraScoresManagerScoreBookInfo|null,
 *     viaScoreBook?: true
 * }
 * @psalm-type OrchestraScoresManagerScoreIndexed = array{
 *     id: int,
 *     title: string,
 *     titleShort: string|null,
 *     composer: string|null,
 *     arranger: string|null,
 *     publisher: string|null,
 *     year: int|null,
 *     difficulty: float|null,
 *     medleyContents: list<string>|null,
 *     defects: string|null,
 *     physicalCopiesStatus: string|null,
 *     digitalStatus: string|null,
 *     gemaIds: list<string>|null,
 *     duration: int|null,
 *     signature: string|null,
 *     lyricist: string|null,
 *     voicing: string|null,
 *     language: string|null,
 *     inventoryStatus: 'active'|'retired'|'missing'|'lent',
 *     lastPerformed: string|null,
 *     lastPerformedImported: string|null,
 *     repertoireClass: 'current'|'dormant'|'earlier'|'not_documented',
 *     rightsStatus: 'public_domain'|'licensed'|'protected'|'open',
 *     rightsNote: string|null,
 *     rightsConfirmedAt: string|null,
 *     rightsConfirmedBy: string|null,
 *     publicationStatus: 'approved'|'to_clarify'|'blocked'|'internal_preservation_copy',
 *     publicationNote: string|null,
 *     publicationBlockedUntil: string|null,
 *     musicxmlFile: string|null,
 *     musicxmlFileId: int|null,
 *     pdfFile: string|null,
 *     pdfFileId: int|null,
 *     updatedAt: string|null,
 *     tags: list<string>|null,
 *     scoreBook: OrchestraScoresManagerScoreBookInfo|null,
 *     viaScoreBook?: true,
 *     index: int
 * }
 * @psalm-type OrchestraScoresManagerScoreBook = array{
 *     id: int,
 *     title: string,
 *     titleShort: string|null,
 *     composer: string|null,
 *     arranger: string|null,
 *     editor: string|null,
 *     publisher: string|null,
 *     year: int|null,
 *     difficulty: float|null,
 *     defects: string|null,
 *     physicalCopiesStatus: string|null,
 *     tags: list<string>|null,
 *     scoreCount: int
 * }
 * @psalm-type OrchestraScoresManagerScoreBookIndexed = array{
 *     id: int,
 *     title: string,
 *     titleShort: string|null,
 *     composer: string|null,
 *     arranger: string|null,
 *     editor: string|null,
 *     publisher: string|null,
 *     year: int|null,
 *     difficulty: float|null,
 *     defects: string|null,
 *     physicalCopiesStatus: string|null,
 *     tags: list<string>|null,
 *     scoreCount: int,
 *     index: int
 * }
 * @psalm-type OrchestraScoresManagerTag = array{
 *     id: int,
 *     name: string
 * }
 * @psalm-type OrchestraScoresManagerCommentAuthor = array{
 *     userId: string,
 *     displayName: string|null
 * }
 * @psalm-type OrchestraScoresManagerComment = array{
 *     id: int,
 *     content: string,
 *     creationDate: int,
 *     author: OrchestraScoresManagerCommentAuthor,
 *     scoreId: int
 * }
 * @psalm-type OrchestraScoresManagerFolderCollectionVersion = array{
 *     id: int,
 *     folderCollectionId: int,
 *     validFrom: string,
 *     validTo: string|null
 * }
 * @psalm-type OrchestraScoresManagerFolderCollection = array{
 *     id: int,
 *     title: string,
 *     description: string|null,
 *     collectionType: 'alphabetical'|'indexed',
 *     activeVersionId: int|null,
 *     scoreCount: int
 * }
 * @psalm-type OrchestraScoresManagerFolderCollectionScore = array{
 *     folderCollection: OrchestraScoresManagerFolderCollection,
 *     version: OrchestraScoresManagerFolderCollectionVersion,
 *     index: int|null,
 *     viaScoreBookId: int|null
 * }
 * @psalm-type OrchestraScoresManagerFolderCollectionScoreBook = array{
 *     folderCollection: OrchestraScoresManagerFolderCollection,
 *     version: OrchestraScoresManagerFolderCollectionVersion,
 *     index: int|null
 * }
 * @psalm-type OrchestraScoresManagerSetlist = array{
 *     id: int,
 *     title: string,
 *     description: string|null,
 *     startDateTime: string|null,
 *     duration: int|null,
 *     defaultModerationDuration: int|null,
 *     folderCollectionVersionId: int|null,
 *     isDraft: bool,
 *     isPublished: bool
 * }
 * @psalm-type OrchestraScoresManagerSetlistEntry = array{
 *     id: int,
 *     setlistId: int,
 *     index: int,
 *     comment: string|null,
 *     moderationDuration: int|null,
 *     breakDuration: int|null,
 *     scoreId: int|null
 * }
 * @psalm-type OrchestraScoresManagerUserSetlistSettings = array{
 *     defaultModerationTime: int|null,
 *     defaultFolderCollectionId: int|null
 * }
 * @psalm-type OrchestraScoresManagerCatalogueChange = array{
 *     column: string,
 *     old: string,
 *     new: string
 * }
 * @psalm-type OrchestraScoresManagerCatalogueImportRow = array{
 *     line: int,
 *     signature: string,
 *     title: string,
 *     status: 'new'|'changed'|'error',
 *     scoreId: int|null,
 *     changes: list<OrchestraScoresManagerCatalogueChange>,
 *     errors: list<string>
 * }
 * @psalm-type OrchestraScoresManagerCatalogueImportCounts = array{
 *     new: int,
 *     changed: int,
 *     unchanged: int,
 *     errors: int
 * }
 * @psalm-type OrchestraScoresManagerCatalogueImportResult = array{
 *     dryRun: bool,
 *     counts: OrchestraScoresManagerCatalogueImportCounts,
 *     mappedColumns: list<string>,
 *     extraColumns: list<string>,
 *     rows: list<OrchestraScoresManagerCatalogueImportRow>
 * }
 * @psalm-type OrchestraScoresManagerCatalogueExport = array{
 *     fileName: string,
 *     content: string
 * }
 * @psalm-type OrchestraScoresManagerCatalogueValue = array{
 *     column: string,
 *     value: string
 * }
 * @psalm-type OrchestraScoresManagerShare = array{
 *     id: int,
 *     shareType: 'link'|'group',
 *     targetType: 'score'|'folder_collection',
 *     targetId: int,
 *     targetTitle: string|null,
 *     token: string|null,
 *     groupId: string|null,
 *     groupName: string|null,
 *     label: string|null,
 *     createdBy: string,
 *     createdAt: int
 * }
 * @psalm-type OrchestraScoresManagerShareGroup = array{
 *     id: string,
 *     displayName: string
 * }
 * @psalm-type OrchestraScoresManagerSharedScore = array{
 *     id: int,
 *     title: string,
 *     composer: string|null,
 *     arranger: string|null,
 *     year: int|null,
 *     duration: int|null,
 *     index: string|null,
 *     hasMusicXml: bool,
 *     hasPdf: bool
 * }
 * @psalm-type OrchestraScoresManagerSharedTarget = array{
 *     shareId: int,
 *     targetType: 'score'|'folder_collection',
 *     targetId: int,
 *     title: string,
 *     description: string|null,
 *     scores: list<OrchestraScoresManagerSharedScore>
 * }
 * @psalm-suppress UnusedClass - Used for OpenAPI response type definitions
 */
class ResponseDefinitions {
}
