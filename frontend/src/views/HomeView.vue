<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import Heatmap from '@/components/Heatmap.vue'
import MonthCalendar from '@/components/MonthCalendar.vue'
import EventModal from '@/components/EventModal.vue'
import { daysBetween, hexA, parseDate, pct } from '@/lib/design'
import { useStudyStore } from '@/stores/study'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { useVocabularyStore } from '@/stores/vocabulary'
import { STUDY_TYPES, type StudyItemRow, type StudyType } from '@/types'

const study = useStudyStore()
const auth = useAuthStore()
const ui = useUiStore()
const vocab = useVocabularyStore()

const today = new Date()
today.setHours(0, 0, 0, 0)

const tab = ref<'progress' | 'goals'>('progress')
const expanded = reactive<Record<number, boolean>>({})
const vw = ref(window.innerWidth)
window.addEventListener('resize', () => (vw.value = window.innerWidth))
const isMobile = computed(() => vw.value < 860)

const hideEmpty = computed(() => auth.settings?.hideEmpty ?? false)

// ---- 進捗集計（included のみ・教材の行ベース達成率） ----
const incItems = computed(() => study.items.filter((i) => i.included))

interface TypeAgg {
  done: number // 1件以上記録のある行数
  total: number // 紐づく教材行の総数
}
function emptyTypes(): Record<StudyType, TypeAgg> {
  return { 講義: { done: 0, total: 0 }, 問題集: { done: 0, total: 0 }, 教科書: { done: 0, total: 0 } }
}
function addTypes(into: Record<StudyType, TypeAgg>, row: StudyItemRow) {
  for (const t of STUDY_TYPES) {
    into[t].done += row.byType[t]?.done ?? 0
    into[t].total += row.byType[t]?.total ?? 0
  }
}
function sumDone(t: Record<StudyType, TypeAgg>) {
  return STUDY_TYPES.reduce((a, k) => a + t[k].done, 0)
}
function sumTotal(t: Record<StudyType, TypeAgg>) {
  return STUDY_TYPES.reduce((a, k) => a + t[k].total, 0)
}

interface SubjAgg {
  id: number
  name: string
  group: string
  colorSoft: string
  colorVivid: string
  itemCount: number
  types: Record<StudyType, TypeAgg>
  majors: { name: string; types: Record<StudyType, TypeAgg> }[]
}

const subjAgg = computed<SubjAgg[]>(() => {
  const map = new Map<number, SubjAgg>()
  for (const it of incItems.value) {
    if (!map.has(it.subjectId)) {
      map.set(it.subjectId, {
        id: it.subjectId,
        name: it.subjectName,
        group: it.group,
        colorSoft: it.colorSoft,
        colorVivid: it.colorVivid,
        itemCount: 0,
        types: emptyTypes(),
        majors: [],
      })
    }
    const s = map.get(it.subjectId)!
    s.itemCount++
    addTypes(s.types, it)
    let mj = s.majors.find((m) => m.name === it.major)
    if (!mj) {
      mj = { name: it.major, types: emptyTypes() }
      s.majors.push(mj)
    }
    addTypes(mj.types, it)
  }
  return [...map.values()]
})

const overall = computed(() => {
  const t = emptyTypes()
  for (const s of subjAgg.value) {
    for (const k of STUDY_TYPES) {
      t[k].done += s.types[k].done
      t[k].total += s.types[k].total
    }
  }
  const done = sumDone(t)
  const total = sumTotal(t)
  return {
    lecPct: pct(t['講義'].done, t['講義'].total),
    lecDone: t['講義'].done,
    lecTotal: t['講義'].total,
    quizPct: pct(t['問題集'].done, t['問題集'].total),
    quizDone: t['問題集'].done,
    quizTotal: t['問題集'].total,
    textPct: pct(t['教科書'].done, t['教科書'].total),
    textDone: t['教科書'].done,
    textTotal: t['教科書'].total,
    allPct: pct(done, total),
    studied: done,
    totalBoth: total,
  }
})

