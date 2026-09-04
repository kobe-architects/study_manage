<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import SubjectProgressPanels from '@/components/SubjectProgressPanels.vue'
import { parseDate, TYPE_BADGE } from '@/lib/design'
import { openListPrint, type PrintCell, type PrintColumn } from '@/lib/printList'
import { useStudyStore } from '@/stores/study'
import { useUiStore } from '@/stores/ui'
import type { RecordListItem } from '@/types'

const study = useStudyStore()
const ui = useUiStore()

const loading = ref(!study.items.length)

onMounted(async () => {
  try {
    await study.fetchItems()
  } catch {
    ui.notify('学習状況の取得に失敗しました')
  } finally {
    loading.value = false
  }
})

// ---- 進捗学習記録のみ表示（学習した記録を科目別・個別学習データ別に一覧） ----
const recordsMode = ref(false)
const records = ref<RecordListItem[]>([])
const recordsLoading = ref(false)
const recordsLoaded = ref(false)

async function toggleRecordsMode() {
  recordsMode.value = !recordsMode.value
  if (recordsMode.value && !recordsLoaded.value) {
    recordsLoading.value = true
    try {
      records.value = await study.fetchRecordList() // 全期間
      recordsLoaded.value = true
    } catch {
      ui.notify('学習記録の取得に失敗しました')
      recordsMode.value = false
    } finally {
      recordsLoading.value = false
    }
  }
}

/** 科目別 → 個別学習データ（教材）別のグルーピング。教材内は日付降順（APIの返却順を維持） */
const recordGroups = computed(() => {
  const subjMap = new Map<
    string,
    {
      name: string
      colorSoft: string
      colorVivid: string
      count: number
      books: Map<string, { title: string; rows: RecordListItem[] }>
    }
  >()
  for (const r of records.value) {
    const subjKey = r.subjectName ?? '（科目未設定）'
    if (!subjMap.has(subjKey)) {
      subjMap.set(subjKey, { name: subjKey, colorSoft: r.colorSoft, colorVivid: r.colorVivid, count: 0, books: new Map() })
    }
    const s = subjMap.get(subjKey)!
    s.count++
    const bookKey = r.bookTitle ?? '（教材未設定）'
    if (!s.books.has(bookKey)) {
      s.books.set(bookKey, { title: bookKey, rows: [] })
    }
    s.books.get(bookKey)!.rows.push(r)
  }
  return [...subjMap.values()].map((s) => ({ ...s, books: [...s.books.values()] }))
})

function fmtMd(isoDate: string) {
  const d = parseDate(isoDate)
  return `${d.getMonth() + 1}/${d.getDate()}`
}

function recordColorHex(c: string | null): string {
  return c === 'red' ? '#d92d20' : c === 'blue' ? '#2563eb' : c === 'green' ? '#2e9d62' : '#1c2024'
}

// ---- PDF出力（画面出力 → ブラウザの印刷/PDF保存） ----
function printRecords() {
  if (!records.value.length) {
    ui.notify('出力対象の学習記録がありません')
    return
  }
  const cols: PrintColumn[] = [
    { label: '科目', width: '70px', nowrap: true },
    { label: '教材（個別学習データ）', width: '210px' },
    { label: '種別', align: 'center', width: '58px', nowrap: true },
    { label: '番号', align: 'center', width: '54px', nowrap: true },
    { label: 'タイトル' },
    { label: '学習日', align: 'center', width: '68px', nowrap: true },
  ]
  const rows: PrintCell[][] = []
  for (const s of recordGroups.value) {
    for (const b of s.books) {
      for (const r of b.rows) {
        rows.push([s.name, b.title, r.type, r.seqNo ?? '', r.rowTitle ?? r.sub ?? '', fmtMd(r.date)])
      }
    }
  }
  const n = new Date()
  const ok = openListPrint({
    title: '学習記録一覧（科目別・個別学習データ別）',
    subtitle: `全${records.value.length}件・出力日 ${n.getFullYear()}/${n.getMonth() + 1}/${n.getDate()}`,
    columns: cols,
    rows,
  })
  if (!ok) ui.notify('ポップアップがブロックされました。ブラウザ設定で許可してください。')
}
</script>

