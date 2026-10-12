<script setup lang="ts">
/**
 * 間違いノートの一覧（生徒）。科目 → 分野ごとに問題のブロックを並べ、ブロックのドラッグで並び替え、
 * 編集・非表示・削除ができる。「ノートを開く」で全画面のバインダー表示、「PDF 出力」で両面印刷用の PDF を作る
 * （目次つき・奇数ページは左、偶数ページは右にパンチ穴の余白）。
 */
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import AuthImage from '@/components/AuthImage.vue'
import HelpTip from '@/components/HelpTip.vue'
import MistakeBinder from '@/components/MistakeBinder.vue'
import { mistakeApi } from '@/api/mistake'
import { appConfirm } from '@/lib/dialog'
import { showPdf, useInAppViewer } from '@/lib/docViewer'
import { fmtDate, groupNotes, type MistakeGroup } from '@/lib/mistake'
import { canvasToJpeg, renderIndexPages, renderNotePage, type IndexEntry } from '@/lib/mistakeRender'
import { haptic } from '@/lib/native'
import { jpegsToPdf } from '@/lib/pdfWriter'
import { useUiStore } from '@/stores/ui'
import type { MistakeNoteSummary } from '@/types'

const router = useRouter()
const ui = useUiStore()

const notes = ref<MistakeNoteSummary[]>([])
const loading = ref(true)

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
const shownCount = computed(() => visible.value.filter((n) => !n.hidden).length)

// ---- ブロックの操作 ----
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
  if (!(await appConfirm(`「${n.title || '（無題）'}」を削除しますか？`, { danger: true, okText: '削除' }))) return
  try {
    await mistakeApi.remove(n.id)
    notes.value = notes.value.filter((x) => x.id !== n.id)
    ui.notify('削除しました')
  } catch {
    ui.notify('削除に失敗しました')
  }
}

/** 同じ科目・分野の中で前後へ */
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
  // 全体の並びの中で、このグループの位置に新しい順で入れ直す
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

// ブロックのドラッグ（取っ手を持って同じ科目・分野の中で動かす）
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
  const el = document.elementsFromPoint(e.clientX, e.clientY).find((x) => (x as HTMLElement).classList?.contains('mblock')) as HTMLElement | undefined
  if (!el || el.dataset.group !== drag.groupKey) {
    drag.over = null
    return
  }
  const r = el.getBoundingClientRect()
  drag.over = Number(el.dataset.id)
  drag.after = e.clientX > r.left + r.width / 2
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