const subjectCards = computed(() =>
  subjAgg.value
    .filter((s) => (!hideEmpty.value || sumDone(s.types) > 0) && sumTotal(s.types) > 0)
    .map((s) => {
      const color = ui.colorOf(s.colorSoft, s.colorVivid)
      const overallPct = pct(sumDone(s.types), sumTotal(s.types))
      const circ = 2 * Math.PI * 30
      return {
        id: s.id,
        name: s.name,
        group: s.group,
        itemCount: s.itemCount,
        color,
        light: hexA(color, 0.12),
        overallPct,
        ringDash: `${((overallPct / 100) * circ).toFixed(1)} ${circ.toFixed(1)}`,
        lecPct: pct(s.types['講義'].done, s.types['講義'].total),
        lecDone: s.types['講義'].done,
        lecTotal: s.types['講義'].total,
        quizPct: pct(s.types['問題集'].done, s.types['問題集'].total),
        quizDone: s.types['問題集'].done,
        quizTotal: s.types['問題集'].total,
        textPct: pct(s.types['教科書'].done, s.types['教科書'].total),
        textDone: s.types['教科書'].done,
        textTotal: s.types['教科書'].total,
        majors: s.majors
          .filter((m) => sumTotal(m.types) > 0)
          .map((m) => ({
            name: m.name,
            pct: pct(sumDone(m.types), sumTotal(m.types)),
            done: sumDone(m.types),
            totalBoth: sumTotal(m.types),
          })),
      }
    }),
)

// ---- 英単語カード（習得率 = 習得済み / 総単語数、セクション別に展開可） ----
const VOCAB_COLOR = { soft: '#c9569b', vivid: '#e0338f' }
const vocabCards = computed(() =>
  vocab.progress
    .filter((p) => p.totalWords > 0)
    .map((p) => {
      const color = ui.colorOf(VOCAB_COLOR.soft, VOCAB_COLOR.vivid)
      const overallPct = pct(p.masteredCount, p.totalWords)
      const circ = 2 * Math.PI * 30
      return {
        id: p.id,
        resourceName: p.name,
        color,
        light: hexA(color, 0.12),
        overallPct,
        ringDash: `${((overallPct / 100) * circ).toFixed(1)} ${circ.toFixed(1)}`,
        masteredCount: p.masteredCount,
        totalWords: p.totalWords,
        sectionCount: p.sections.length,
        sections: p.sections
          .filter((s) => s.totalWords > 0)
          .map((s) => ({
            name: s.name,
            pct: pct(s.masteredCount, s.totalWords),
            done: s.masteredCount,
            total: s.totalWords,
          })),
      }
    }),
)
const vocabExpanded = reactive<Record<number, boolean>>({})
function toggleVocab(id: number) {
  vocabExpanded[id] = !vocabExpanded[id]
}

// ---- 目標カード ----
const goalCards = computed(() =>
  study.goals.map((g) => {
    const color = ui.colorOf(g.colorSoft, g.colorVivid)
    const dl = parseDate(g.deadline)
    const daysLeft = Math.max(0, daysBetween(today, dl))
    return {
      ...g,
      color,
      light: hexA(color, 0.12),
      daysLeft,
      pct: pct(g.done, g.target),
      urgentColor: daysLeft <= 7 ? '#e0533d' : '#9aa1ab',
      deadlineLabel: `${dl.getFullYear()}.${dl.getMonth() + 1}.${dl.getDate()}`,
    }
  }),
)

// ---- カレンダー / 予定 ----
const examDate = computed(() => auth.settings?.examDate ?? null)
const daysToExam = computed(() => (examDate.value ? Math.max(0, daysBetween(today, parseDate(examDate.value))) : 0))

const upcoming = computed(() =>
  study.events
    .map((e) => ({ ...e, d: parseDate(e.date) }))
    .filter((e) => e.d >= today)
    .sort((a, b) => a.d.getTime() - b.d.getTime()),
)
const nextEvent = computed(() => upcoming.value[0] ?? null)
const upcomingList = computed(() =>
  upcoming.value.slice(0, 3).map((e) => ({
    title: e.title,
    dateLabel: `${e.d.getMonth() + 1}/${e.d.getDate()}`,
    days: Math.max(0, daysBetween(today, e.d)),
  })),
)

const eventModal = ref<{ date: string; title: string } | null>(null)
function openEvent(date: string, title: string) {
  eventModal.value = { date, title }
}
async function saveEvent(title: string) {
  if (!eventModal.value) return
  const date = eventModal.value.date
  if (title.trim()) {
    await study.saveEvent(date, title.trim())
    ui.notify('予定を保存しました')
  } else {
    const ev = study.events.find((e) => e.date === date)
    if (ev) {
      await study.deleteEvent(ev.id)
      ui.notify('予定を削除しました')
    }
  }
  eventModal.value = null
}
async function deleteEvent() {
  if (!eventModal.value) return
  const ev = study.events.find((e) => e.date === eventModal.value!.date)
  if (ev) {
    await study.deleteEvent(ev.id)
    ui.notify('予定を削除しました')
  }
  eventModal.value = null
}

