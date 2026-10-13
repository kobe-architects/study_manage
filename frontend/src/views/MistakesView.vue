<script setup lang="ts">
/**
 * 間違いノートの一覧（生徒）。小テスト一覧と同じく、科目のバッジ → 分野（大分類 ／ 中分類）ごとのテーブルで表示する。
 * 行の取っ手のドラッグ（同じ分野の中）や矢印で並び替え、編集・非表示・削除ができる。
 * 「ノートを開く」で全画面のバインダー表示、「PDF 出力」で両面印刷用の PDF を作る
 * （目次つき・1 ページに複数の問題を収め、奇数ページは左、偶数ページは右にパンチ穴の余白）。
 */
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import ActionMenu, { type ActionMenuItem } from '@/components/ActionMenu.vue'
import AuthImage from '@/components/AuthImage.vue'
import HelpTip from '@/components/HelpTip.vue'
import MistakeBinder from '@/components/MistakeBinder.vue'
import { mistakeApi } from '@/api/mistake'
import { appConfirm } from '@/lib/dialog'
import { showPdf, useInAppViewer } from '@/lib/docViewer'
import { fmtDate, groupNotes, pageIndexOf, paginate, type MistakeGroup } from '@/lib/mistake'
import { canvasToJpeg, renderBinderPage, renderIndexPages, type IndexEntry } from '@/lib/mistakeRender'
import { haptic, viewportWidth } from '@/lib/native'
import { jpegsToPdf } from '@/lib/pdfWriter'
import { useUiStore } from '@/stores/ui'
import type { MistakeNoteDetail, MistakeNoteSummary } from '@/types'

const router = useRouter()
const ui = useUiStore()

const notes = ref<MistakeNoteSummary[]>([])
const loading = ref(true)
const compact = computed(() => viewportWidth.value <= 900)

async function load() {
  loading.value = true
  try {
    notes.value = await mistakeApi.list()
  } catch {
    ui.notify('間違いノートの取得に失敗しました')
  } finally {
    loading.value = false
  }
}
onMounted(load)

// ---- 絞り込み（科目 → 大分類 → 中分類） ----
const filter = reactive({ subject: 'all' as string, majorId: 0, midId: 0 })
const subjectTabs = computed(() => {
  const out: { key: string; name: string; color: string | null; count: number }[] = []
  for (const n of notes.value) {
    const key = String(n.subjectId ?? 0)
    const t = out.find((x) => x.key === key)
    if (t) t.count++
    else out.push({ key, name: n.subjectName ?? '科目なし', color: ui.colorOf(n.colorSoft ?? '#5b6b8c', n.colorVivid ?? '#5b6b8c'), count: 1 })
  }
  return out
})
const majorChips = computed(() => {
  if (filter.subject === 'all') return []
  const out: { id: number; name: string; count: number }[] = []
  for (const n of notes.value) {
    if (String(n.subjectId ?? 0) !== filter.subject) continue
    const id = n.majorId ?? 0
    const t = out.find((x) => x.id === id)
    if (t) t.count++
    else out.push({ id, name: n.majorName ?? '（大分類なし）', count: 1 })
  }
  return out
})
const midChips = computed(() => {
  if (!filter.majorId) return []
  const out: { id: number; name: string; count: number }[] = []
  for (const n of notes.value) {
    if (String(n.subjectId ?? 0) !== filter.subject || (n.majorId ?? 0) !== filter.majorId) continue
    const id = n.midId ?? 0
    const t = out.find((x) => x.id === id)
    if (t) t.count++
    else out.push({ id, name: n.midName ?? '（中分類なし）', count: 1 })
  }
  return out
})
function pickSubject(key: string) {
  filter.subject = key
  filter.majorId = 0
  filter.midId = 0
}
function pickMajor(id: number) {
  filter.majorId = id
  filter.midId = 0
}
const visible = computed(() =>
  notes.value.filter(
    (n) =>
      (filter.subject === 'all' || String(n.subjectId ?? 0) === filter.subject) &&
      (filter.majorId === 0 || (n.majorId ?? 0) === filter.majorId) &&
      (filter.midId === 0 || (n.midId ?? 0) === filter.midId),
  ),
)
const groups = computed(() => groupNotes(visible.value))
/** 科目 → 分野ごとのテーブル（小テスト一覧と同じ構成） */
const subjectBlocks = computed(() => {
  const out: { key: string; name: string; color: string; groups: MistakeGroup[] }[] = []
  for (const g of groups.value) {
    const key = String(g.subjectId ?? 0)
    let s = out.find((x) => x.key === key)
    if (!s) {
      const n = g.notes[0]!
      s = { key, name: g.subjectName, color: ui.colorOf(n.colorSoft ?? '#5b6b8c', n.colorVivid ?? '#5b6b8c'), groups: [] }
      out.push(s)
    }
    s.groups.push(g)
  }
  return out
})
const shownCount = computed(() => visible.value.filter((n) => !n.hidden).length)

