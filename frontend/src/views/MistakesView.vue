<script setup lang="ts">
/**
 * 間違いノートの本棚（生徒）。ページ（1 問 = 1 ページ）を「本」にまとめて本棚に並べる。
 * 上の切替で本の分け方が変わる:
 *  - デッキ別: ユーザーが作ったデッキが 1 冊ずつ（デッキに入っていないページは「デッキなし」の本）。科目「その他」のページはここにだけ出る。
 *  - 分類別: 科目ごとの棚に、大分類が 1 冊ずつ（本の中では中分類 → 小分類で絞り込める）。
 * 本を開くと中のページの一覧（表）になり、「ノートを開く」（見開きの閲覧）・「問題を解く」（問題解答モード）・「PDF 出力」ができる。
 * 表の行は取っ手のドラッグ（同じ分野の中）や矢印で並び替え、デッキの移動・編集・非表示・削除ができる。
 * 「今日の復習」は、忘却曲線で期限が来たページを本をまたいでまとめて解く。
 */
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import ActionMenu, { type ActionMenuItem } from '@/components/ActionMenu.vue'
import AuthImage from '@/components/AuthImage.vue'
import HelpTip from '@/components/HelpTip.vue'
import MistakeBinder from '@/components/MistakeBinder.vue'
import MistakeQuiz from '@/components/MistakeQuiz.vue'
import { mistakeApi } from '@/api/mistake'
import { appConfirm } from '@/lib/dialog'
import { showPdf, useInAppViewer } from '@/lib/docViewer'
import { DECK_COLORS, OTHER_SUBJECT, fmtDate, groupNotes, pageIndexOf, paginate, type MistakeGroup } from '@/lib/mistake'
import { canvasToJpeg, renderBinderPage, renderIndexPages, type IndexEntry } from '@/lib/mistakeRender'
import { haptic, viewportWidth } from '@/lib/native'
import { useUiStore } from '@/stores/ui'
import type { MistakeDeck, MistakeNoteDetail, MistakeNoteSummary, MistakeReviewState } from '@/types'

const router = useRouter()
const ui = useUiStore()

const notes = ref<MistakeNoteSummary[]>([])
const decks = ref<MistakeDeck[]>([])
const loading = ref(true)
const compact = computed(() => viewportWidth.value <= 900)

async function load() {
  loading.value = true
  try {
    const [n, d] = await Promise.all([mistakeApi.list(), mistakeApi.decks()])
    notes.value = n
    decks.value = d
  } catch {
    ui.notify('間違いノートの取得に失敗しました')
  } finally {
    loading.value = false
  }
}
onMounted(load)

// ================================================================
// 本棚
// ================================================================
type ShelfMode = 'deck' | 'category'
const mode = ref<ShelfMode>((localStorage.getItem('sm_mn_shelf') as ShelfMode | null) ?? 'deck')
watch(mode, (m) => {
  localStorage.setItem('sm_mn_shelf', m)
  book.value = null
})

/** 本（デッキ 1 つ、または 科目 × 大分類 1 つ） */
interface Book {
  key: string
  kind: 'deck' | 'category'
  title: string
  /** 表紙の小さい文字（分類別は科目名） */
  sub: string | null
  color: string
  notes: MistakeNoteSummary[]
  count: number
  due: number
  fresh: number
  deckId: number | null
  subjectId: number | null
  majorId: number | null
  /** 表紙の写真（最初のページの問題の写真） */
  thumb: string | null
}
interface Shelf {
  key: string
  label: string | null
  color: string | null
  books: Book[]
}

function deckColor(d: MistakeDeck, i: number): string {
  return d.color ?? DECK_COLORS[i % DECK_COLORS.length]!
}
function makeBook(base: Omit<Book, 'count' | 'due' | 'fresh' | 'thumb'>): Book {
  const shown = base.notes.filter((n) => !n.hidden)
  return {
    ...base,
    count: base.notes.length,
    due: shown.filter((n) => n.review.isDue).length,
    fresh: shown.filter((n) => n.review.isNew).length,
    thumb: shown[0]?.problemThumbUrl ?? base.notes[0]?.problemThumbUrl ?? null,
  }
}
const shelves = computed<Shelf[]>(() => {
  if (mode.value === 'deck') {
    const books: Book[] = decks.value.map((d, i) =>
      makeBook({ key: `d${d.id}`, kind: 'deck', title: d.name, sub: null, color: deckColor(d, i), notes: notes.value.filter((n) => n.deckId === d.id), deckId: d.id, subjectId: null, majorId: null }),
    )
    const loose = notes.value.filter((n) => !n.deckId)
    if (loose.length) books.push(makeBook({ key: 'd0', kind: 'deck', title: 'デッキなし', sub: null, color: '#9aa1ab', notes: loose, deckId: 0, subjectId: null, majorId: null }))
    return [{ key: 'decks', label: null, color: null, books }]
  }
  // 分類別: 科目ごとの棚に大分類の本（「その他」はデッキ別のみ）
  const out: Shelf[] = []
  for (const n of notes.value) {
    if (n.subjectId === OTHER_SUBJECT || n.subjectId == null) continue
    const sKey = `s${n.subjectId}`
    let shelf = out.find((x) => x.key === sKey)
    if (!shelf) {
      shelf = { key: sKey, label: n.subjectName ?? '科目なし', color: ui.colorOf(n.colorSoft ?? '#5b6b8c', n.colorVivid ?? '#5b6b8c'), books: [] }
      out.push(shelf)
    }
    const bKey = `c${n.subjectId}-${n.majorId ?? 0}`
    let b = shelf.books.find((x) => x.key === bKey)
    if (!b) {
      b = makeBook({ key: bKey, kind: 'category', title: n.majorName ?? '（大分類なし）', sub: n.subjectName, color: shelf.color!, notes: [], deckId: null, subjectId: n.subjectId, majorId: n.majorId ?? 0 })
      shelf.books.push(b)
    }
    b.notes.push(n)
  }
  // 件数などは本ごとに作り直す
  for (const s of out) s.books = s.books.map((b) => makeBook(b))
  return out
})
const allBooks = computed(() => shelves.value.flatMap((s) => s.books))
/** 期限が来ているページ（本をまたいで） */
const dueNotes = computed(() => notes.value.filter((n) => !n.hidden && n.review.isDue))
/** 分類別のときに本棚から外れる「その他」のページ数 */
const otherCount = computed(() => notes.value.filter((n) => n.subjectId === OTHER_SUBJECT).length)

/** 開いている本（本のキー。本棚を切り替えると閉じる） */
const book = ref<string | null>(null)
const openBook = computed(() => allBooks.value.find((b) => b.key === book.value) ?? null)
function open(b: Book) {
  book.value = b.key
  filter.midId = 0
  filter.studyItemId = 0
  filter.subject = 'all'
  if (b.kind === 'deck') localStorage.setItem('sm_mn_cat', JSON.stringify({ ...(safeJson(localStorage.getItem('sm_mn_cat')) as object), deckId: b.deckId ?? 0 }))
}
function safeJson(s: string | null): unknown {
  try {
    return JSON.parse(s ?? '{}') ?? {}
  } catch {
    return {}
  }
}

