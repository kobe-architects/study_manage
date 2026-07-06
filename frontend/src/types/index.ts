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
  dates: string[] // 全学習日（昇順・ISO）
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
  dates: string[]
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

export interface Goal {
  id: number
  title: string
  subjectId: number | null
  subjectName: string | null
  colorSoft: string
  colorVivid: string
  scope: string
  rangeLabel: string
  deadline: string
  target: number
  done: number
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

export interface AuthUser {
  id: number
  name: string
  email: string
  settings: UserSettings
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
