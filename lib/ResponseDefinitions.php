<?php

namespace OCA\ChoirScores;

/**
 * @psalm-type ChoirScoresScoreBookInfo = array{
 *     id: int,
 *     index: int
 * }
 * @psalm-type ChoirScoresScore = array{
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
 *     scoreBook: ChoirScoresScoreBookInfo|null,
 *     viaScoreBook?: true
 * }
 * @psalm-type ChoirScoresScoreIndexed = array{
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
 *     scoreBook: ChoirScoresScoreBookInfo|null,
 *     viaScoreBook?: true,
 *     index: int
 * }
 * @psalm-type ChoirScoresScoreBook = array{
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
 * @psalm-type ChoirScoresScoreBookIndexed = array{
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
 * @psalm-type ChoirScoresTag = array{
 *     id: int,
 *     name: string
 * }
 * @psalm-type ChoirScoresCommentAuthor = array{
 *     userId: string,
 *     displayName: string|null
 * }
 * @psalm-type ChoirScoresComment = array{
 *     id: int,
 *     content: string,
 *     creationDate: int,
 *     author: ChoirScoresCommentAuthor,
 *     scoreId: int
 * }
 * @psalm-type ChoirScoresFolderCollectionVersion = array{
 *     id: int,
 *     folderCollectionId: int,
 *     validFrom: string,
 *     validTo: string|null
 * }
 * @psalm-type ChoirScoresFolderCollection = array{
 *     id: int,
 *     title: string,
 *     description: string|null,
 *     collectionType: 'alphabetical'|'indexed',
 *     activeVersionId: int|null,
 *     scoreCount: int
 * }
 * @psalm-type ChoirScoresFolderCollectionScore = array{
 *     folderCollection: ChoirScoresFolderCollection,
 *     version: ChoirScoresFolderCollectionVersion,
 *     index: int|null,
 *     viaScoreBookId: int|null
 * }
 * @psalm-type ChoirScoresFolderCollectionScoreBook = array{
 *     folderCollection: ChoirScoresFolderCollection,
 *     version: ChoirScoresFolderCollectionVersion,
 *     index: int|null
 * }
 * @psalm-type ChoirScoresSetlist = array{
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
 * @psalm-type ChoirScoresSetlistEntry = array{
 *     id: int,
 *     setlistId: int,
 *     index: int,
 *     comment: string|null,
 *     moderationDuration: int|null,
 *     breakDuration: int|null,
 *     scoreId: int|null
 * }
 * @psalm-type ChoirScoresUserSetlistSettings = array{
 *     defaultModerationTime: int|null,
 *     defaultFolderCollectionId: int|null
 * }
 * @psalm-type ChoirScoresCatalogueChange = array{
 *     column: string,
 *     old: string,
 *     new: string
 * }
 * @psalm-type ChoirScoresCatalogueImportRow = array{
 *     line: int,
 *     signature: string,
 *     title: string,
 *     status: 'new'|'changed'|'error',
 *     scoreId: int|null,
 *     changes: list<ChoirScoresCatalogueChange>,
 *     errors: list<string>
 * }
 * @psalm-type ChoirScoresCatalogueImportCounts = array{
 *     new: int,
 *     changed: int,
 *     unchanged: int,
 *     errors: int
 * }
 * @psalm-type ChoirScoresCatalogueImportResult = array{
 *     dryRun: bool,
 *     counts: ChoirScoresCatalogueImportCounts,
 *     mappedColumns: list<string>,
 *     extraColumns: list<string>,
 *     rows: list<ChoirScoresCatalogueImportRow>
 * }
 * @psalm-type ChoirScoresCatalogueExport = array{
 *     fileName: string,
 *     content: string
 * }
 * @psalm-type ChoirScoresCatalogueValue = array{
 *     column: string,
 *     value: string
 * }
 * @psalm-type ChoirScoresShare = array{
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
 * @psalm-type ChoirScoresShareGroup = array{
 *     id: string,
 *     displayName: string
 * }
 * @psalm-type ChoirScoresSharedScore = array{
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
 * @psalm-type ChoirScoresSharedTarget = array{
 *     shareId: int,
 *     targetType: 'score'|'folder_collection',
 *     targetId: int,
 *     title: string,
 *     description: string|null,
 *     scores: list<ChoirScoresSharedScore>
 * }
 * @psalm-suppress UnusedClass - Used for OpenAPI response type definitions
 */
class ResponseDefinitions {
}