/** 2 色を混ぜる（color-mix は古い iPadOS の Safari で使えないため JS で） */
function mix(hex: string, other: string, t: number): string {
  const a = /^#?([0-9a-f]{6})$/i.exec(hex.trim())
  const b = /^#?([0-9a-f]{6})$/i.exec(other.trim())
  if (!a || !b) return hex
  const x = parseInt(a[1]!, 16)
  const y = parseInt(b[1]!, 16)
  const ch = (sh: number) => Math.round(((x >> sh) & 255) * (1 - t) + ((y >> sh) & 255) * t)
  return `#${[16, 8, 0].map((sh) => ch(sh).toString(16).padStart(2, '0')).join('')}`
}
/** 本の表紙・背表紙の色（CSS 変数） */
function bookStyle(color: string): Record<string, string> {
  return { '--c': color, '--c1': mix(color, '#ffffff', 0.08), '--c2': mix(color, '#000000', 0.22), '--c3': mix(color, '#000000', 0.4), '--c4': mix(color, '#000000', 0.15), '--t': textOn(color) }
}
/** バッジの背景色に対して読める文字色 */
function textOn(bg: string): string {
  const m = /^#?([0-9a-f]{6})$/i.exec(bg.trim())
  if (!m) return '#fff'
  const n = parseInt(m[1]!, 16)
  const lum = (0.2126 * ((n >> 16) & 255) + 0.7152 * ((n >> 8) & 255) + 0.0722 * (n & 255)) / 255
  return lum > 0.6 ? '#1c2024' : '#fff'
}

// ---- デッキの作成・編集・削除・並び替え ----
const deckDlg = reactive<{ open: boolean; id: number | null; name: string; color: string | null; busy: boolean }>({ open: false, id: null, name: '', color: null, busy: false })
function newDeck() {
  Object.assign(deckDlg, { open: true, id: null, name: '', color: DECK_COLORS[decks.value.length % DECK_COLORS.length], busy: false })
}
function editDeck(id: number) {
  const d = decks.value.find((x) => x.id === id)
  if (!d) return
  Object.assign(deckDlg, { open: true, id, name: d.name, color: d.color, busy: false })
}
async function saveDeck() {
  const name = deckDlg.name.trim()
  if (!name || deckDlg.busy) return
  deckDlg.busy = true
  try {
    if (deckDlg.id == null) {
      const d = await mistakeApi.createDeck({ name, color: deckDlg.color })
      decks.value = [...decks.value, d]
      ui.notify(`デッキ「${d.name}」を作りました`)
    } else {
      const d = await mistakeApi.updateDeck(deckDlg.id, { name, color: deckDlg.color })
      decks.value = decks.value.map((x) => (x.id === d.id ? d : x))
      notes.value = notes.value.map((n) => (n.deckId === d.id ? { ...n, deckName: d.name, deckColor: d.color } : n))
    }
    deckDlg.open = false
  } catch {
    ui.notify('デッキの保存に失敗しました')
  } finally {
    deckDlg.busy = false
  }
}
async function removeDeck(id: number) {
  const d = decks.value.find((x) => x.id === id)
  if (!d) return
  if (!(await appConfirm(`デッキ「${d.name}」を削除しますか？\n中のページは消えず「デッキなし」になります。`, { danger: true, okText: '削除' }))) return
  try {
    await mistakeApi.removeDeck(id)
    decks.value = decks.value.filter((x) => x.id !== id)
    notes.value = notes.value.map((n) => (n.deckId === id ? { ...n, deckId: null, deckName: null, deckColor: null } : n))
    if (book.value === `d${id}`) book.value = null
    ui.notify('デッキを削除しました')
  } catch {
    ui.notify('削除に失敗しました')
  }
}
async function moveDeck(id: number, dir: -1 | 1) {
  const ids = decks.value.map((d) => d.id)
  const i = ids.indexOf(id)
  const j = i + dir
  if (i < 0 || j < 0 || j >= ids.length) return
  ;[ids[i], ids[j]] = [ids[j]!, ids[i]!]
  try {
    decks.value = await mistakeApi.reorderDecks(ids)
    haptic()
  } catch {
    ui.notify('並び順の保存に失敗しました')
  }
}
function deckMenu(b: Book): (ActionMenuItem & { key: string })[] {
  const i = decks.value.findIndex((d) => d.id === b.deckId)
  const items: (ActionMenuItem & { key: string })[] = [{ key: 'edit', label: '名前・色を変更' }]
  if (i > 0) items.push({ key: 'left', label: '左へ' })
  if (i >= 0 && i < decks.value.length - 1) items.push({ key: 'right', label: '右へ' })
  items.push({ key: 'delete', label: 'デッキを削除', danger: true })
  return items
}
function onDeckMenu(b: Book, key: string) {
  if (!b.deckId) return
  if (key === 'edit') editDeck(b.deckId)
  else if (key === 'left') moveDeck(b.deckId, -1)
  else if (key === 'right') moveDeck(b.deckId, 1)
  else if (key === 'delete') removeDeck(b.deckId)
}

// ================================================================
// 本の中（ページの一覧）
// ================================================================
const filter = reactive({ subject: 'all' as string, midId: 0, studyItemId: 0 })
/** デッキの本の中: 科目で絞る */
const subjectTabs = computed(() => {
  const out: { key: string; name: string; color: string | null; count: number }[] = []
  for (const n of openBook.value?.notes ?? []) {
    const key = String(n.subjectId ?? 0)
    const t = out.find((x) => x.key === key)
    if (t) t.count++
    else out.push({ key, name: n.subjectName ?? '科目なし', color: ui.colorOf(n.colorSoft ?? '#5b6b8c', n.colorVivid ?? '#5b6b8c'), count: 1 })
  }
  return out
})
/** 分類の本の中: 中分類 → 小分類で絞る */
const midChips = computed(() => {
  if (openBook.value?.kind !== 'category') return []
  const out: { id: number; name: string; count: number }[] = []
  for (const n of openBook.value.notes) {
    const id = n.midId ?? 0
    const t = out.find((x) => x.id === id)
    if (t) t.count++
    else out.push({ id, name: n.midName ?? '（中分類なし）', count: 1 })
  }
  return out
})
const subChips = computed(() => {
  if (openBook.value?.kind !== 'category' || !filter.midId) return []
  const out: { id: number; name: string; count: number }[] = []
  for (const n of openBook.value.notes) {
    if ((n.midId ?? 0) !== filter.midId) continue
    const id = n.studyItemId ?? 0
    const t = out.find((x) => x.id === id)
    if (t) t.count++
    else out.push({ id, name: n.studyItemName ?? '（小分類なし）', count: 1 })
  }
  return out.length > 1 ? out : []
})
function pickMid(id: number) {
  filter.midId = id
  filter.studyItemId = 0
}
const visible = computed(() =>
  (openBook.value?.notes ?? []).filter(
    (n) =>
      (filter.subject === 'all' || String(n.subjectId ?? 0) === filter.subject) &&
      (filter.midId === 0 || (n.midId ?? 0) === filter.midId) &&
      (filter.studyItemId === 0 || (n.studyItemId ?? 0) === filter.studyItemId),
  ),
)
const groups = computed(() => groupNotes(visible.value))
/** 科目ごとに 1 つのテーブル（行は分野＝大分類・中分類の順。並び替えは同じ分野の中だけ） */
interface SubjectRow {
  n: MistakeNoteSummary
  g: MistakeGroup
  /** 分野の中での位置 */
  i: number
}
const subjectBlocks = computed(() => {
  const out: { key: string; name: string; color: string; rows: SubjectRow[] }[] = []
  for (const g of groups.value) {
    const key = String(g.subjectId ?? 0)
    let s = out.find((x) => x.key === key)
    if (!s) {
      const n = g.notes[0]!
      s = { key, name: g.subjectName, color: ui.colorOf(n.colorSoft ?? '#5b6b8c', n.colorVivid ?? '#5b6b8c'), rows: [] }
      out.push(s)
    }
    g.notes.forEach((n, i) => s!.rows.push({ n, g, i }))
  }
  return out
})
const shownCount = computed(() => visible.value.filter((n) => !n.hidden).length)

