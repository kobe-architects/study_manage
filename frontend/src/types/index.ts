// ===== 共通 =====
export type StudyType = '講義' | '問題集' | '教科書'

export const STUDY_TYPES: StudyType[] = ['講義', '問題集', '教科書']

// ===== メインアプリ =====
export interface TypeStat {
  total: number // この小分類×種別に紐づく教材行の総数
  done: number // 1件以上記録のある行数
  lastDate: string | null
}

export interface StudyItemRow {
  id: number
  subjectId: number
  subjectCode: string
  subjectName: string
  group: string
  colorSoft: string
  colorVivid: string
  majorId: number
  major: string
  midId: number
  mid: string
  sub: string
  sortOrder: number
  included: boolean
  byType: Record<StudyType, TypeStat>
}

// 学習日ごとの色分け（color: 'red' | 'blue' | 'green' | null）
export type RecordColor = 'red' | 'blue' | 'green'
export interface StudyDate {
  date: string // ISO (YYYY-MM-DD)
  color: RecordColor | null
  reviewOn: string | null // 復習期限日（ISO）。null は復習不要。
}

// 復習項目一覧（トップページ）: 復習期限を持つ学習記録を1件=1復習タスクとして表示
export interface ReviewItem {
  id: number // 学習記録ID（復習タスクの単位）
  rowId: number | null // resource_book_item id
  title: string | null
  sub: string | null
  bookTitle: string | null
  type: StudyType | null
  subjectName: string | null
  colorSoft: string
  colorVivid: string
  major: string | null
  mid: string | null
  studiedOn: string
  reviewOn: string // ISO
  color: RecordColor | null
  reviewed: boolean // 復習完了済みか
  reviewedOn: string | null // 復習完了日（ISO）。未復習は null。
  overdue: boolean
}

// ===== 個別学習一覧データ（教材） =====
export interface ResourceBook {
  id: number
  type: StudyType
  title: string
  subjectId: number | null
  subjectName: string | null
  colorSoft: string
  colorVivid: string
  imageUrl: string | null
  sortOrder: number
  pinned: boolean
  totalRows: number
  doneRows: number
  targetRows: number // 進捗対象（included かつ学習項目に紐づく）行数
}

export interface ResourceBookRow {
  id: number
  bookId: number
  chapter: string | null
  seqNo: string | null
  checkFlag: string | null
  title: string | null
  difficulty: string | null
  meta: Record<string, string> | null
  studyItemId: number | null
  included: boolean
  important: boolean
  subjectName: string | null
  colorSoft: string
  colorVivid: string
  major: string | null
  mid: string | null
  sub: string | null
  sortOrder: number
  recordCount: number
  lastDate: string | null
  dates: StudyDate[] // 全学習日（昇順）＋色
}

// 講義に関連する問題（同じ小分類に紐づく問題集の行）
export interface RelatedProblemRow {
  id: number
  bookTitle: string | null
  chapter: string | null
  seqNo: string | null
  checkFlag: string | null
  think: string | null
  title: string | null
  difficulty: string | null
  important: boolean
  subjectName: string | null
  colorVivid: string
  major: string | null
  mid: string | null
  sub: string | null
  recordCount: number
  dates: StudyDate[]
}

export interface RecordStats {
  week: number
  streak: number
  total: number
  heatmap: Record<string, number>
  recent: RecentRecord[]
}

export interface RecentRecord {
  id: number
  date: string
  subjectName: string
  colorSoft: string
  colorVivid: string
  major: string
  mid: string
  sub: string
  type: StudyType
}

/** 学習記録の出力機能（期間指定一覧）の1行 */
export interface RecordListItem {
  id: number
  date: string
  type: StudyType
  subjectName: string | null
  colorSoft: string
  colorVivid: string
  major: string | null
  mid: string | null
  sub: string | null
  bookTitle: string | null
  seqNo: string | null
  rowTitle: string | null
  color: RecordColor | null
  reviewOn: string | null
  reviewedOn: string | null
}

export interface Goal {
  id: number
  title: string
  subjectId: number | null
  subjectName: string | null
  colorSoft: string
  colorVivid: string
  scope: string
  rangeLabel: string
  createdOn: string // 目標作成日（ISO）。ペース按分の起点。
  deadline: string
  target: number
  done: number
  itemIds: number[] // 紐づけた個別学習データ（教材の行）ID
  linkedCount: number
  achieved: boolean | null // true=達成 / false=未達成 / null=未記録
  createdByTutor: boolean // 家庭教師が設定した目標
  parentId: number | null
  subGoals: Goal[] // 中間目標（親目標のときのみ）
}

