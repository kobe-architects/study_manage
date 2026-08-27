<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import Heatmap from '@/components/Heatmap.vue'
import { daysBetween, iso, parseDate, TYPE_BADGE } from '@/lib/design'
import { useStudyStore } from '@/stores/study'
import { useUiStore } from '@/stores/ui'
import type { RecordListItem } from '@/types'

const study = useStudyStore()
const ui = useUiStore()
const router = useRouter()

const today = new Date()
today.setHours(0, 0, 0, 0)

const WD = ['日', '月', '火', '水', '木', '金', '土']
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

/** 直近7日を日付降順で並べ、記録を日付ごとにグルーピング（記録0件の日も表示） */
const dayGroups = computed(() => {
  const byDate = new Map<string, RecordListItem[]>()
  for (const r of records.value) {
    if (!byDate.has(r.date)) byDate.set(r.date, [])
    byDate.get(r.date)!.push(r)
  }
  const groups: { date: string; label: string; wd: string; isToday: boolean; rows: RecordListItem[] }[] = []
  for (let i = 0; i < DAYS; i++) {
    const d = new Date(today)
    d.setDate(d.getDate() - i)
    const key = iso(d)
    groups.push({
      date: key,
      label: `${d.getMonth() + 1}/${d.getDate()}`,
      wd: WD[d.getDay()],
      isToday: i === 0,
      rows: byDate.get(key) ?? [],
    })
  }
  return groups
})

const weekTotal = computed(() => records.value.length)
const activeDays = computed(() => dayGroups.value.filter((g) => g.rows.length).length)

// 課題サマリ（未記録のみ・期限昇順で3件）
const pendingAssignments = computed(() =>
  study.assignments
    .filter((a) => a.achieved === null)
    .slice(0, 3)
    .map((a) => ({
      ...a,
      daysLeft: daysBetween(today, parseDate(a.dueOn)),
    })),
)

function recordColorHex(c: string | null): string {
  return c === 'red' ? '#d92d20' : c === 'blue' ? '#2563eb' : c === 'green' ? '#2e9d62' : '#1c2024'
}
</script>

<template>
  <div>
    <div style="font-size: 17px; font-weight: 700; margin-bottom: 14px">直近1週間の学習記録</div>

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
            <div class="dm" style="font-size: 26px; font-weight: 700">{{ activeDays }}<span style="font-size: 12px; color: var(--mut); margin-left: 2px">/ 7日</span></div>
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
                <div style="font-size: 12.5px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis">{{ a.title }}</div>
                <div style="font-size: 11px; color: var(--faint)">{{ a.done }} / {{ a.target }} 項目</div>
              </div>
              <span :style="{ fontSize: '11px', fontWeight: 700, flexShrink: 0, color: a.daysLeft < 0 ? '#e0533d' : a.daysLeft <= 3 ? '#e0533d' : '#9aa1ab' }">
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

      <!-- RIGHT: 日別の記録一覧 -->
      <div class="card" style="padding: 6px 0">
        <div v-if="loading" style="padding: 40px; text-align: center; color: var(--faint); font-size: 13px">読み込み中…</div>
        <template v-else>
          <div v-for="g in dayGroups" :key="g.date" class="day-block">
            <div class="day-head">
              <span class="day-date" :class="{ today: g.isToday }">{{ g.label }}<span class="day-wd">（{{ g.wd }}）</span></span>
              <span v-if="g.isToday" class="today-tag">今日</span>
              <span style="flex: 1"></span>
              <span style="font-size: 11.5px; color: var(--faint)">{{ g.rows.length ? `${g.rows.length}件` : '記録なし' }}</span>
            </div>
            <div v-if="g.rows.length" style="display: flex; flex-direction: column">
              <div v-for="r in g.rows" :key="r.id" class="rec-row">
                <span class="rec-dot" :style="{ background: ui.colorOf(r.colorSoft, r.colorVivid) }"></span>
                <span style="font-size: 11.5px; color: var(--mut); width: 44px; flex-shrink: 0">{{ r.subjectName ?? '—' }}</span>
                <span class="rec-badge" :style="{ background: TYPE_BADGE[r.type]?.bg ?? '#f1f2f4', color: TYPE_BADGE[r.type]?.fg ?? '#6b7280' }">{{ r.type }}</span>
                <span class="rec-title" :style="{ color: recordColorHex(r.color) }">
                  <span v-if="r.seqNo" style="color: #aeb4bd">{{ r.seqNo }}.</span>
                  {{ r.rowTitle ?? r.sub ?? '（無題）' }}
                </span>
                <span class="rec-src">{{ r.bookTitle ?? (r.major ? `${r.major}›${r.mid}` : '') }}</span>
              </div>
            </div>
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
.day-block {
  padding: 8px 18px 10px;
  border-top: 1px solid #f2f3f5;
}
.day-block:first-child {
  border-top: none;
}
.day-head {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 4px;
}
.day-date {
  font-size: 13px;
  font-weight: 700;
}
.day-date.today {
  color: #2e4a8f;
}
.day-wd {
  font-size: 11px;
  color: var(--faint);
  font-weight: 500;
}
.today-tag {
  font-size: 10px;
  font-weight: 700;
  color: #2e4a8f;
  background: #e8eefb;
  padding: 1px 7px;
  border-radius: 99px;
}
.rec-row {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 5px 0;
  min-width: 0;
}
.rec-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  flex-shrink: 0;
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