/** 復習の状態の短い表示（一覧の列） */
function reviewLabel(r: MistakeReviewState): { text: string; cls: string } {
  if (r.isNew) return { text: '未学習', cls: 'new' }
  if (r.isDue) return { text: '復習', cls: 'due' }
  const d = r.dueAt ? new Date(r.dueAt) : null
  return { text: d ? `${d.getMonth() + 1}/${d.getDate()} まで` : '—', cls: 'ok' }
}

// ---- 行の操作 ----
function edit(n: MistakeNoteSummary) {
  router.push({ name: 'mistake-edit', params: { id: n.id } })
}
function addNote() {
  const b = openBook.value
  router.push({ name: 'mistake-new', query: b?.kind === 'deck' && b.deckId ? { deck: String(b.deckId) } : undefined })
}
async function toggleHidden(n: MistakeNoteSummary) {
  try {
    const d = await mistakeApi.update(n.id, { hidden: !n.hidden })
    n.hidden = d.hidden
    ui.notify(d.hidden ? 'ノートから非表示にしました' : '表示に戻しました')
  } catch {
    ui.notify('更新に失敗しました')
  }
}
async function remove(n: MistakeNoteSummary) {
  if (!(await appConfirm(`「${[n.studyItemName, n.title].filter(Boolean).join('　') || '（無題）'}」を削除しますか？`, { danger: true, okText: '削除' }))) return
  try {
    await mistakeApi.remove(n.id)
    notes.value = notes.value.filter((x) => x.id !== n.id)
    ui.notify('削除しました')
  } catch {
    ui.notify('削除に失敗しました')
  }
}
/** 行のデッキを変える（一覧のプルダウン） */
async function setDeck(n: MistakeNoteSummary, e: Event) {
  const deckId = Number((e.target as HTMLSelectElement).value) || null
  const prev = { deckId: n.deckId, deckName: n.deckName, deckColor: n.deckColor }
  const d = decks.value.find((x) => x.id === deckId)
  Object.assign(n, { deckId, deckName: d?.name ?? null, deckColor: d?.color ?? null })
  try {
    decks.value = await mistakeApi.assignDeck([n.id], deckId)
    haptic()
  } catch {
    Object.assign(n, prev)
    ui.notify('デッキの変更に失敗しました')
  }
}

/** 同じ分野の中で前後へ */
async function move(n: MistakeNoteSummary, dir: -1 | 1) {
  const g = groups.value.find((x) => x.notes.includes(n))
  if (!g) return
  const i = g.notes.indexOf(n)
  const j = i + dir
  if (j < 0 || j >= g.notes.length) return
  await reorderWithin(g, i, j)
}
async function reorderWithin(g: MistakeGroup, from: number, to: number) {
  const list = notes.value.slice()
  const ids = g.notes.map((x) => x.id)
  const [moved] = ids.splice(from, 1)
  if (moved === undefined) return
  ids.splice(to, 0, moved)
  // 全体の並びの中で、この分野の位置に新しい順で入れ直す
  const positions = list.map((x, idx) => (g.notes.includes(x) ? idx : -1)).filter((x) => x >= 0)
  const byId = new Map(list.map((x) => [x.id, x]))
  positions.forEach((p, k) => (list[p] = byId.get(ids[k]!)!))
  const prev = notes.value
  notes.value = list
  try {
    notes.value = await mistakeApi.reorder(list.map((x) => x.id))
    haptic()
  } catch {
    notes.value = prev
    ui.notify('並び順の保存に失敗しました')
  }
}

type ActionKey = 'open' | 'up' | 'down' | 'hide' | 'delete'
function secondary(n: MistakeNoteSummary, i: number, g: MistakeGroup): (ActionMenuItem & { key: ActionKey })[] {
  const items: (ActionMenuItem & { key: ActionKey })[] = []
  if (!n.hidden) items.push({ key: 'open', label: 'ノートで開く' })
  if (i > 0) items.push({ key: 'up', label: '上へ' })
  if (i < g.notes.length - 1) items.push({ key: 'down', label: '下へ' })
  items.push({ key: 'hide', label: n.hidden ? '表示に戻す' : '非表示にする' })
  items.push({ key: 'delete', label: '削除', danger: true })
  return items
}
function onAction(n: MistakeNoteSummary, key: string) {
  if (key === 'open') openBinder(n.id)
  else if (key === 'up') move(n, -1)
  else if (key === 'down') move(n, 1)
  else if (key === 'hide') toggleHidden(n)
  else if (key === 'delete') remove(n)
}

// 行のドラッグ（取っ手を持って同じ分野の中で動かす）
const drag = reactive<{ id: number; groupKey: string; over: number | null; after: boolean; active: boolean; x0: number; y0: number }>({ id: 0, groupKey: '', over: null, after: false, active: false, x0: 0, y0: 0 })
function onHandleDown(e: PointerEvent, n: MistakeNoteSummary, g: MistakeGroup) {
  if (e.pointerType === 'mouse' && e.button !== 0) return
  ;(e.currentTarget as HTMLElement).setPointerCapture(e.pointerId)
  Object.assign(drag, { id: n.id, groupKey: g.key, over: null, after: false, active: false, x0: e.clientX, y0: e.clientY })
}
function onHandleMove(e: PointerEvent) {
  if (!drag.id) return
  if (!drag.active && Math.hypot(e.clientX - drag.x0, e.clientY - drag.y0) < 6) return
  drag.active = true
  const el = document.elementsFromPoint(e.clientX, e.clientY).find((x) => (x as HTMLElement).classList?.contains('mrow')) as HTMLElement | undefined
  if (!el || el.dataset.group !== drag.groupKey) {
    drag.over = null
    return
  }
  const r = el.getBoundingClientRect()
  drag.over = Number(el.dataset.id)
  drag.after = e.clientY > r.top + r.height / 2
}
async function onHandleUp() {
  if (!drag.id) return
  const { id, groupKey, over, after, active } = drag
  drag.id = 0
  drag.active = false
  if (!active || over === null || over === id) return
  const g = groups.value.find((x) => x.key === groupKey)
  if (!g) return
  const from = g.notes.findIndex((x) => x.id === id)
  let to = g.notes.findIndex((x) => x.id === over)
  if (from < 0 || to < 0) return
  if (after) to += 1
  if (to > from) to -= 1
  if (to === from) return
  await reorderWithin(g, from, to)
}

