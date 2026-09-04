<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import Heatmap from '@/components/Heatmap.vue'
import { assignmentTitle, daysBetween, iso, parseDate, TYPE_BADGE } from '@/lib/design'
import { useStudyStore } from '@/stores/study'
import { useUiStore } from '@/stores/ui'
import type { RecordListItem } from '@/types'

const study = useStudyStore()
const ui = useUiStore()
const router = useRouter()

const today = new Date()
today.setHours(0, 0, 0, 0)

const DAYS = 7 // 直近1週間

const records = ref<RecordListItem[]>([])
const loading = ref(true)

onMounted(async () => {
  const from = new Date(today)
  from.setDate(from.getDate() - (DAYS - 1))
  try {
    records.value = await study.fetchRecordList(iso(from), iso(today))
  } catch {
    ui.notify('学習記録の取得に失敗しました')
  } finally {
    loading.value = false
  }
  study.fetchRecordStats().catch(() => {})
  study.fetchAssignments().catch(() => {})
})

/** 直近の学習記録を科目別にグルーピング（最新の記録がある科目を先頭に、科目内は日付降順） */
const subjectGroups = computed(() => {
  const map = new Map<string, { name: string; colorSoft: string; colorVivid: string; rows: RecordListItem[] }>()
  for (const r of records.value) {
    const key = r.subjectName ?? '（科目未設定）'
    if (!map.has(key)) {
      map.set(key, { name: key, colorSoft: r.colorSoft, colorVivid: r.colorVivid, rows: [] })
    }
    map.get(key)!.rows.push(r)
  }
  // records は日付降順で返るため rows の順序は維持し、科目は最新記録日の降順に並べる
  return [...map.values()].sort((a, b) => (a.rows[0].date < b.rows[0].date ? 1 : -1))
})

const weekTotal = computed(() => records.value.length)
const activeDayCount = computed(() => new Set(records.value.map((r) => r.date)).size)

// 課題サマリ（未記録のみ・期限昇順で3件）
const pendingAssignments = computed(() =>
  study.assignments
    .filter((a) => a.achieved === null)
    .slice(0, 3)
    .map((a) => ({
      ...a,
      displayTitle: assignmentTitle(a.title, a.dueOn),
      daysLeft: daysBetween(today, parseDate(a.dueOn)),
    })),
)

function fmtMd(isoDate: string) {
  const d = parseDate(isoDate)
  return `${d.getMonth() + 1}/${d.getDate()}`
}

function recordColorHex(c: string | null): string {
  return c === 'red' ? '#d92d20' : c === 'blue' ? '#2563eb' : c === 'green' ? '#2e9d62' : '#1c2024'
}
</script>

