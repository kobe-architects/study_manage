<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { TYPE_BADGE } from '@/lib/design'
import { useStudyStore } from '@/stores/study'
import { useUiStore } from '@/stores/ui'
import { STUDY_TYPES, type StudyItemRow, type StudyType } from '@/types'
import ItemEditModal from '@/components/ItemEditModal.vue'

const study = useStudyStore()
const ui = useUiStore()

const view = ref<'table' | 'tree'>('table')

// ---- フィルタ ----
const q = ref('')
const fSubject = ref<'all' | number>('all')
const fType = ref<'all' | StudyType>('all')
const page = ref(1)
const perPage = 12

const subjectOptions = computed(() => {
  const seen = new Map<number, string>()
  study.items.forEach((i) => seen.set(i.subjectId, i.subjectName))
  return [{ val: 'all' as const, label: '全科目' }, ...[...seen.entries()].map(([id, name]) => ({ val: id, label: name }))]
})

interface Row {
  itemId: number
  subjectId: number
  subjectName: string
  color: string
  major: string
  mid: string
  sub: string
  type: StudyType
  done: number
  total: number
  dateLabel: string
}

function fmtMd(s: string) {
  const d = new Date(s + 'T00:00:00')
  return `${d.getMonth() + 1}/${d.getDate()}`
}

const allRows = computed<Row[]>(() => {
  const rows: Row[] = []
  for (const it of study.items) {
    const color = ui.colorOf(it.colorSoft, it.colorVivid)
    for (const type of STUDY_TYPES) {
      const st = it.byType[type]
      // 教材行が無い種別は表示しない（ノイズ削減）
      if (!st || st.total === 0) continue
      rows.push({
        itemId: it.id,
        subjectId: it.subjectId,
        subjectName: it.subjectName,
        color,
        major: it.major,
        mid: it.mid,
        sub: it.sub,
        type,
        done: st.done,
        total: st.total,
        dateLabel: st.lastDate ? fmtMd(st.lastDate) : '—',
      })
    }
  }
  return rows
})

const filtered = computed(() => {
  const term = q.value.trim()
  return allRows.value.filter(
    (r) =>
      (fSubject.value === 'all' || r.subjectId === fSubject.value) &&
      (fType.value === 'all' || r.type === fType.value) &&
      (!term || (r.sub + r.mid + r.major + r.subjectName).includes(term)),
  )
})

const totalCount = computed(() => filtered.value.length)
const totalPages = computed(() => Math.max(1, Math.ceil(totalCount.value / perPage)))
const curPage = computed(() => Math.min(page.value, totalPages.value))
const tableRows = computed(() => {
  const start = (curPage.value - 1) * perPage
  return filtered.value.slice(start, start + perPage).map((r, i) => ({ ...r, no: start + i + 1, badge: TYPE_BADGE[r.type] }))
})
const rangeText = computed(() => {
  if (!totalCount.value) return '0件'
  const start = (curPage.value - 1) * perPage
  return `${start + 1}–${Math.min(start + perPage, totalCount.value)} / 全${totalCount.value}件`
})

function resetPage() {
  page.value = 1
}

// ---- 追加・編集 ----
const editTarget = ref<{ id: number; name: string; meta: string } | null>(null)
const addMode = ref(false)

function openEdit(r: Row) {
  editTarget.value = { id: r.itemId, name: r.sub, meta: `${r.subjectName} › ${r.major} › ${r.mid}` }
  addMode.value = false
}
function openAdd() {
  editTarget.value = null
  addMode.value = true
}
async function onEditSave(name: string) {
  if (editTarget.value) {
    await study.updateItem(editTarget.value.id, name)
    ui.notify('保存しました')
  }
  editTarget.value = null
}
async function onAddSave(midId: number, name: string) {
  await study.createItem(midId, name)
  ui.notify('項目を追加しました')
  addMode.value = false
}
async function del(r: Row) {
  await study.deleteItem(r.itemId)
  ui.notify('項目を削除しました')
}

// ---- 進捗対象ツリー ----
const expand = reactive<Record<string, boolean>>({})
function toggleExpand(k: string) {
  expand[k] = !expand[k]
}

interface TreeRow {
  key: string
  level: number
  label: string
  leafIds: number[]
  included: number
  total: number
  hasChildren: boolean
  expanded: boolean
}