// ================================================================
// 全画面のノート（見開き）
// ================================================================
const binderOpen = ref(false)
const binderStart = ref<number | null>(null)
const binderList = ref<MistakeNoteSummary[]>([])
function openBinder(startId: number | null = null, list: MistakeNoteSummary[] = visible.value) {
  const shown = list.filter((n) => !n.hidden)
  if (!shown.length) {
    ui.notify('表示できる問題がありません')
    return
  }
  binderList.value = shown
  binderStart.value = startId
  binderOpen.value = true
}
/** 本棚から全部のノートを開く（分類別の並び） */
function openAllBinder() {
  openBinder(null, notes.value)
}

// ================================================================
// 問題解答モード（忘却曲線に合わせて出題）
// ================================================================
const quiz = ref<{ notes: MistakeNoteSummary[]; title: string } | null>(null)
const quizDlg = ref<{ title: string; all: MistakeNoteSummary[]; due: MistakeNoteSummary[]; fresh: MistakeNoteSummary[] } | null>(null)
/** 出題の順: 期限が来たもの（期限の古い順）→ 未学習（ノートの順）→ まだ期限ではないもの（期限の近い順） */
function orderForQuiz(list: MistakeNoteSummary[]): MistakeNoteSummary[] {
  const t = (n: MistakeNoteSummary) => (n.review.dueAt ? new Date(n.review.dueAt).getTime() : 0)
  const due = list.filter((n) => n.review.isDue).sort((a, b) => t(a) - t(b))
  const fresh = list.filter((n) => n.review.isNew)
  const rest = list.filter((n) => !n.review.isDue && !n.review.isNew).sort((a, b) => t(a) - t(b))
  return [...due, ...fresh, ...rest]
}
function askQuiz(list: MistakeNoteSummary[], title: string) {
  const all = list.filter((n) => !n.hidden)
  if (!all.length) {
    ui.notify('解ける問題がありません')
    return
  }
  quizDlg.value = { title, all, due: all.filter((n) => n.review.isDue), fresh: all.filter((n) => n.review.isNew) }
}
function startQuiz(which: 'review' | 'all') {
  const d = quizDlg.value
  if (!d) return
  const list = which === 'all' ? d.all : [...d.due, ...d.fresh]
  quizDlg.value = null
  if (!list.length) return
  quiz.value = { notes: orderForQuiz(list), title: d.title }
}
/** 今日の復習: 期限が来たページを本をまたいで */
function startDue() {
  if (!dueNotes.value.length) return
  quiz.value = { notes: orderForQuiz(dueNotes.value), title: '今日の復習' }
}
function onGraded(noteId: number, review: MistakeReviewState) {
  const n = notes.value.find((x) => x.id === noteId)
  if (n) n.review = review
}

// ================================================================
// PDF 出力（目次 + ページ割りした各ページ。両面印刷でとじ側にパンチの余白）
// ================================================================
const pdfProgress = ref<{ done: number; total: number } | null>(null)
async function exportPdf() {
  const list = visible.value.filter((n) => !n.hidden)
  if (!list.length) {
    ui.notify('出力できる問題がありません')
    return
  }
  if (pdfProgress.value) return
  const w = useInAppViewer ? null : window.open('', '_blank')
  const pages = paginate(list)
  pdfProgress.value = { done: 0, total: pages.length + 1 }
  try {
    const SCALE = 1.4
    const b = openBook.value
    const midName = filter.midId ? (midChips.value.find((m) => m.id === filter.midId)?.name ?? '') : ''
    const title = ['間違いノート', b?.sub ?? '', b?.title ?? '', midName].filter(Boolean).join('　')
    // 目次ページ数が決まるまで繰り返す（目次が増えるとページ番号がずれるため）
    let indexCount = 1
    let indexPages: HTMLCanvasElement[] = []
    for (let pass = 0; pass < 4; pass++) {
      const entries: IndexEntry[] = list.map((n) => ({
        subjectName: n.subjectName ?? '科目なし',
        color: n.colorSoft,
        field: n.field || '（分野なし）',
        title: [n.studyItemName, n.title].filter(Boolean).join('　') || n.source || fmtDate(n.notedOn),
        pageNo: indexCount + pageIndexOf(pages, n.id) + 1,
      }))
      indexPages = renderIndexPages(entries, 1, title, SCALE)
      if (indexPages.length === indexCount) break
      indexCount = indexPages.length
    }
    const out = [...indexPages]
    pdfProgress.value = { done: 1, total: pages.length + 1 }
    // 各問題の詳細（配置）を読み込みながらページを描く
    const details = new Map<number, MistakeNoteDetail>()
    for (let i = 0; i < pages.length; i++) {
      const page = pages[i]!
      await Promise.all(page.entries.map((e) => (details.has(e.note.id) ? Promise.resolve() : mistakeApi.show(e.note.id).then((d) => details.set(e.note.id, d)))))
      out.push(await renderBinderPage(page, details, { pageNo: indexCount + i + 1, scale: SCALE }))
      pdfProgress.value = { done: i + 2, total: pages.length + 1 }
    }
    // 両面印刷で最後の裏が白紙になるよう、奇数枚なら白紙を足す
    if (out.length % 2 === 1) {
      const blank = document.createElement('canvas')
      blank.width = out[0]!.width
      blank.height = out[0]!.height
      const x = blank.getContext('2d')!
      x.fillStyle = '#fff'
      x.fillRect(0, 0, blank.width, blank.height)
      out.push(blank)
    }
    const images = []
    for (const c of out) images.push({ blob: await canvasToJpeg(c, 0.86), width: c.width, height: c.height })
    const pdf = await (await import('@/lib/pdfWriter')).jpegsToPdf(images)
    const name = `${title}.pdf`
    if (useInAppViewer) {
      showPdf(pdf, name)
    } else {
      const url = URL.createObjectURL(pdf)
      if (w) w.location.href = url
      else window.open(url, '_blank')
    }
  } catch (e) {
    w?.close()
    ui.notify(e instanceof Error && e.message ? e.message : 'PDF の作成に失敗しました')
  } finally {
    pdfProgress.value = null
  }
}
</script>