<template>
  <div>
    <div style="font-size: 17px; font-weight: 700; margin-bottom: 14px">直近の学習記録（科目別・過去{{ DAYS }}日）</div>

    <div class="grid">
      <!-- LEFT: 週間サマリ + 課題 + ヒートマップ -->
      <div style="display: flex; flex-direction: column; gap: 14px">
        <div class="card" style="padding: 16px 18px; display: flex; gap: 18px">
          <div style="flex: 1; text-align: center">
            <div style="font-size: 11.5px; color: var(--faint); margin-bottom: 3px">今週の記録</div>
            <div class="dm" style="font-size: 26px; font-weight: 700">{{ weekTotal }}<span style="font-size: 12px; color: var(--mut); margin-left: 2px">件</span></div>
          </div>
          <div style="width: 1px; background: var(--line)"></div>
          <div style="flex: 1; text-align: center">
            <div style="font-size: 11.5px; color: var(--faint); margin-bottom: 3px">学習した日</div>
            <div class="dm" style="font-size: 26px; font-weight: 700">{{ activeDayCount }}<span style="font-size: 12px; color: var(--mut); margin-left: 2px">/ {{ DAYS }}日</span></div>
          </div>
          <div style="width: 1px; background: var(--line)"></div>
          <div style="flex: 1; text-align: center">
            <div style="font-size: 11.5px; color: var(--faint); margin-bottom: 3px">連続学習</div>
            <div class="dm" style="font-size: 26px; font-weight: 700">{{ study.recordStats?.streak ?? 0 }}<span style="font-size: 12px; color: var(--mut); margin-left: 2px">日</span></div>
          </div>
        </div>

        <div class="card" style="padding: 16px 18px">
          <div class="row-between" style="margin-bottom: 10px">
            <span style="font-size: 13px; font-weight: 700">進行中の課題</span>
            <button class="link-btn" @click="router.push({ name: 'tutor-assignments' })">課題設定へ →</button>
          </div>
          <div v-if="pendingAssignments.length" style="display: flex; flex-direction: column; gap: 10px">
            <div v-for="a in pendingAssignments" :key="a.id" style="display: flex; align-items: center; gap: 10px">
              <div style="flex: 1; min-width: 0">
                <div style="font-size: 12.5px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis">{{ a.displayTitle }}</div>
                <div style="font-size: 11px; color: var(--faint)">{{ a.done }} / {{ a.target }} 項目</div>
              </div>
              <span :style="{ fontSize: '11px', fontWeight: 700, flexShrink: 0, color: a.daysLeft <= 3 ? '#e0533d' : '#9aa1ab' }">
                {{ a.daysLeft < 0 ? `${-a.daysLeft}日超過` : a.daysLeft === 0 ? '本日期限' : `あと${a.daysLeft}日` }}
              </span>
            </div>
          </div>
          <div v-else style="font-size: 12px; color: var(--faint)">進行中の課題はありません</div>
        </div>

        <div class="card" style="padding: 16px 18px">
          <div class="row-between" style="margin-bottom: 12px">
            <span style="font-size: 13px; font-weight: 700">学習カレンダー</span>
            <span style="font-size: 10.5px; color: var(--faint)">直近16週間</span>
          </div>
          <Heatmap :counts="study.recordStats?.heatmap ?? {}" :cell="11" />
        </div>
      </div>

      <!-- RIGHT: 科目別の記録一覧 -->
      <div class="card" style="padding: 6px 0">
        <div v-if="loading" style="padding: 40px; text-align: center; color: var(--faint); font-size: 13px">読み込み中…</div>
        <template v-else>
          <div v-for="g in subjectGroups" :key="g.name" class="subj-block">
            <div class="subj-head">
              <span class="subj-dot" :style="{ background: ui.colorOf(g.colorSoft, g.colorVivid) }"></span>
              <span class="subj-name">{{ g.name }}</span>
              <span style="flex: 1"></span>
              <span style="font-size: 11.5px; color: var(--faint)">{{ g.rows.length }}件</span>
            </div>
            <div style="display: flex; flex-direction: column">
              <div v-for="r in g.rows" :key="r.id" class="rec-row">
                <span style="font-size: 11.5px; color: var(--mut); width: 36px; flex-shrink: 0">{{ fmtMd(r.date) }}</span>
                <span class="rec-badge" :style="{ background: TYPE_BADGE[r.type]?.bg ?? '#f1f2f4', color: TYPE_BADGE[r.type]?.fg ?? '#6b7280' }">{{ r.type }}</span>
                <span class="rec-title" :style="{ color: recordColorHex(r.color) }">
                  <span v-if="r.seqNo" style="color: #aeb4bd">{{ r.seqNo }}.</span>
                  {{ r.rowTitle ?? r.sub ?? '（無題）' }}
                </span>
                <span class="rec-src">{{ r.bookTitle ?? (r.major ? `${r.major}›${r.mid}` : '') }}</span>
              </div>
            </div>
          </div>
          <div v-if="!subjectGroups.length" style="padding: 40px; text-align: center; color: var(--faint); font-size: 13px">
            直近{{ DAYS }}日間の学習記録はありません
          </div>
        </template>
      </div>
    </div>
  </div>
</template>

<style scoped>
.grid {
  display: grid;
  grid-template-columns: minmax(280px, 330px) 1fr;
  gap: 16px;
  align-items: start;
}
@media (max-width: 860px) {
  .grid {
    grid-template-columns: 1fr;
  }
}
.row-between {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.link-btn {
  border: none;
  background: none;
  color: #3b50cc;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  padding: 0;
}
.subj-block {
  padding: 10px 18px 12px;
  border-top: 1px solid #f2f3f5;
}
.subj-block:first-child {
  border-top: none;
}
.subj-head {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 5px;
}
.subj-dot {
  width: 9px;
  height: 9px;
  border-radius: 50%;
  flex-shrink: 0;
}
.subj-name {
  font-size: 13.5px;
  font-weight: 700;
}
.rec-row {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 5px 0 5px 17px;
  min-width: 0;
}
.rec-badge {
  flex-shrink: 0;
  font-size: 10px;
  font-weight: 700;
  padding: 1px 6px;
  border-radius: 99px;
  width: 44px;
  text-align: center;
}
.rec-title {
  flex: 1;
  min-width: 0;
  font-size: 12.5px;
  font-weight: 600;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.rec-src {
  flex-shrink: 0;
  max-width: 40%;
  font-size: 10.5px;
  color: #aeb4bd;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
</style>