const treeRows = computed<TreeRow[]>(() => {
  const rows: TreeRow[] = []
  // group by subject -> major -> mid -> items
  const subjMap = new Map<number, { name: string; items: StudyItemRow[] }>()
  for (const it of study.items) {
    if (!subjMap.has(it.subjectId)) subjMap.set(it.subjectId, { name: it.subjectName, items: [] })
    subjMap.get(it.subjectId)!.items.push(it)
  }
  for (const [sid, s] of subjMap) {
    const sKey = `s|${sid}`
    const sInc = s.items.filter((i) => i.included).length
    rows.push({ key: sKey, level: 0, label: s.name, leafIds: s.items.map((i) => i.id), included: sInc, total: s.items.length, hasChildren: true, expanded: !!expand[sKey] })
    if (!expand[sKey]) continue
    const majMap = new Map<string, StudyItemRow[]>()
    s.items.forEach((i) => {
      if (!majMap.has(i.major)) majMap.set(i.major, [])
      majMap.get(i.major)!.push(i)
    })
    for (const [maj, mItems] of majMap) {
      const mKey = `${sKey}|${maj}`
      rows.push({ key: mKey, level: 1, label: maj, leafIds: mItems.map((i) => i.id), included: mItems.filter((i) => i.included).length, total: mItems.length, hasChildren: true, expanded: !!expand[mKey] })
      if (!expand[mKey]) continue
      const midMap = new Map<string, StudyItemRow[]>()
      mItems.forEach((i) => {
        if (!midMap.has(i.mid)) midMap.set(i.mid, [])
        midMap.get(i.mid)!.push(i)
      })
      for (const [mid, dItems] of midMap) {
        const dKey = `${mKey}|${mid}`
        rows.push({ key: dKey, level: 2, label: mid, leafIds: dItems.map((i) => i.id), included: dItems.filter((i) => i.included).length, total: dItems.length, hasChildren: true, expanded: !!expand[dKey] })
        if (!expand[dKey]) continue
        for (const item of dItems) {
          rows.push({ key: `l|${item.id}`, level: 3, label: item.sub, leafIds: [item.id], included: item.included ? 1 : 0, total: 1, hasChildren: false, expanded: false })
        }
      }
    }
  }
  return rows
})

function checkState(inc: number, total: number): 'on' | 'mid' | 'off' {
  if (inc >= total) return 'on'
  if (inc === 0) return 'off'
  return 'mid'
}
const checkStyle = {
  on: { bg: '#1c2024', bd: '#1c2024', co: '#fff', mark: '✓' },
  mid: { bg: '#fff', bd: '#1c2024', co: '#1c2024', mark: '−' },
  off: { bg: '#fff', bd: '#cbd1d8', co: 'transparent', mark: '' },
}

async function toggleCheck(r: TreeRow) {
  const anyIncluded = r.included < r.total // 一部でも除外されていれば全選択へ、全選択なら全解除
  // included になっていない (some excluded) -> turn all on; else all off
  const targetIncluded = r.included < r.total
  await study.setIncluded(r.leafIds, targetIncluded)
  void anyIncluded
}
async function treeAll(on: boolean) {
  await study.setIncluded(study.items.map((i) => i.id), on)
}

function indent(level: number) {
  return `${level * 20 + 14}px`
}
</script>