<template>
  <div>
    <!-- ============ 本棚 ============ -->
    <template v-if="!openBook">
      <div class="top">
        <div class="ttl">
          <span>間違いノート</span>
          <HelpTip
            text="間違えた問題を写真に撮って、間違えた部分や解説を書き込んでまとめます。ページは本棚の「本」にまとまり、「デッキ別」（自分で作ったデッキごと）と「分類別」（科目の棚に大分類の本）を切り替えられます。科目「その他」のページはデッキ別にだけ出ます。本を開くと中のページの一覧になり、「ノートを開く」で見開きの閲覧（ページをめくるアニメーション）、「問題を解く」で問題だけを見て解き「解答を表示」→ もう一度・難しい・正解・簡単 で評価（忘却曲線に合わせて次に出る日が決まります）、「PDF 出力」で両面印刷用の PDF を作れます。「今日の復習」は期限が来たページを本をまたいでまとめて解きます。"
          />
        </div>
        <div class="actions">
          <div class="seg" v-seg>
            <button class="seg-b" :class="{ on: mode === 'deck' }" @click="mode = 'deck'">デッキ別</button>
            <button class="seg-b" :class="{ on: mode === 'category' }" @click="mode = 'category'">分類別</button>
          </div>
          <button class="btn-ghost due-btn" :disabled="!dueNotes.length" :title="dueNotes.length ? '忘却曲線で期限が来たページをまとめて解く' : '期限が来たページはありません'" @click="startDue">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></svg>今日の復習<span v-if="dueNotes.length" class="cnt hot">{{ dueNotes.length }}</span>
          </button>
          <button v-if="!compact" class="btn-ghost" :disabled="!notes.some((n) => !n.hidden)" @click="openAllBinder">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 5h7a2 2 0 0 1 2 2v13a2 2 0 0 0-2-2H3z" /><path d="M21 5h-7a2 2 0 0 0-2 2v13a2 2 0 0 1 2-2h7z" /></svg>すべて開く
          </button>
          <button class="btn-dark" @click="addNote">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 5v14M5 12h14" /></svg>問題を追加
          </button>
        </div>
      </div>

      <div v-if="loading" class="hint" style="text-align: center">読み込み中…</div>
      <div v-else-if="!notes.length && !decks.length" class="hint" style="text-align: center">
        まだ問題がありません。「問題を追加」から、間違えた問題を撮影して登録してください。
      </div>
      <template v-else>
        <div v-for="s in shelves" :key="s.key" class="shelf-sec">
          <div v-if="s.label" class="shelf-label"><span class="subj-badge" :style="{ background: s.color!, color: textOn(s.color!) }">{{ s.label }}</span><span class="gcount">{{ s.books.reduce((a, b) => a + b.count, 0) }}問</span></div>
          <div class="shelf">
            <div v-for="b in s.books" :key="b.key" class="book-slot">
              <button class="book" :style="bookStyle(b.color)" :title="`${b.title}を開く`" @click="open(b)">
                <span class="spine"></span>
                <span class="cover">
                  <span v-if="b.sub" class="b-sub">{{ b.sub }}</span>
                  <span class="b-title">{{ b.title }}</span>
                  <span class="b-thumb"><AuthImage v-if="b.thumb" :src="b.thumb" alt="" /></span>
                  <span class="b-foot">
                    <span class="b-count">{{ b.count }}問</span>
                    <span v-if="b.due" class="b-badge due">復習 {{ b.due }}</span>
                    <span v-else-if="b.fresh" class="b-badge fresh">未学習 {{ b.fresh }}</span>
                  </span>
                </span>
                <span class="pages"></span>
              </button>
              <div v-if="b.kind === 'deck' && b.deckId" class="book-menu" @click.stop>
                <ActionMenu :items="deckMenu(b)" @select="onDeckMenu(b, $event)" />
              </div>
            </div>
            <!-- デッキ別: 新しいデッキ -->
            <div v-if="mode === 'deck'" class="book-slot">
              <button class="book ghost" title="新しいデッキを作る" @click="newDeck">
                <span class="cover">
                  <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 5v14M5 12h14" /></svg>
                  <span class="b-title">新しいデッキ</span>
                </span>
              </button>
            </div>
          </div>
        </div>
        <div v-if="mode === 'category' && !shelves.length" class="hint" style="text-align: center">分類のあるページがありません。</div>
        <div v-if="mode === 'category' && otherCount" class="foot-note">科目「その他」のページ（{{ otherCount }}問）はデッキ別にだけ表示されます。</div>
      </template>
    </template>

    <!-- ============ 本の中（ページの一覧） ============ -->
    <template v-else>
      <div class="top">
        <div class="ttl book-ttl">
          <button class="back" @click="book = null">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7" /></svg>本棚
          </button>
          <span class="book-chip" :style="{ background: openBook.color, color: textOn(openBook.color) }">{{ openBook.kind === 'deck' ? 'デッキ' : openBook.sub }}</span>
          <span>{{ openBook.title }}</span>
          <span class="gcount">{{ openBook.count }}問</span>
        </div>
        <div class="actions">
          <button class="btn-ghost" :disabled="!shownCount" @click="openBinder()">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 5h7a2 2 0 0 1 2 2v13a2 2 0 0 0-2-2H3z" /><path d="M21 5h-7a2 2 0 0 0-2 2v13a2 2 0 0 1 2-2h7z" /></svg>ノートを開く
          </button>
          <button class="btn-ghost" :disabled="!shownCount" @click="askQuiz(visible, openBook.title)">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 9a3 3 0 1 1 4.5 2.6c-.9.5-1.5 1.2-1.5 2.4" /><circle cx="12" cy="18" r=".6" fill="currentColor" /><rect x="3" y="3" width="18" height="18" rx="3" /></svg>問題を解く<span v-if="openBook.due" class="cnt hot">{{ openBook.due }}</span>
          </button>
          <button class="btn-ghost" :disabled="!shownCount || !!pdfProgress" @click="exportPdf">
            <template v-if="pdfProgress">PDF 作成中 {{ pdfProgress.done }}/{{ pdfProgress.total }}</template>
            <template v-else>PDF 出力</template>
          </button>
          <button class="btn-dark" @click="addNote">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 5v14M5 12h14" /></svg>問題を追加
          </button>
        </div>
      </div>

      <!-- 絞り込み -->
      <div v-if="subjectTabs.length > 1 || midChips.length" class="filters">
        <div v-if="openBook.kind === 'deck' && subjectTabs.length > 1" class="tabs">
          <button class="tab" :class="{ on: filter.subject === 'all' }" @click="filter.subject = 'all'">すべて<span class="cnt">{{ openBook.count }}</span></button>
          <button v-for="t in subjectTabs" :key="t.key" class="tab" :class="{ on: filter.subject === t.key }" :style="{ '--c': t.color ?? '#5b6b8c' }" @click="filter.subject = t.key">
            <span class="dot"></span>{{ t.name }}<span class="cnt">{{ t.count }}</span>
          </button>
        </div>
        <div v-if="midChips.length" class="chips">
          <span class="chips-label">中分類</span>
          <button class="chip" :class="{ on: filter.midId === 0 }" @click="pickMid(0)">すべて</button>
          <button v-for="m in midChips" :key="m.id" class="chip" :class="{ on: filter.midId === m.id }" @click="pickMid(m.id)">{{ m.name }}<span class="cnt">{{ m.count }}</span></button>
        </div>
        <div v-if="subChips.length" class="chips">
          <span class="chips-label">小分類</span>
          <button class="chip" :class="{ on: filter.studyItemId === 0 }" @click="filter.studyItemId = 0">すべて</button>
          <button v-for="m in subChips" :key="m.id" class="chip" :class="{ on: filter.studyItemId === m.id }" @click="filter.studyItemId = m.id">{{ m.name }}<span class="cnt">{{ m.count }}</span></button>
        </div>
      </div>

      <div v-if="!openBook.notes.length" class="hint" style="text-align: center">この本にはまだページがありません。「問題を追加」で登録するか、他の本のページの「デッキ」をこのデッキに変えてください。</div>
      <div v-else-if="!visible.length" class="hint" style="text-align: center">この絞り込みに当てはまる問題はありません。</div>

      <!-- 科目ごとに 1 つのテーブル（大分類・中分類は列） -->
      <div v-for="s in subjectBlocks" :key="s.key" class="group">
        <div v-if="openBook.kind === 'deck'" class="subj">
          <span class="subj-badge" :style="{ background: s.color, color: textOn(s.color) }">{{ s.name }}</span>
          <span class="gcount">{{ s.rows.length }}問</span>
        </div>
        <div class="tbl-wrap">
          <table class="tbl">
            <thead>
              <tr>
                <th class="c-drag"></th>
                <th class="c-cat">大分類</th>
                <th class="c-cat">中分類</th>
                <th>小分類</th>
                <th class="c-src">出典</th>
                <th class="c-date">日付</th>
                <th class="c-deck">デッキ</th>
                <th class="c-rev">復習</th>
                <th class="c-state">状態</th>
                <th class="c-actions"></th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="r in s.rows"
                :key="r.n.id"
                class="mrow"
                :class="{ hidden: r.n.hidden, lifted: drag.active && drag.id === r.n.id, 'ins-before': drag.active && drag.over === r.n.id && !drag.after, 'ins-after': drag.active && drag.over === r.n.id && drag.after, 'group-start': r.i === 0 }"
                :data-id="r.n.id"
                :data-group="r.g.key"
              >
                <td class="c-drag">
                  <button class="handle" title="ドラッグで並び替え（同じ大分類・中分類の中）" aria-label="並び替え" @pointerdown="onHandleDown($event, r.n, r.g)" @pointermove="onHandleMove" @pointerup="onHandleUp" @pointercancel="onHandleUp">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><circle cx="9" cy="6" r="1.7" /><circle cx="15" cy="6" r="1.7" /><circle cx="9" cy="12" r="1.7" /><circle cx="15" cy="12" r="1.7" /><circle cx="9" cy="18" r="1.7" /><circle cx="15" cy="18" r="1.7" /></svg>
                  </button>
                </td>
                <td class="c-cat">{{ r.n.majorName || '–' }}</td>
                <td class="c-cat">{{ r.n.midName || '–' }}</td>
                <td class="c-title">{{ r.n.studyItemName || r.n.title || '–' }}</td>
                <td class="c-src">{{ r.n.source || '–' }}</td>
                <td class="c-date nowrap">{{ fmtDate(r.n.notedOn) }}</td>
                <td class="c-deck">
                  <select class="deck-sel" :value="r.n.deckId ?? 0" title="このページのデッキ" @change="setDeck(r.n, $event)">
                    <option :value="0">—</option>
                    <option v-for="d in decks" :key="d.id" :value="d.id">{{ d.name }}</option>
                  </select>
                </td>
                <td class="c-rev"><span class="badge" :class="reviewLabel(r.n.review).cls">{{ reviewLabel(r.n.review).text }}</span></td>
                <td class="c-state"><span class="badge" :class="r.n.hidden ? 'off' : 'on'">{{ r.n.hidden ? '非表示' : '表示' }}</span></td>
                <td class="c-actions">
                  <div class="acts">
                    <button class="btn primary" @click="edit(r.n)">編集</button>
                    <ActionMenu v-if="compact" :items="secondary(r.n, r.i, r.g)" @select="onAction(r.n, $event)" />
                    <template v-else>
                      <button v-for="it in secondary(r.n, r.i, r.g)" :key="it.key" class="btn" :class="{ danger: it.danger }" @click="onAction(r.n, it.key)">{{ it.label }}</button>
                    </template>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>

    <!-- デッキの作成・編集 -->
    <Transition name="ui-modal">
      <div v-if="deckDlg.open" class="overlay ui-overlay ui-sheet ui-swipe" @click="deckDlg.open = false">
        <div class="modal ui-panel" @click.stop>
          <div class="m-title">{{ deckDlg.id == null ? '新しいデッキ' : 'デッキを編集' }}</div>
          <label class="fld">
            <span>名前</span>
            <input v-model="deckDlg.name" placeholder="例: 数学 定期テスト対策" @keydown.enter.prevent="saveDeck" />
          </label>
          <div class="fld">
            <span>色</span>
            <div class="swatches">
              <button v-for="c in DECK_COLORS" :key="c" class="sw" :class="{ on: deckDlg.color === c }" :style="{ background: c }" :aria-label="c" @click="deckDlg.color = c"></button>
            </div>
          </div>
          <div class="m-actions">
            <button class="btn-ghost" @click="deckDlg.open = false">キャンセル</button>
            <button class="btn-dark" :disabled="!deckDlg.name.trim() || deckDlg.busy" @click="saveDeck">{{ deckDlg.id == null ? '作成' : '保存' }}</button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- 問題を解く: 出題の範囲 -->
    <Transition name="ui-modal">
      <div v-if="quizDlg" class="overlay ui-overlay ui-sheet ui-swipe" @click="quizDlg = null">
        <div class="modal ui-panel" @click.stop>
          <div class="m-title">問題を解く<span class="m-sub">{{ quizDlg.title }}</span></div>
          <button class="q-opt" :disabled="!quizDlg.due.length && !quizDlg.fresh.length" @click="startQuiz('review')">
            <span class="q-opt-t">復習する</span>
            <span class="q-opt-d">期限が来た {{ quizDlg.due.length }} 問 ＋ 未学習 {{ quizDlg.fresh.length }} 問（忘却曲線に合わせた順）</span>
          </button>
          <button class="q-opt" @click="startQuiz('all')">
            <span class="q-opt-t">すべて解く</span>
            <span class="q-opt-d">{{ quizDlg.all.length }} 問（期限が来たもの → 未学習 → それ以外）</span>
          </button>
          <div class="m-actions">
            <button class="btn-ghost" @click="quizDlg = null">キャンセル</button>
          </div>
        </div>
      </div>
    </Transition>

    <Transition name="ui-modal">
      <MistakeBinder v-if="binderOpen" :notes="binderList" :start-id="binderStart" @close="binderOpen = false" />
    </Transition>
    <Transition name="ui-modal">
      <MistakeQuiz v-if="quiz" :notes="quiz.notes" :title="quiz.title" @graded="onGraded" @close="quiz = null" />
    </Transition>
  </div>