<template>
  <div>
    <div class="head-row">
      <div style="font-size: 17px; font-weight: 700">科目別学習状況</div>
      <div style="display: flex; gap: 8px">
        <button v-if="recordsMode" class="btn-outline" @click="printRecords">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V3h12v6" /><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2" /><path d="M6 14h12v7H6z" /></svg>
          PDF出力
        </button>
        <button class="btn-outline" :class="{ on: recordsMode }" @click="toggleRecordsMode">
          {{ recordsMode ? '進捗表示に戻る' : '進捗学習記録のみ表示' }}
        </button>
      </div>
    </div>

    <div v-if="loading && !recordsMode" style="padding: 40px; text-align: center; color: var(--faint); font-size: 13px">読み込み中…</div>

    <!-- 通常表示: ペラいち進捗 -->
    <SubjectProgressPanels v-else-if="!recordsMode" />

    <!-- 進捗学習記録のみ表示: 科目別 → 個別学習データ別の学習記録一覧 -->
    <template v-else>
      <div v-if="recordsLoading" style="padding: 40px; text-align: center; color: var(--faint); font-size: 13px">読み込み中…</div>
      <template v-else>
        <div v-for="s in recordGroups" :key="s.name" class="card subj-card">
          <div class="subj-head">
            <span class="subj-dot" :style="{ background: ui.colorOf(s.colorSoft, s.colorVivid) }"></span>
            <span style="font-size: 14.5px; font-weight: 700">{{ s.name }}</span>
            <span style="flex: 1"></span>
            <span style="font-size: 11.5px; color: var(--faint)">{{ s.count }}件</span>
          </div>
          <div v-for="b in s.books" :key="b.title" class="book-block">
            <div class="book-head">
              <span class="book-title">{{ b.title }}</span>
              <span style="font-size: 10.5px; color: var(--faint); flex-shrink: 0">{{ b.rows.length }}件</span>
            </div>
            <div class="rec-list">
              <div v-for="r in b.rows" :key="r.id" class="rec-row">
                <span style="font-size: 11px; color: var(--mut); width: 36px; flex-shrink: 0">{{ fmtMd(r.date) }}</span>
                <span class="rec-badge" :style="{ background: TYPE_BADGE[r.type]?.bg ?? '#f1f2f4', color: TYPE_BADGE[r.type]?.fg ?? '#6b7280' }">{{ r.type }}</span>
                <span class="rec-title" :style="{ color: recordColorHex(r.color) }">
                  <span v-if="r.seqNo" style="color: #aeb4bd">{{ r.seqNo }}.</span>
                  {{ r.rowTitle ?? r.sub ?? '（無題）' }}
                </span>
              </div>
            </div>
          </div>
        </div>
        <div v-if="!recordGroups.length" class="card" style="padding: 40px; text-align: center; color: var(--faint); font-size: 13px">
          学習記録がまだありません
        </div>
      </template>
    </template>
  </div>
</template>

<style scoped>
.head-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  flex-wrap: wrap;
  margin-bottom: 14px;
}
.btn-outline {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 8px 14px;
  border: 1px solid #d7dcfb;
  border-radius: 9px;
  background: #f3f5ff;
  color: #3b50cc;
  font-size: 12.5px;
  font-weight: 600;
  cursor: pointer;
}
.btn-outline.on {
  background: #1c2024;
  border-color: #1c2024;
  color: #fff;
}
.subj-card {
  padding: 13px 16px;
  margin-bottom: 12px;
}
.subj-head {
  display: flex;
  align-items: center;
  gap: 8px;
  padding-bottom: 7px;
  border-bottom: 1px solid #f0f1f3;
}
.subj-dot {
  width: 9px;
  height: 9px;
  border-radius: 50%;
  flex-shrink: 0;
}
.book-block {
  margin-top: 9px;
}
.book-head {
  display: flex;
  align-items: baseline;
  gap: 8px;
  margin-bottom: 3px;
}
.book-title {
  font-size: 12.5px;
  font-weight: 700;
  color: #4b5563;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.rec-list {
  display: flex;
  flex-direction: column;
  gap: 1px;
  padding-left: 8px;
}
.rec-row {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 3px 0;
  min-width: 0;
}
.rec-badge {
  flex-shrink: 0;
  font-size: 9.5px;
  font-weight: 700;
  padding: 0 6px;
  border-radius: 99px;
  width: 42px;
  text-align: center;
}
.rec-title {
  flex: 1;
  min-width: 0;
  font-size: 12px;
  font-weight: 500;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
</style>