<template>
  <div>
    <!-- view switch -->
    <div class="seg" style="margin-bottom: 16px">
      <button class="seg-btn" :class="{ on: view === 'table' }" @click="view = 'table'">一覧</button>
      <button class="seg-btn" :class="{ on: view === 'tree' }" @click="view = 'tree'">進捗対象の設定</button>
    </div>

    <!-- TABLE -->
    <template v-if="view === 'table'">
      <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-bottom: 14px">
        <div style="position: relative; flex: 1; min-width: 200px">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#9aa1ab" stroke-width="2" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%)"><circle cx="11" cy="11" r="7" /><path d="M21 21l-4-4" /></svg>
          <input v-model="q" placeholder="小分類・中分類・大分類で検索…" class="search" @input="resetPage" />
        </div>
        <select v-model="fSubject" class="filter" @change="resetPage">
          <option v-for="o in subjectOptions" :key="o.val" :value="o.val">{{ o.label }}</option>
        </select>
        <select v-model="fType" class="filter" @change="resetPage">
          <option value="all">全種別</option>
          <option value="講義">講義</option>
          <option value="問題集">問題集</option>
          <option value="教科書">教科書</option>
        </select>
      </div>

      <div style="display: flex; gap: 9px; flex-wrap: wrap; margin-bottom: 16px">
        <button class="btn-dark" @click="openAdd">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 5v14M5 12h14" /></svg>新規項目
        </button>
        <button class="btn-out" @click="$router.push({ name: 'resource' })">Excel入出力（個別学習一覧へ）</button>
      </div>

      <div class="card" style="overflow: hidden">
        <div style="overflow-x: auto">
          <table class="tbl">
            <thead>
              <tr>
                <th style="width: 52px">No</th><th>科目</th><th>大分類</th><th>中分類</th><th>小分類</th>
                <th style="width: 78px">種別</th><th style="width: 92px">最終学習日</th>
                <th style="width: 96px; text-align: center">達成（行）</th><th style="width: 88px; text-align: right">操作</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in tableRows" :key="r.itemId + r.type">
                <td style="color: #bcc2cb">{{ r.no }}</td>
                <td><span style="display: inline-flex; align-items: center; gap: 6px"><span :style="{ width: '8px', height: '8px', borderRadius: '50%', background: r.color }"></span>{{ r.subjectName }}</span></td>
                <td style="color: #4b5563">{{ r.major }}</td>
                <td style="color: #4b5563">{{ r.mid }}</td>
                <td style="font-weight: 500">{{ r.sub }}</td>
                <td><span :style="{ fontSize: '11px', fontWeight: 600, padding: '2px 8px', borderRadius: '99px', background: r.badge.bg, color: r.badge.fg }">{{ r.type }}</span></td>
                <td :style="{ color: r.dateLabel === '—' ? '#cdd2d9' : '#4b5563', whiteSpace: 'nowrap' }">{{ r.dateLabel }}</td>
                <td :style="{ textAlign: 'center', color: r.done > 0 ? '#1c2024' : '#cdd2d9', fontWeight: r.done > 0 ? 700 : 400 }">{{ r.done }}/{{ r.total }}</td>
                <td style="text-align: right; white-space: nowrap">
                  <button class="icon-btn" title="編集" @click="openEdit(r)"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9" /><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z" /></svg></button>
                  <button class="icon-btn danger" title="削除" @click="del(r)"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6" /></svg></button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="!totalCount" style="padding: 40px; text-align: center; color: var(--faint); font-size: 13px">該当する項目がありません</div>
        <div class="pager">
          <span>{{ rangeText }}</span>
          <div style="display: flex; align-items: center; gap: 6px">
            <button class="pg-btn" @click="page = Math.max(1, curPage - 1)">前へ</button>
            <span style="padding: 0 6px">{{ curPage }} / {{ totalPages }}</span>
            <button class="pg-btn" @click="page = Math.min(totalPages, curPage + 1)">次へ</button>
          </div>
        </div>
      </div>
    </template>

    <!-- TREE -->
    <template v-else>
      <div class="card" style="overflow: hidden">
        <div class="tree-head">
          <div style="font-size: 12.5px; color: var(--mut); line-height: 1.5">
            チェックを外した項目はトップページの学習進捗の集計から除外されます
          </div>
          <div style="display: flex; gap: 8px; flex-shrink: 0">
            <button class="mini-btn" style="color: #3b50cc" @click="treeAll(true)">全選択</button>
            <button class="mini-btn" style="color: #9aa1ab" @click="treeAll(false)">全解除</button>
          </div>
        </div>
        <div style="max-height: 560px; overflow-y: auto; padding: 4px 0">
          <div v-for="t in treeRows" :key="t.key" class="tree-row" :style="{ paddingLeft: indent(t.level) }">
            <button class="chev" :style="{ opacity: t.hasChildren ? 1 : 0 }" @click="t.hasChildren && toggleExpand(t.key)">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" :style="{ transform: t.expanded ? 'rotate(90deg)' : 'none' }"><path d="M9 6l6 6-6 6" /></svg>
            </button>
            <button
              class="chk"
              :style="{
                borderColor: checkStyle[checkState(t.included, t.total)].bd,
                background: checkStyle[checkState(t.included, t.total)].bg,
                color: checkStyle[checkState(t.included, t.total)].co,
              }"
              @click="toggleCheck(t)"
            >{{ checkStyle[checkState(t.included, t.total)].mark }}</button>
            <span
              class="tree-label"
              :style="{
                fontSize: t.level === 0 ? '14px' : t.level === 1 ? '13px' : '12.5px',
                fontWeight: t.level <= 1 ? 700 : t.level === 2 ? 600 : 400,
                color: t.level === 3 ? '#4b5563' : '#1c2024',
              }"
            >{{ t.label }}</span>
            <span style="font-size: 11px; color: var(--faint); flex-shrink: 0">{{ t.included }}/{{ t.total }}</span>
          </div>
        </div>
      </div>
    </template>

    <ItemEditModal
      v-if="editTarget || addMode"
      :add-mode="addMode"
      :name="editTarget?.name ?? ''"
      :meta="editTarget?.meta ?? ''"
      :items="study.items"
      @save="onEditSave"
      @add="onAddSave"
      @close="editTarget = null; addMode = false"
    />
  </div>