</template>

<style scoped>
.top {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
  margin-bottom: 14px;
}
.ttl {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 17px;
  font-weight: 700;
}
.book-ttl {
  min-width: 0;
  flex-wrap: wrap;
}
.back {
  display: flex;
  align-items: center;
  gap: 2px;
  padding: 7px 10px 7px 6px;
  border: 1px solid #e3e6ea;
  border-radius: 9px;
  background: #fff;
  color: var(--mut);
  font-size: 12.5px;
  font-weight: 600;
  cursor: pointer;
}
.book-chip {
  font-size: 11px;
  font-weight: 700;
  padding: 3px 10px;
  border-radius: 999px;
}
.actions {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  align-items: center;
}
.seg {
  position: relative;
  display: inline-flex;
  padding: 3px;
  border-radius: 10px;
  background: #e9ecf1;
}
.seg-b {
  padding: 6px 14px;
  border: none;
  border-radius: 8px;
  background: transparent;
  font-size: 12.5px;
  font-weight: 700;
  color: var(--mut);
  cursor: pointer;
}
.seg-b.on {
  background: #fff;
  color: var(--ink);
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.12);
}
.btn-dark {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 9px 16px;
  border: none;
  border-radius: 10px;
  background: #1c2024;
  color: #fff;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
}
.btn-ghost {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 9px 14px;
  border: 1px solid #e3e6ea;
  border-radius: 10px;
  background: #fff;
  cursor: pointer;
  font-size: 13px;
  font-weight: 600;
  color: var(--mut);
}
.btn-ghost:disabled,
.btn-dark:disabled {
  opacity: 0.4;
  cursor: default;
}
.cnt.hot {
  background: #e0533d;
  margin-left: 2px;
}
.hint {
  background: #f8f9fb;
  border: 1px dashed #d8dce1;
  border-radius: 14px;
  padding: 18px;
  font-size: 12.5px;
  color: var(--faint);
  line-height: 1.7;
}
.foot-note {
  margin-top: 6px;
  font-size: 11.5px;
  color: var(--faint);
}