const homeCols = computed(() => (isMobile.value ? '1fr' : 'minmax(300px,340px) 1fr'))
function toggle(id: number) {
  expanded[id] = !expanded[id]
}
</script>

<template>
  <div>
    <!-- tabs -->
    <div style="display: flex; margin-bottom: 18px">
      <div class="seg">
        <button class="seg-btn" :class="{ on: tab === 'progress' }" @click="tab = 'progress'">進捗率</button>
        <button class="seg-btn" :class="{ on: tab === 'goals' }" @click="tab = 'goals'">目標設定状況</button>
      </div>
    </div>

    <!-- Progress tab -->
    <div v-if="tab === 'progress'" :style="{ display: 'grid', gridTemplateColumns: homeCols, gap: '18px', alignItems: 'start' }">
      <!-- LEFT -->
      <div :style="{ display: 'flex', flexDirection: 'column', gap: '14px', order: isMobile ? 2 : 0 }">
        <div class="card" style="padding: 16px 18px">
          <div class="row-between" style="margin-bottom: 12px">
            <span style="font-size: 13px; font-weight: 700">学習カレンダー</span>
            <span style="font-size: 10.5px; color: var(--faint)">直近16週間</span>
          </div>
          <Heatmap :counts="study.recordStats?.heatmap ?? {}" :cell="11" />
        </div>

        <MonthCalendar :events="study.events" :exam-date="examDate" @day-click="openEvent" />

        <div style="background: #1c2024; border-radius: 16px; padding: 15px 18px; color: #fff">
          <div class="row-between" style="align-items: baseline">
            <span style="font-size: 11.5px; color: #b7bcc6">受験本番まで</span>
            <span><span class="dm" style="font-size: 24px; font-weight: 700">{{ daysToExam }}</span><span style="font-size: 11px; color: #b7bcc6; margin-left: 2px">日</span></span>
          </div>
          <div v-if="nextEvent" class="row-between" style="align-items: baseline; margin-top: 10px; padding-top: 10px; border-top: 1px solid rgba(255, 255, 255, 0.12)">
            <span style="font-size: 11.5px; color: #b7bcc6">{{ nextEvent.title }}</span>
            <span><span class="dm" style="font-size: 18px; font-weight: 700; color: #9fb4ff">{{ Math.max(0, daysBetween(today, nextEvent.d)) }}</span><span style="font-size: 11px; color: #b7bcc6; margin-left: 2px">日</span></span>
          </div>
        </div>

        <div v-if="upcomingList.length" class="card" style="padding: 14px 16px">
          <div style="font-size: 12px; font-weight: 700; margin-bottom: 9px">今後の予定</div>
          <div style="display: flex; flex-direction: column; gap: 8px">
            <div v-for="(e, i) in upcomingList" :key="i" style="display: flex; align-items: center; gap: 9px">
              <span style="font-size: 11px; color: var(--faint); width: 36px">{{ e.dateLabel }}</span>
              <span style="flex: 1; font-size: 12px; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis">{{ e.title }}</span>
              <span style="font-size: 11px; font-weight: 600; color: #cf4486">{{ e.days }}日</span>
            </div>
          </div>
        </div>
      </div>

      <!-- RIGHT -->
      <div :style="{ minWidth: 0, order: isMobile ? 1 : 0 }">
        <!-- overall summary -->
        <div class="card" style="padding: 22px 24px; margin-bottom: 18px; display: flex; gap: 30px; align-items: center; flex-wrap: wrap">
          <div style="flex: 1; min-width: 200px">
            <div style="font-size: 12.5px; color: var(--faint); font-weight: 500; margin-bottom: 4px">全体の学習進捗</div>
            <div style="display: flex; align-items: baseline; gap: 10px">
              <span class="dm" style="font-size: 40px; font-weight: 700">{{ overall.allPct }}<span style="font-size: 19px">%</span></span>
              <span style="font-size: 13px; color: var(--mut)">{{ overall.studied }} / {{ overall.totalBoth }} 行</span>
            </div>
            <div class="track" style="height: 9px; margin-top: 12px">
              <div :style="{ height: '100%', width: overall.allPct + '%', background: 'linear-gradient(90deg,#3b50cc,#6678e6)', borderRadius: '99px' }"></div>
            </div>
          </div>
          <div style="display: flex; gap: 20px">
            <div style="text-align: center; min-width: 80px">
              <div style="font-size: 11.5px; color: var(--faint); margin-bottom: 3px">講義</div>
              <div class="dm" style="font-size: 23px; font-weight: 700">{{ overall.lecPct }}<span style="font-size: 13px">%</span></div>
              <div style="font-size: 11px; color: var(--faint)">{{ overall.lecDone }} / {{ overall.lecTotal }}</div>
            </div>
            <div style="width: 1px; background: var(--line)"></div>
            <div style="text-align: center; min-width: 80px">
              <div style="font-size: 11.5px; color: var(--faint); margin-bottom: 3px">問題集</div>
              <div class="dm" style="font-size: 23px; font-weight: 700">{{ overall.quizPct }}<span style="font-size: 13px">%</span></div>
              <div style="font-size: 11px; color: var(--faint)">{{ overall.quizDone }} / {{ overall.quizTotal }}</div>
            </div>
            <div style="width: 1px; background: var(--line)"></div>
            <div style="text-align: center; min-width: 80px">
              <div style="font-size: 11.5px; color: var(--faint); margin-bottom: 3px">教科書</div>
              <div class="dm" style="font-size: 23px; font-weight: 700">{{ overall.textPct }}<span style="font-size: 13px">%</span></div>
              <div style="font-size: 11px; color: var(--faint)">{{ overall.textDone }} / {{ overall.textTotal }}</div>
            </div>
          </div>
        </div>

        <!-- subject cards -->
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(330px, 1fr)); gap: 16px">
          <div v-for="s in subjectCards" :key="s.id" class="card" style="padding: 18px 20px">
            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 14px">
              <div v-if="ui.progressStyle === 'リング'" style="position: relative; width: 56px; height: 56px; flex-shrink: 0">
                <svg width="56" height="56" viewBox="0 0 70 70">
                  <circle cx="35" cy="35" r="30" fill="none" stroke="#eef0f3" stroke-width="8" />
                  <circle cx="35" cy="35" r="30" fill="none" :stroke="s.color" stroke-width="8" stroke-linecap="round" :stroke-dasharray="s.ringDash" transform="rotate(-90 35 35)" />
                </svg>
                <div class="dm" style="position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700">{{ s.overallPct }}</div>
              </div>
              <div v-else :style="{ width: '11px', height: '38px', borderRadius: '6px', background: s.color, flexShrink: 0 }"></div>
              <div style="flex: 1; min-width: 0">
                <div class="row-between">
                  <div style="display: flex; align-items: center; gap: 8px">
                    <span style="font-size: 16px; font-weight: 700">{{ s.name }}</span>
                    <span :style="{ fontSize: '10.5px', fontWeight: 600, color: s.color, background: s.light, padding: '2px 7px', borderRadius: '99px' }">{{ s.group }}</span>
                  </div>
                  <span style="font-size: 11.5px; color: var(--faint)">{{ s.itemCount }}項目</span>
                </div>
                <template v-if="ui.progressStyle !== 'リング'">
                  <div v-if="ui.progressStyle === 'ドット'" style="display: flex; gap: 3px; margin-top: 9px">
                    <div v-for="i in 10" :key="i" :style="{ flex: 1, height: '7px', borderRadius: '2px', background: i <= Math.round(s.overallPct / 10) ? s.color : '#eef0f3' }"></div>
                  </div>
                  <div v-else class="track" style="height: 7px; margin-top: 9px">
                    <div :style="{ height: '100%', width: s.overallPct + '%', background: s.color, borderRadius: '99px' }"></div>
                  </div>
                </template>
              </div>
            </div>

            <div style="display: flex; gap: 8px; margin-bottom: 4px">
              <div class="stat-box">
                <div class="row-between"><span style="font-size: 11.5px; color: var(--mut); font-weight: 500">講義</span><span class="dm" style="font-size: 13px; font-weight: 700">{{ s.lecPct }}%</span></div>
                <div style="font-size: 10.5px; color: var(--faint)">{{ s.lecDone }} / {{ s.lecTotal }}</div>
              </div>
              <div class="stat-box">
                <div class="row-between"><span style="font-size: 11.5px; color: var(--mut); font-weight: 500">問題集</span><span class="dm" style="font-size: 13px; font-weight: 700">{{ s.quizPct }}%</span></div>
                <div style="font-size: 10.5px; color: var(--faint)">{{ s.quizDone }} / {{ s.quizTotal }}</div>
              </div>
              <div class="stat-box">
                <div class="row-between"><span style="font-size: 11.5px; color: var(--mut); font-weight: 500">教科書</span><span class="dm" style="font-size: 13px; font-weight: 700">{{ s.textPct }}%</span></div>
                <div style="font-size: 10.5px; color: var(--faint)">{{ s.textDone }} / {{ s.textTotal }}</div>
              </div>
            </div>

            <button class="toggle-btn" @click="toggle(s.id)">
              {{ expanded[s.id] ? '大分類を閉じる' : '大分類別の進捗を見る' }}
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" :style="{ transform: expanded[s.id] ? 'rotate(180deg)' : 'none' }"><path d="M6 9l6 6 6-6" /></svg>
            </button>

            <div v-if="expanded[s.id]" style="border-top: 1px solid #f0f1f3; margin-top: 4px; padding-top: 12px; display: flex; flex-direction: column; gap: 11px">
              <div v-for="(m, mi) in s.majors" :key="mi">
                <div class="row-between" style="align-items: baseline; margin-bottom: 5px">
                  <span style="font-size: 12.5px; font-weight: 500">{{ m.name }}</span>
                  <span style="font-size: 11px; color: var(--faint)">{{ m.pct }}% · {{ m.done }}/{{ m.totalBoth }}</span>
                </div>
                <div class="track" style="height: 6px">
                  <div :style="{ height: '100%', width: m.pct + '%', background: s.color, opacity: 0.85, borderRadius: '99px' }"></div>
                </div>
              </div>
            </div>
          </div>

          <!-- 英単語カード（習得率 = 習得済み/総単語数、セクション別に展開可） -->
          <div v-for="v in vocabCards" :key="'vocab-' + v.id" class="card" style="padding: 18px 20px">
            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 14px">
              <div v-if="ui.progressStyle === 'リング'" style="position: relative; width: 56px; height: 56px; flex-shrink: 0">
                <svg width="56" height="56" viewBox="0 0 70 70">
                  <circle cx="35" cy="35" r="30" fill="none" stroke="#eef0f3" stroke-width="8" />
                  <circle cx="35" cy="35" r="30" fill="none" :stroke="v.color" stroke-width="8" stroke-linecap="round" :stroke-dasharray="v.ringDash" transform="rotate(-90 35 35)" />
                </svg>
                <div class="dm" style="position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700">{{ v.overallPct }}</div>
              </div>
              <div v-else :style="{ width: '11px', height: '38px', borderRadius: '6px', background: v.color, flexShrink: 0 }"></div>
              <div style="flex: 1; min-width: 0">
                <div class="row-between">
                  <div style="display: flex; align-items: center; gap: 8px; min-width: 0">
                    <span style="font-size: 16px; font-weight: 700">英単語</span>
                    <span :style="{ fontSize: '10.5px', fontWeight: 600, color: v.color, background: v.light, padding: '2px 7px', borderRadius: '99px' }">習得率</span>
                  </div>
                  <router-link :to="{ name: 'quiz' }" style="font-size: 11.5px; color: var(--faint); text-decoration: none; white-space: nowrap">クイズへ ›</router-link>
                </div>
                <div style="font-size: 11px; color: var(--faint); margin-top: 2px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap">{{ v.resourceName }}</div>
                <template v-if="ui.progressStyle !== 'リング'">
                  <div v-if="ui.progressStyle === 'ドット'" style="display: flex; gap: 3px; margin-top: 9px">
                    <div v-for="i in 10" :key="i" :style="{ flex: 1, height: '7px', borderRadius: '2px', background: i <= Math.round(v.overallPct / 10) ? v.color : '#eef0f3' }"></div>
                  </div>
                  <div v-else class="track" style="height: 7px; margin-top: 9px">
                    <div :style="{ height: '100%', width: v.overallPct + '%', background: v.color, borderRadius: '99px' }"></div>
                  </div>
                </template>
              </div>
            </div>

            <div style="display: flex; gap: 8px; margin-bottom: 4px">
              <div class="stat-box">
                <div class="row-between"><span style="font-size: 11.5px; color: var(--mut); font-weight: 500">習得</span><span class="dm" style="font-size: 13px; font-weight: 700">{{ v.overallPct }}%</span></div>
                <div style="font-size: 10.5px; color: var(--faint)">{{ v.masteredCount }} / {{ v.totalWords }} 語</div>
              </div>
              <div class="stat-box">
                <div class="row-between"><span style="font-size: 11.5px; color: var(--mut); font-weight: 500">セクション</span><span class="dm" style="font-size: 13px; font-weight: 700">{{ v.sectionCount }}</span></div>
                <div style="font-size: 10.5px; color: var(--faint)">全 {{ v.totalWords }} 語</div>
              </div>
            </div>

            <button class="toggle-btn" @click="toggleVocab(v.id)">
              {{ vocabExpanded[v.id] ? 'セクション別を閉じる' : 'セクション別の進捗を見る' }}
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" :style="{ transform: vocabExpanded[v.id] ? 'rotate(180deg)' : 'none' }"><path d="M6 9l6 6 6-6" /></svg>
            </button>

            <div v-if="vocabExpanded[v.id]" style="border-top: 1px solid #f0f1f3; margin-top: 4px; padding-top: 12px; display: flex; flex-direction: column; gap: 11px; max-height: 340px; overflow-y: auto">
              <div v-for="(sec, si) in v.sections" :key="si">
                <div class="row-between" style="align-items: baseline; margin-bottom: 5px">
                  <span style="font-size: 12.5px; font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap">{{ sec.name }}</span>
                  <span style="font-size: 11px; color: var(--faint); white-space: nowrap; flex-shrink: 0; margin-left: 8px">{{ sec.pct }}% · {{ sec.done }}/{{ sec.total }}</span>
                </div>
                <div class="track" style="height: 6px">
                  <div :style="{ height: '100%', width: sec.pct + '%', background: v.color, opacity: 0.85, borderRadius: '99px' }"></div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Goals-status tab -->
    <div v-else style="display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 16px">
      <div v-for="g in goalCards" :key="g.id" class="card" style="padding: 18px 20px">
        <div class="row-between" style="margin-bottom: 13px">
          <div style="display: flex; align-items: center; gap: 8px">
            <span :style="{ width: '9px', height: '9px', borderRadius: '50%', background: g.color }"></span>
            <span :style="{ fontSize: '11px', fontWeight: 600, color: g.color, background: g.light, padding: '2px 8px', borderRadius: '99px' }">{{ g.rangeLabel }}</span>
          </div>
          <span :style="{ fontSize: '11.5px', fontWeight: 600, color: g.urgentColor }">残り{{ g.daysLeft }}日</span>
        </div>
        <div style="font-size: 15px; font-weight: 700; margin-bottom: 14px; line-height: 1.4">{{ g.title }}</div>
        <div class="row-between" style="align-items: baseline; margin-bottom: 6px">
          <span style="font-size: 12px; color: var(--mut)">達成項目</span>
          <span class="dm" style="font-size: 13px; font-weight: 700">{{ g.done }} / {{ g.target }}<span :style="{ color: g.color, marginLeft: '6px' }">{{ g.pct }}%</span></span>
        </div>
        <div class="track" style="height: 8px"><div :style="{ height: '100%', width: g.pct + '%', background: g.color, borderRadius: '99px' }"></div></div>
        <div style="font-size: 11.5px; color: var(--faint); margin-top: 9px">期限: {{ g.deadlineLabel }}</div>
      </div>
    </div>

    <EventModal
      v-if="eventModal"
      :date="eventModal.date"
      :title="eventModal.title"
      @save="saveEvent"
      @delete="deleteEvent"
      @close="eventModal = null"
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
.row-between {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.track {
  background: #eef0f3;
  border-radius: 99px;
  overflow: hidden;
}
.stat-box {
  flex: 1;
  background: #f8f9fb;
  border-radius: 10px;
  padding: 9px 12px;
}
.toggle-btn {
  width: 100%;
  margin-top: 8px;
  padding: 7px;
  border: none;
  background: transparent;
  color: var(--mut);
  font-size: 12px;
  font-weight: 500;
  cursor: pointer;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 5px;
}
</style>