// 目標の紐づけツリー（教材 → 章 → 行）
export interface GoalLinkRow {
  id: number
  seqNo: string | null
  title: string | null
  checkFlag: string | null
  think: string | null
}
export interface GoalLinkChapter {
  name: string
  rows: GoalLinkRow[]
}
export interface GoalLinkBook {
  id: number
  title: string
  type: StudyType
  subjectName: string | null
  colorVivid: string
  rowCount: number
  chapters: GoalLinkChapter[]
}

// 目標に紐づく個別学習データの明細（学習済み/未学習）
export interface GoalItemDetail {
  id: number
  bookTitle: string | null
  type: StudyType | null
  chapter: string | null
  seqNo: string | null
  title: string | null
  sub: string | null
  subjectName: string | null
  colorVivid: string
  studied: boolean
  studiedOn: string | null // 最新学習日（ISO）。未学習は null。
}

export interface CalendarEvent {
  id: number
  date: string
  title: string
}

export interface UserSettings {
  name: string
  school: string
  examDate: string | null
  defaultType: StudyType
  reminder: boolean
  weeklyReport: boolean
  hideEmpty: boolean
  startScreen: 'home' | 'record' | 'goal'
}

export type UserRole = 'owner' | 'tutor'

/** 家庭教師ログイン時の担当生徒情報 */
export interface StudentInfo {
  name: string
  school: string
  examDate: string | null
}

export interface AuthUser {
  id: number
  name: string
  email: string
  role: UserRole
  settings: UserSettings | null // tutor は null
  student: StudentInfo | null // owner は null
}

// ===== 家庭教師（tutor） =====

/** 家庭教師アカウント（owner の管理画面用） */
export interface TutorAccount {
  id: number
  name: string
  email: string
  /** 表示用パスワード（機能追加前に作成したアカウントは null。PW再設定で保存される） */
  password: string | null
  createdOn: string
}

/** 課題（家庭教師が個別学習データを選択して期限を設定） */
export interface Assignment {
  id: number
  title: string
  note: string | null
  createdOn: string
  dueOn: string
  target: number
  done: number
  itemIds: number[]
  achieved: boolean | null // true=達成 / false=未達成 / null=未記録
  overdue: boolean
  createdByName: string | null
}

// ===== 英単語クイズ =====
export type VocabularyLabel = 'easy' | 'normal' | 'hard'
export type QuizType = 'choice' | 'input'
export type VocabularyProficiency = 'high' | 'medium' | 'low'
export type PrintTestType = 'meaning' | 'spelling' | 'fill_spelling'
export type PrintTestFormat = 'free' | 'choice'

export interface LearningStat {
  correctCount: number
  incorrectCount: number
  lastAttemptedAt: string | null
  nextReviewAt: string | null
  easeFactor: number
  intervalDays: number
  repetitionCount: number
}

export interface Vocabulary {
  id: number
  sectionId: number
  word: string
  meaning: string
  meaningSupplement: string | null
  partOfSpeech: string | null
  importance: number
  label: VocabularyLabel
  proficiency: VocabularyProficiency | null
  memo: string | null
  exampleSentence: string | null
  exampleTranslation: string | null
  exampleExplanation: string | null
  imageUrl: string | null
  sortOrder: number
  learningStat: LearningStat | null
  createdAt?: string
  updatedAt?: string
}

export interface StudyResourceSection {
  id: number
  name: string
  sortOrder: number
}

export interface StudyResource {
  id: number
  name: string
  sections: StudyResourceSection[]
}

export interface QuizChoice {
  meaning: string
  isCorrect: boolean
}

export interface QuizQuestion {
  vocabulary: Vocabulary
  choices?: QuizChoice[]
}

export interface QuizSettings {
  sectionIds: number[]
  quizType: QuizType
  count: number
  importances?: number[]
  labels?: VocabularyLabel[]
  ordered?: boolean
  offset?: number
  vocabularyIds?: number[]
}

export interface FlashcardSettings {
  wordDuration: number
  meaningDuration: number
}

export interface VocabularyStats {
  totalWords: number
  masteredCount: number
  learningCount: number
  newCount: number
  overallAccuracy: number
  dueForReview: number
}