/* ---- 本棚 ---- */
.shelf-sec {
  margin-bottom: 10px;
}
.shelf-label {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 6px 2px 4px;
}
.shelf {
  --bh: 196px; /* 本の高さ */
  --board: 14px; /* 棚板の厚み */
  --gap: 26px; /* 棚板の下の余白 */
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 0 18px;
  padding: 14px 18px 0;
  border-radius: 16px;
  /* 本の高さごとに棚板を描く（行の数に関係なく揃う。周期 = .book-slot の高さ、位相は上の余白 14px） */
  background:
    repeating-linear-gradient(
      to bottom,
      transparent 0,
      transparent var(--bh),
      #b98a5a var(--bh),
      #8d6238 calc(var(--bh) + var(--board)),
      rgba(0, 0, 0, 0.12) calc(var(--bh) + var(--board)),
      transparent calc(var(--bh) + var(--board) + 6px),
      transparent calc(var(--bh) + var(--board) + var(--gap))
    ),
    linear-gradient(#f4f1ec, #ece6dd);
  background-position:
    0 14px,
    0 0;
  border: 1px solid #e3dccf;
}
.book-slot {
  position: relative;
  height: calc(var(--bh) + var(--board) + var(--gap));
  display: flex;
  align-items: flex-start;
}
.book {
  position: relative;
  width: 136px;
  height: var(--bh);
  border: none;
  padding: 0;
  background: transparent;
  cursor: pointer;
  text-align: left;
  transform-origin: bottom center;
  transition: transform 0.22s cubic-bezier(0.2, 0.9, 0.25, 1);
  perspective: 800px;
}
.book:hover {
  transform: translateY(-6px) scale(1.02);
}
.book:active {
  transform: translateY(-2px) scale(0.99);
}
.cover {
  position: absolute;
  inset: 0 0 0 10px;
  display: flex;
  flex-direction: column;
  padding: 10px 10px 8px 12px;
  border-radius: 3px 8px 8px 3px;
  background: linear-gradient(135deg, var(--c1), var(--c2) 100%);
  color: var(--t);
  box-shadow:
    0 10px 18px -8px rgba(0, 0, 0, 0.45),
    inset 2px 0 0 rgba(255, 255, 255, 0.25);
  overflow: hidden;
}
.spine {
  position: absolute;
  left: 0;
  top: 3px;
  bottom: 3px;
  width: 12px;
  border-radius: 3px 0 0 3px;
  background: linear-gradient(90deg, var(--c3), var(--c4));
}
.pages {
  position: absolute;
  right: -4px;
  top: 6px;
  bottom: 6px;
  width: 5px;
  border-radius: 0 2px 2px 0;
  background: repeating-linear-gradient(to bottom, #fff 0 2px, #d9d9d9 2px 3px);
  box-shadow: 1px 0 0 rgba(0, 0, 0, 0.2);
}
.b-sub {
  font-size: 10px;
  font-weight: 700;
  opacity: 0.85;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.b-title {
  font-size: 13.5px;
  font-weight: 800;
  line-height: 1.3;
  display: -webkit-box;
  -webkit-line-clamp: 3;
  -webkit-box-orient: vertical;
  overflow: hidden;
  word-break: break-all;
}
.b-thumb {
  flex: 1;
  min-height: 0;
  margin: 8px 0 6px;
  border-radius: 4px;
  background: rgba(255, 255, 255, 0.35);
  overflow: hidden;
}
.b-thumb :deep(img) {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}
.b-foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 4px;
  font-size: 10.5px;
  font-weight: 700;
}
.b-badge {
  padding: 1px 7px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.92);
  color: #1c2024;
  white-space: nowrap;
}
.b-badge.due {
  background: #e0533d;
  color: #fff;
}
.book.ghost .cover {
  inset: 0;
  align-items: center;
  justify-content: center;
  gap: 6px;
  background: transparent;
  border: 2px dashed #c8bfae;
  color: #8d6238;
  box-shadow: none;
}
.book.ghost .b-title {
  font-size: 12px;
}
.book-menu {
  position: absolute;
  right: -6px;
  top: -4px;
}
.book-menu :deep(button) {
  background: rgba(255, 255, 255, 0.9);
  border-radius: 50%;
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.25);
}