</template>

<style scoped>
.seg {
  display: flex;
  background: #fff;
  border: 1px solid #e6e8eb;
  border-radius: 11px;
  padding: 3px;
  width: fit-content;
}
.seg-btn {
  padding: 8px 18px;
  border: none;
  border-radius: 8px;
  cursor: pointer;
  font-size: 13px;
  font-weight: 600;
  background: transparent;
  color: var(--mut);
}
.seg-btn.on {
  background: #1c2024;
  color: #fff;
}
.search {
  width: 100%;
  padding: 9px 12px 9px 36px;
  border: 1px solid #e3e6ea;
  border-radius: 10px;
  font-size: 13px;
  background: #fff;
  outline: none;
}
.filter {
  padding: 9px 12px;
  border: 1px solid #e3e6ea;
  border-radius: 10px;
  font-size: 13px;
  background: #fff;
  cursor: pointer;
  outline: none;
}
.btn-dark {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 9px 15px;
  border: none;
  border-radius: 10px;
  background: #1c2024;
  color: #fff;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
}
.btn-out {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 9px 15px;
  border: 1px solid #e3e6ea;
  border-radius: 10px;
  background: #fff;
  color: #1c2024;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
}
.tbl {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
  min-width: 820px;
}
.tbl thead tr {
  background: #f8f9fb;
  color: var(--faint);
  font-size: 11.5px;
  text-align: left;
}
.tbl th {
  padding: 11px 10px;
  font-weight: 600;
}
.tbl th:first-child {
  padding-left: 14px;
}
.tbl td {
  padding: 10px;
  border-top: 1px solid #f0f1f3;
}
.tbl td:first-child {
  padding-left: 14px;
}
.icon-btn {
  border: none;
  background: transparent;
  cursor: pointer;
  color: #9aa1ab;
  padding: 5px;
}
.icon-btn.danger {
  color: #d99;
}
.pager {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 11px 16px;
  border-top: 1px solid #f0f1f3;
  font-size: 12.5px;
  color: var(--mut);
  flex-wrap: wrap;
  gap: 8px;
}
.pg-btn {
  padding: 6px 11px;
  border: 1px solid #e3e6ea;
  border-radius: 8px;
  background: #fff;
  cursor: pointer;
  font-size: 12.5px;
  color: #4b5563;
}
.tree-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 13px 16px;
  border-bottom: 1px solid #f0f1f3;
  flex-wrap: wrap;
}
.mini-btn {
  border: 1px solid #e3e6ea;
  background: #fff;
  border-radius: 8px;
  padding: 6px 12px;
  font-size: 12px;
  cursor: pointer;
  font-weight: 600;
}
.tree-row {
  display: flex;
  align-items: center;
  gap: 9px;
  padding: 6px 14px;
  border-top: 1px solid #f6f7f8;
}
.chev {
  width: 18px;
  height: 18px;
  border: none;
  background: none;
  cursor: pointer;
  color: #9aa1ab;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.chk {
  width: 19px;
  height: 19px;
  border: 1.6px solid;
  border-radius: 5px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  font-size: 12px;
  font-weight: 700;
  line-height: 1;
}
.tree-label {
  flex: 1;
  min-width: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
</style>