// ---- PDF 出力（目次 + 各ページ。両面印刷でとじ側にパンチの余白） ----
const pdfProgress = ref<{ done: number; total: number } | null>(null)
async function exportPdf() {
  const list = binderNotes.value
  if (!list.length) {
    ui.notify('出力できる問題がありません')
    return
  }
  if (pdfProgress.value) return
  const w = useInAppViewer ? null : window.open('', '_blank')
  pdfProgress.value = { done: 0, total: list.length + 1 }
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
      const entries: IndexEntry[] = list.map((n, i) => ({
        subjectName: n.subjectName ?? '科目なし',
        color: n.colorSoft,
        field: n.field || '（分野なし）',
        title: [n.studyItemName, n.title].filter(Boolean).join('　') || n.source || fmtDate(n.notedOn),
        pageNo: indexCount + i + 1,
      }))
      indexPages = renderIndexPages(entries, 1, title, SCALE)
      if (indexPages.length === indexCount) break
      indexCount = indexPages.length
    }
    const pages = [...indexPages]
    pdfProgress.value = { done: 1, total: list.length + 1 }
    for (let i = 0; i < list.length; i++) {
      const d = await mistakeApi.show(list[i]!.id)
      pages.push(await renderNotePage(d, { pageNo: indexCount + i + 1, scale: SCALE }))
      pdfProgress.value = { done: i + 2, total: list.length + 1 }
    }
    // 両面印刷で最後の裏が白紙になるよう、奇数枚なら白紙を足す
    if (pages.length % 2 === 1) {
      const blank = document.createElement('canvas')
      blank.width = pages[0]!.width
      blank.height = pages[0]!.height
      const x = blank.getContext('2d')!
      x.fillStyle = '#fff'
      x.fillRect(0, 0, blank.width, blank.height)
      pages.push(blank)
    }
    const images = []
    for (const c of pages) images.push({ blob: await canvasToJpeg(c, 0.86), width: c.width, height: c.height })
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
          text="間違えた問題を写真に撮って、間違えた部分や解説を書き込んだ 1 ページにまとめます。科目 → 大分類 → 中分類（学習項目データの分類）ごとに整理され、「ノートを開く」で実際のバインダーのように見開きで閲覧、「PDF 出力」で両面印刷用の PDF（目次つき・とじ側にパンチ穴の余白）を作れます。ブロックは取っ手のドラッグや矢印で並び替え、「非表示」でノート・PDF から外せます。"
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

    <div v-for="g in groups" :key="g.key" class="group">
      <div class="ghead">
        <span class="gname" :style="{ color: g.color ? ui.colorOf(g.color, g.color) : undefined }">{{ g.subjectName }}</span>
        <span v-if="g.field" class="gfield">／ {{ g.field }}</span>
        <span class="gcount">{{ g.notes.length }}問</span>
      </div>
      <div class="blocks">
        <div
          v-for="(n, i) in g.notes"
          :key="n.id"
          class="mblock card"
          :class="{ hidden: n.hidden, lifted: drag.active && drag.id === n.id, 'ins-before': drag.active && drag.over === n.id && !drag.after, 'ins-after': drag.active && drag.over === n.id && drag.after }"
          :data-id="n.id"
          :data-group="g.key"
        >
          <button class="thumb tap" title="編集" @click="edit(n)">
            <AuthImage v-if="n.problemThumbUrl" :src="n.problemThumbUrl" alt="" />
            <div v-else class="nothumb">写真なし</div>
            <span v-if="n.hidden" class="hid">非表示</span>
          </button>
          <div class="body">
            <div class="btitle">{{ [n.studyItemName, n.title].filter(Boolean).join('　') || '（無題）' }}</div>
            <div class="bsub">{{ fmtDate(n.notedOn) }}<template v-if="n.source">・{{ n.source }}</template><template v-if="n.imageCount > 1">・画像 {{ n.imageCount }}</template></div>
          </div>
          <div class="bact">
            <button class="handle" title="ドラッグで並び替え" aria-label="並び替え" @pointerdown="onHandleDown($event, n, g)" @pointermove="onHandleMove" @pointerup="onHandleUp" @pointercancel="onHandleUp">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><circle cx="9" cy="6" r="1.7" /><circle cx="15" cy="6" r="1.7" /><circle cx="9" cy="12" r="1.7" /><circle cx="15" cy="12" r="1.7" /><circle cx="9" cy="18" r="1.7" /><circle cx="15" cy="18" r="1.7" /></svg>
            </button>
            <button class="mini" :disabled="i === 0" title="前へ" @click="move(n, -1)">↑</button>
            <button class="mini" :disabled="i === g.notes.length - 1" title="後ろへ" @click="move(n, 1)">↓</button>
            <button class="mini" @click="edit(n)">編集</button>
            <button class="mini" :disabled="n.hidden" @click="openBinder(n.id)">開く</button>
            <button class="mini" @click="toggleHidden(n)">{{ n.hidden ? '表示' : '非表示' }}</button>
            <button class="mini danger" @click="remove(n)">削除</button>
          </div>
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
.group {
  margin-bottom: 18px;
}
.ghead {
  display: flex;
  align-items: baseline;
  gap: 6px;
  margin: 0 2px 8px;
}
.gname {
  font-size: 14px;
  font-weight: 700;
}
.gfield {
  font-size: 13px;
  font-weight: 600;
  color: var(--mut);
}
.gcount {
  font-size: 11.5px;
  color: var(--faint);
  margin-left: 4px;
}
.blocks {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
  gap: 12px;
}
.mblock {
  position: relative;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  transition: box-shadow 0.15s ease, opacity 0.15s ease;
}
.mblock.hidden {
  opacity: 0.55;
}
.mblock.lifted {
  opacity: 0.4;
}
.mblock.ins-before {
  box-shadow: -4px 0 0 0 var(--primary);
}
.mblock.ins-after {
  box-shadow: 4px 0 0 0 var(--primary);
}
.thumb {
  position: relative;
  display: block;
  width: 100%;
  aspect-ratio: 4 / 3;
  border: none;
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
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--faint);
  font-size: 12px;
}
.hid {
  position: absolute;
  left: 8px;
  top: 8px;
  font-size: 10.5px;
  font-weight: 700;
  color: #fff;
  background: rgba(28, 32, 36, 0.75);
  border-radius: 5px;
  padding: 2px 7px;
}
.body {
  padding: 10px 12px 6px;
}
.btitle {
  font-size: 13.5px;
  font-weight: 700;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.bsub {
  font-size: 11px;
  color: var(--faint);
  margin-top: 2px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.bact {
  display: flex;
  align-items: center;
  gap: 4px;
  flex-wrap: wrap;
  padding: 4px 8px 10px;
}
.handle {
  width: 26px;
  height: 26px;
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
  background: #f1f2f4;
  color: #8a919c;
}
.mini {
  padding: 4px 9px;
  border: 1px solid #e3e6ea;
  border-radius: 99px;
  background: #fff;
  font-size: 11px;
  font-weight: 600;
  color: var(--mut);
  cursor: pointer;
}
.mini:disabled {
  opacity: 0.35;
  cursor: default;
}
.mini.danger {
  color: #c0444f;
  border-color: #f0c3c8;
}
</style>