/* ---- 本の中 ---- */
.filters {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-bottom: 14px;
}
.tabs {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}
.tab {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 7px 12px;
  border: 1px solid #e3e6ea;
  border-radius: 99px;
  background: #fff;
  font-size: 12.5px;
  font-weight: 600;
  color: var(--mut);
  cursor: pointer;
}
.tab .dot {
  width: 9px;
  height: 9px;
  border-radius: 50%;
  background: var(--c);
}
.tab.on {
  background: #1c2024;
  border-color: #1c2024;
  color: #fff;
}
.cnt {
  font-size: 10.5px;
  font-weight: 700;
  color: #fff;
  background: #c3c8d0;
  border-radius: 99px;
  padding: 0 6px;
  line-height: 16px;
  margin-left: 4px;
}
.tab.on .cnt,
.chip.on .cnt {
  background: rgba(255, 255, 255, 0.3);
}
.chips {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}
.chips-label {
  font-size: 11px;
  font-weight: 700;
  color: var(--faint);
  margin-right: 2px;
}
.chip {
  padding: 5px 11px;
  border: 1px solid #e3e6ea;
  border-radius: 99px;
  background: #fff;
  font-size: 11.5px;
  font-weight: 600;
  color: var(--mut);
  cursor: pointer;
}
.chip.on {
  background: #eef0f5;
  border-color: #c1c8f0;
  color: #3b50cc;
}
.group {
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin-bottom: 18px;
}
.subj {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 0 2px;
}
.subj-badge {
  display: inline-block;
  padding: 3px 12px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 700;
  line-height: 1.5;
}
.gcount {
  font-size: 10.5px;
  font-weight: 700;
  background: #f1f2f4;
  color: var(--mut);
  padding: 1px 7px;
  border-radius: 999px;
}
.tbl-wrap {
  background: #fff;
  border: 1px solid var(--line);
  border-radius: 14px;
  overflow-x: auto;
}
.tbl {
  width: 100%;
  min-width: 860px;
  border-collapse: collapse;
  font-size: 12.5px;
}
.tbl tbody tr.group-start:not(:first-child) td {
  border-top: 1px solid #d4d8de;
}
.c-cat {
  width: 120px;
  max-width: 160px;
  color: var(--mut);
  font-weight: 600;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.tbl th {
  padding: 7px 10px;
  text-align: left;
  font-size: 11px;
  font-weight: 700;
  color: var(--mut);
  background: #f8f9fb;
  border-bottom: 1px solid var(--line);
  white-space: nowrap;
}
.tbl td {
  padding: 5px 10px;
  border-bottom: 1px solid #f1f2f4;
  vertical-align: middle;
  line-height: 1.35;
}
.tbl tbody tr:nth-child(even) {
  background: #eef2f9;
}
.tbl tr:last-child td {
  border-bottom: none;
}
.tbl tbody tr:hover {
  background: #e3e9f6;
}
.nowrap {
  white-space: nowrap;
}
.mrow.hidden td {
  opacity: 0.55;
}
.mrow.lifted td {
  opacity: 0.4;
}
.mrow.ins-before td {
  box-shadow: inset 0 3px 0 0 var(--primary);
}
.mrow.ins-after td {
  box-shadow: inset 0 -3px 0 0 var(--primary);
}
.c-drag {
  width: 1%;
  padding-left: 6px;
  padding-right: 0;
}
.handle {
  width: 28px;
  height: 30px;
  border: none;
  border-radius: 7px;
  background: transparent;
  color: #c3c8d0;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: grab;
  touch-action: none;
  padding: 0;
}
.handle:hover {
  background: #e9ecf1;
  color: #8a919c;
}
.c-title {
  min-width: 140px;
  font-weight: 700;
}
.c-src {
  color: var(--mut);
  max-width: 200px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.c-date {
  width: 84px;
  color: var(--mut);
}
.c-deck {
  width: 130px;
}
.deck-sel {
  width: 100%;
  max-width: 150px;
  padding: 4px 6px;
  border: 1px solid #e3e6ea;
  border-radius: 7px;
  background: #fff;
  font-size: 11.5px;
  color: var(--mut);
}
.c-rev {
  width: 84px;
  white-space: nowrap;
}
.c-state {
  width: 72px;
  white-space: nowrap;
}
.badge {
  display: inline-block;
  font-size: 10.5px;
  font-weight: 700;
  padding: 3px 9px;
  border-radius: 999px;
  white-space: nowrap;
}
.badge.on {
  background: #e6f5ec;
  color: #2f7a4f;
}
.badge.off {
  background: #f1f2f4;
  color: var(--mut);
}
.badge.new {
  background: #eef0f5;
  color: #5b6b8c;
}
.badge.due {
  background: #fde8e4;
  color: #c0444f;
}
.badge.ok {
  background: #f1f2f4;
  color: var(--mut);
}
.c-actions {
  width: 1%;
  white-space: nowrap;
}
.acts {
  display: flex;
  align-items: center;
  gap: 5px;
}
.btn {
  padding: 5px 10px;
  border: 1px solid #e3e6ea;
  border-radius: 7px;
  background: #fff;
  font-size: 11.5px;
  font-weight: 600;
  color: var(--mut);
  cursor: pointer;
  white-space: nowrap;
}
.btn:hover {
  background: #f6f7f9;
  color: var(--ink);
}
.btn.primary {
  background: #1c2024;
  border-color: #1c2024;
  color: #fff;
}
.btn.danger {
  color: #c0444f;
  border-color: #f0c3c8;
}
.btn.danger:hover {
  background: #fdf3f4;
}

/* ---- モーダル ---- */
.overlay {
  position: fixed;
  inset: 0;
  z-index: 190;
  background: rgba(20, 22, 26, 0.45);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 16px;
}
.modal {
  width: min(100%, 420px);
  background: #fff;
  border-radius: 18px;
  padding: 20px 20px 16px;
  display: flex;
  flex-direction: column;
  gap: 12px;
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
}
.m-title {
  font-size: 15px;
  font-weight: 700;
  display: flex;
  align-items: baseline;
  gap: 10px;
}
.m-sub {
  font-size: 12px;
  font-weight: 600;
  color: var(--mut);
}
.fld {
  display: flex;
  flex-direction: column;
  gap: 5px;
}
.fld > span {
  font-size: 11.5px;
  color: var(--mut);
  font-weight: 500;
}
.fld input {
  padding: 9px 11px;
  border: 1px solid #e3e6ea;
  border-radius: 9px;
  font-size: 14px;
  outline: none;
}
.fld input:focus {
  border-color: #b9c2f2;
}
.swatches {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.sw {
  width: 30px;
  height: 30px;
  border-radius: 50%;
  border: 3px solid transparent;
  cursor: pointer;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
}
.sw.on {
  border-color: #1c2024;
}
.m-actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 4px;
}
.q-opt {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 3px;
  padding: 12px 14px;
  border: 1px solid #e3e6ea;
  border-radius: 12px;
  background: #fff;
  text-align: left;
  cursor: pointer;
}
.q-opt:hover {
  background: #f6f7f9;
}
.q-opt:disabled {
  opacity: 0.45;
  cursor: default;
}
.q-opt-t {
  font-size: 14px;
  font-weight: 700;
}
.q-opt-d {
  font-size: 11.5px;
  color: var(--mut);
}
@media (max-width: 600px) {
  .shelf {
    --bh: 164px;
    gap: 0 12px;
    padding: 10px 10px 0;
  }
  .book {
    width: 112px;
  }
  .b-title {
    font-size: 12px;
  }
}
</style>