/** バッジの背景色に対して読める文字色 */
function textOn(bg: string): string {
  const m = /^#?([0-9a-f]{6})$/i.exec(bg.trim())
  if (!m) return '#fff'
  const n = parseInt(m[1]!, 16)
  const lum = (0.2126 * ((n >> 16) & 255) + 0.7152 * ((n >> 8) & 255) + 0.0722 * (n & 255)) / 255
  return lum > 0.6 ? '#1c2024' : '#fff'
}

// ---- 行の操作 ----
function edit(n: MistakeNoteSummary) {
  router.push({ name: 'mistake-edit', params: { id: n.id } })
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

// ---- 全画面のノート ----
const binderOpen = ref(false)
const binderStart = ref<number | null>(null)
const binderNotes = computed(() => visible.value.filter((n) => !n.hidden))
function openBinder(startId: number | null = null) {
  if (!binderNotes.value.length) {
    ui.notify('表示できる問題がありません')
    return
  }
  binderStart.value = startId
  binderOpen.value = true
}

// ---- PDF 出力（目次 + ページ割りした各ページ。両面印刷でとじ側にパンチの余白） ----
const pdfProgress = ref<{ done: number; total: number } | null>(null)
async function exportPdf() {
  const list = binderNotes.value
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
    const subjectName = filter.subject === 'all' ? '' : (subjectTabs.value.find((t) => t.key === filter.subject)?.name ?? '')
    const majorName = filter.majorId ? (majorChips.value.find((m) => m.id === filter.majorId)?.name ?? '') : ''
    const midName = filter.midId ? (midChips.value.find((m) => m.id === filter.midId)?.name ?? '') : ''
    const title = ['間違いノート', subjectName, majorName, midName].filter(Boolean).join('　')
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
    const pdf = await jpegsToPdf(images)
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
    <div class="top">
      <div class="ttl">
        <span>間違いノート</span>
        <HelpTip
          text="間違えた問題を写真に撮って、間違えた部分や解説を書き込んでまとめます。科目 → 大分類 → 中分類（学習項目データの分類）ごとに整理され、「ノートを開く」で実際のバインダーのように見開きで閲覧、「PDF 出力」で両面印刷用の PDF（目次つき・とじ側にパンチ穴の余白）を作れます。ページには収まる限り複数の問題を続けて載せ、問題の間に罫線が入ります。行は取っ手のドラッグや「上へ」「下へ」で並び替え、「非表示」でノート・PDF から外せます。"
        />
      </div>
      <div class="actions">
        <button class="btn-ghost" :disabled="!shownCount" @click="openBinder()">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 5h7a2 2 0 0 1 2 2v13a2 2 0 0 0-2-2H3z" /><path d="M21 5h-7a2 2 0 0 0-2 2v13a2 2 0 0 1 2-2h7z" /></svg>ノートを開く
        </button>
        <button class="btn-ghost" :disabled="!shownCount || !!pdfProgress" @click="exportPdf">
          <template v-if="pdfProgress">PDF 作成中 {{ pdfProgress.done }}/{{ pdfProgress.total }}</template>
          <template v-else>PDF 出力</template>
        </button>
        <button class="btn-dark" @click="router.push({ name: 'mistake-new' })">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 5v14M5 12h14" /></svg>問題を追加
        </button>
      </div>
    </div>

    <!-- 科目のタブ・分野 -->
    <div v-if="notes.length" class="filters">
      <div class="tabs">
        <button class="tab" :class="{ on: filter.subject === 'all' }" @click="pickSubject('all')">すべて<span class="cnt">{{ notes.length }}</span></button>
        <button v-for="t in subjectTabs" :key="t.key" class="tab" :class="{ on: filter.subject === t.key }" :style="{ '--c': t.color ?? '#5b6b8c' }" @click="pickSubject(t.key)">
          <span class="dot"></span>{{ t.name }}<span class="cnt">{{ t.count }}</span>
        </button>
      </div>
      <div v-if="majorChips.length" class="chips">
        <span class="chips-label">大分類</span>
        <button class="chip" :class="{ on: filter.majorId === 0 }" @click="pickMajor(0)">すべて</button>
        <button v-for="m in majorChips" :key="m.id" class="chip" :class="{ on: filter.majorId === m.id }" @click="pickMajor(m.id)">{{ m.name }}<span class="cnt">{{ m.count }}</span></button>
      </div>
      <div v-if="midChips.length" class="chips">
        <span class="chips-label">中分類</span>
        <button class="chip" :class="{ on: filter.midId === 0 }" @click="filter.midId = 0">すべて</button>
        <button v-for="m in midChips" :key="m.id" class="chip" :class="{ on: filter.midId === m.id }" @click="filter.midId = m.id">{{ m.name }}<span class="cnt">{{ m.count }}</span></button>
      </div>
    </div>

    <div v-if="loading" class="hint" style="text-align: center">読み込み中…</div>
    <div v-else-if="!notes.length" class="hint" style="text-align: center">
      まだ問題がありません。「問題を追加」から、間違えた問題を撮影して登録してください。
    </div>
    <div v-else-if="!visible.length" class="hint" style="text-align: center">この絞り込みに当てはまる問題はありません。</div>

    <!-- 科目 → 分野ごとのテーブル -->
    <div v-for="s in subjectBlocks" :key="s.key" class="group">
      <div class="subj">
        <span class="subj-badge" :style="{ background: s.color, color: textOn(s.color) }">{{ s.name }}</span>
      </div>
      <div v-for="g in s.groups" :key="g.key" class="book-block">
        <div class="book-head">{{ g.field || '（分野なし）' }}<span class="gcount">{{ g.notes.length }}問</span></div>
        <div class="tbl-wrap">
          <table class="tbl">
            <thead>
              <tr>
                <th class="c-drag"></th>
                <th class="c-thumb">問題</th>
                <th>小分類 ／ タイトル</th>
                <th class="c-src">出典</th>
                <th class="c-date">日付</th>
                <th class="c-img r">画像</th>
                <th class="c-state">状態</th>
                <th class="c-actions"></th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="(n, i) in g.notes"
                :key="n.id"
                class="mrow"
                :class="{ hidden: n.hidden, lifted: drag.active && drag.id === n.id, 'ins-before': drag.active && drag.over === n.id && !drag.after, 'ins-after': drag.active && drag.over === n.id && drag.after }"
                :data-id="n.id"
                :data-group="g.key"
              >
                <td class="c-drag">
                  <button class="handle" title="ドラッグで並び替え（同じ分野の中）" aria-label="並び替え" @pointerdown="onHandleDown($event, n, g)" @pointermove="onHandleMove" @pointerup="onHandleUp" @pointercancel="onHandleUp">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><circle cx="9" cy="6" r="1.7" /><circle cx="15" cy="6" r="1.7" /><circle cx="9" cy="12" r="1.7" /><circle cx="15" cy="12" r="1.7" /><circle cx="9" cy="18" r="1.7" /><circle cx="15" cy="18" r="1.7" /></svg>
                  </button>
                </td>
                <td class="c-thumb">
                  <button class="thumb tap" title="編集" @click="edit(n)">
                    <AuthImage v-if="n.problemThumbUrl" :src="n.problemThumbUrl" alt="" />
                    <span v-else class="nothumb">なし</span>
                  </button>
                </td>
                <td class="c-title">
                  <div class="t1">{{ n.studyItemName || '—' }}</div>
                  <div class="t2">{{ n.title || '（無題）' }}</div>
                </td>
                <td class="c-src">{{ n.source || '–' }}</td>
                <td class="c-date nowrap">{{ fmtDate(n.notedOn) }}</td>
                <td class="c-img r">{{ n.imageCount }}</td>
                <td class="c-state"><span class="badge" :class="n.hidden ? 'off' : 'on'">{{ n.hidden ? '非表示' : '表示' }}</span></td>
                <td class="c-actions">
                  <div class="acts">
                    <button class="btn primary" @click="edit(n)">編集</button>
                    <ActionMenu v-if="compact" :items="secondary(n, i, g)" @select="onAction(n, $event)" />
                    <template v-else>
                      <button v-for="it in secondary(n, i, g)" :key="it.key" class="btn" :class="{ danger: it.danger }" @click="onAction(n, it.key)">{{ it.label }}</button>
                    </template>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <Transition name="ui-modal">
      <MistakeBinder v-if="binderOpen" :notes="binderNotes" :start-id="binderStart" @close="binderOpen = false" />
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
.actions {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
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
.hint {
  background: #f8f9fb;
  border: 1px dashed #d8dce1;
  border-radius: 14px;
  padding: 18px;
  font-size: 12.5px;
  color: var(--faint);
  line-height: 1.7;
}
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

/* ---- 科目 → 分野ごとのテーブル（小テスト一覧と同じ見た目） ---- */
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
.book-block {
  margin-bottom: 8px;
}
.book-block:last-child {
  margin-bottom: 0;
}
.book-head {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 4px 6px 5px;
  font-size: 12.5px;
  font-weight: 700;
  color: var(--ink);
}
.book-head::before {
  content: '';
  width: 3px;
  height: 12px;
  border-radius: 2px;
  background: #c9cfd8;
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
  min-width: 640px;
  border-collapse: collapse;
  font-size: 12.5px;
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
.r {
  text-align: right;
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
.c-thumb {
  width: 1%;
}
.thumb {
  display: block;
  width: 64px;
  height: 48px;
  border: none;
  border-radius: 6px;
  background: #f1f2f4;
  padding: 0;
  cursor: pointer;
  overflow: hidden;
}
.thumb :deep(img) {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}
.nothumb {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: 100%;
  font-size: 11px;
  color: var(--faint);
}
.c-title {
  min-width: 180px;
}
.t1 {
  font-size: 12.5px;
  font-weight: 700;
}
.t2 {
  font-size: 12px;
  color: var(--mut);
}
.c-src {
  color: var(--mut);
  max-width: 220px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.c-date {
  width: 84px;
  color: var(--mut);
}
.c-img {
  width: 48px;
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
</style>