// トップページ用: 英単語の習得進捗（全体 + セクション別）
export interface VocabSectionProgress {
  id: number
  name: string
  totalWords: number
  masteredCount: number
}
export interface VocabProgress {
  id: number
  name: string
  totalWords: number
  masteredCount: number
  sections: VocabSectionProgress[]
}

export interface QuizAnswerRecord {
  isCorrect: boolean
  selected?: number | null
  input?: string
}

// ===== 小テスト =====

/** 教材に紐づく PDF。pageMap=seq: 行の番号 + pageOffset = PDF ページ / none: 手動選択 */
export type PdfPageMap = 'seq' | 'none'
export interface BookPdf {
  id: number
  bookId: number
  title: string
  pageCount: number
  sizeBytes: number
  pageMap: PdfPageMap
  pageOffset: number
  sortOrder: number
  createdOn: string | null
}

/** 出題対象の教材（PDF 紐づけ数付き） */
export interface QuizBook {
  id: number
  type: StudyType
  title: string
  subjectName: string | null
  colorVivid: string
  pdfCount: number
  rowCount: number
}

export type QuizMark = 'o' | 'tri' | 'x'
export type QuizStatus = 'assigned' | 'submitted' | 'graded'

/** 出題ページ選択用の教材行 */
export interface QuizRow {
  id: number
  chapter: string | null
  seqNo: string | null
  title: string | null
  difficulty: string | null
  checkFlag: string | null
  important: boolean
  recordCount: number
  lastDate: string | null
  quizCount: number
  lastMark: QuizMark | null
  /** PDF ID → 対応ページ（番号=ページ対応の PDF のみ） */
  pages: Record<string, number>
}

export interface QuizSummary {
  id: number
  title: string
  note: string | null
  dueOn: string | null
  createdOn: string
  status: QuizStatus
  pageCount: number
  answeredCount: number
  maxScorePerPage: number
  maxScore: number
  score: number | null
  rate: number | null
  submittedAt: string | null
  gradedAt: string | null
  bookId: number | null
  bookTitle: string | null
  createdByName: string | null
  overdue: boolean
}

/** 添削注釈（画像ピクセル座標） */
export type AnnotationShape = 'circle' | 'cross' | 'triangle' | 'check' | 'line' | 'rect'
export type AnnotationItem =
  | { id: string; type: 'pen'; color: string; width: number; points: number[] }
  | { id: string; type: AnnotationShape; color: string; width: number; x1: number; y1: number; x2: number; y2: number }
  | { id: string; type: 'text'; color: string; size: number; x: number; y: number; text: string }
export interface AnnotationDoc {
  version: 1
  width: number
  height: number
  items: AnnotationItem[]
}

export interface QuizPageDetail {
  id: number
  pageNo: number
  label: string | null
  pdfId: number | null
  pdfTitle: string | null
  pdfPage: number
  itemId: number | null
  chapter: string | null
  seqNo: string | null
  itemTitle: string | null
  difficulty: string | null
  refPdfId: number | null
  refPdfTitle: string | null
  refPage: number | null
  hasAnswer: boolean
  answerUploadedAt: string | null
  answerVersion: number | null
  annotations: AnnotationDoc | null
  hasAnnotated: boolean
  annotatedVersion: number | null
  mark: QuizMark | null
  score: number | null
  comment: string | null
}

export interface QuizDetail extends QuizSummary {
  pages: QuizPageDetail[]
}

/** 出題時のページ指定 */
export interface QuizPageSpec {
  pdfId: number
  page: number
  itemId?: number | null
  label?: string | null
  refPdfId?: number | null
  refPage?: number | null
}

export interface QuizStatGroup {
  key: string
  label: string
  sub: string | null
  pages: number
  o: number
  tri: number
  x: number
  rate: number | null
  score: number
  max: number
}
export interface QuizWeakItem {
  itemId: number | null
  label: string | null
  chapter: string | null
  seqNo: string | null
  title: string | null
  difficulty: string | null
  attempts: number
  lastMark: QuizMark | null
  lastOn: string | null
  rate: number | null
  score: number
  max: number
}
export interface QuizStats {
  summary: {
    quizCount: number
    assignedCount: number
    submittedCount: number
    gradedCount: number
    pageCount: number
    score: number
    max: number
    avgRate: number | null
    marks: { o: number; tri: number; x: number }
  }
  timeline: { id: number; title: string; gradedOn: string | null; score: number; max: number; rate: number | null }[]
  byChapter: QuizStatGroup[]
  byMid: QuizStatGroup[]
  byDifficulty: QuizStatGroup[]
  weak: QuizWeakItem[]
}

