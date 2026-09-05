<script setup lang="ts">
import { computed } from 'vue'
import { hexA, parseDate, pct } from '@/lib/design'
import { useStudyStore } from '@/stores/study'
import { useUiStore } from '@/stores/ui'
import { STUDY_TYPES, type StudyItemRow, type StudyType } from '@/types'

/**
 * 科目別進捗のペラいち表示（全体サマリストリップ + 科目パネル）。
 * 展開操作なしで全科目の大分類まで一見できる。生徒トップと講師の科目別学習状況で共用。
 */
const props = defineProps<{
  hideEmpty?: boolean // 進捗0の科目を隠す（生徒の表示設定）
}>()

const study = useStudyStore()
const ui = useUiStore()

interface TypeAgg {
  done: number
  total: number
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

// 種別の短縮ラベル（1行に収めるため）
const TYPE_SHORT: Record<StudyType, string> = { 講義: '講義', 問題集: '問題', 教科書: '教科' }

interface MajorAgg {
  name: string
  types: Record<StudyType, TypeAgg>
  mids: { name: string; types: Record<StudyType, TypeAgg> }[]
}

interface SubjAgg {
  id: number
  name: string
  group: string
  colorSoft: string
  colorVivid: string
  types: Record<StudyType, TypeAgg>
  majors: MajorAgg[]
  lastDate: string | null
}

const subjAgg = computed<SubjAgg[]>(() => {
  const map = new Map<number, SubjAgg>()
  for (const it of study.items.filter((i) => i.included)) {
    if (!map.has(it.subjectId)) {
      map.set(it.subjectId, {
        id: it.subjectId,
        name: it.subjectName,
        group: it.group,
        colorSoft: it.colorSoft,
        colorVivid: it.colorVivid,
        types: emptyTypes(),
        majors: [],
        lastDate: null,
      })
    }
    const s = map.get(it.subjectId)!
    addTypes(s.types, it)
    for (const t of STUDY_TYPES) {
      const last = it.byType[t]?.lastDate
      if (last && (!s.lastDate || last > s.lastDate)) s.lastDate = last
    }
    let mj = s.majors.find((m) => m.name === it.major)
    if (!mj) {
      mj = { name: it.major, types: emptyTypes(), mids: [] }
      s.majors.push(mj)
    }
    addTypes(mj.types, it)
    // 中分類別の内訳（大分類行のホバーで表示）
    let md = mj.mids.find((m) => m.name === it.mid)
    if (!md) {
      md = { name: it.mid, types: emptyTypes() }
      mj.mids.push(md)
    }
    addTypes(md.types, it)
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
    allPct: pct(done, total),
    studied: done,
    totalBoth: total,
    perType: STUDY_TYPES.filter((k) => t[k].total > 0).map((k) => ({
      name: k,
      pct: pct(t[k].done, t[k].total),
      done: t[k].done,
      total: t[k].total,
    })),
  }
})

/** 科目パネル: 展開なしで全大分類まで一見できる形。データのない種別(0/0)は出さない */
const subjectPanels = computed(() =>
  subjAgg.value
    .filter((s) => sumTotal(s.types) > 0 && (!props.hideEmpty || sumDone(s.types) > 0))
    .map((s) => {
      const color = ui.colorOf(s.colorSoft, s.colorVivid)
      return {
        id: s.id,
        name: s.name,
        group: s.group,
        color,
        light: hexA(color, 0.12),
        overallPct: pct(sumDone(s.types), sumTotal(s.types)),
        done: sumDone(s.types),
        total: sumTotal(s.types),
        lastLabel: s.lastDate ? fmtMd(s.lastDate) : null,
        perType: STUDY_TYPES.filter((k) => s.types[k].total > 0).map((k) => ({
          name: TYPE_SHORT[k],
          pct: pct(s.types[k].done, s.types[k].total),
          done: s.types[k].done,
          total: s.types[k].total,
        })),
        majors: s.majors
          .filter((m) => sumTotal(m.types) > 0)
          .map((m) => ({
            name: m.name,
            pct: pct(sumDone(m.types), sumTotal(m.types)),
            done: sumDone(m.types),
            totalBoth: sumTotal(m.types),
            mids: m.mids
              .filter((x) => sumTotal(x.types) > 0)
              .map((x) => ({
                name: x.name,
                pct: pct(sumDone(x.types), sumTotal(x.types)),
                done: sumDone(x.types),
                totalBoth: sumTotal(x.types),
              })),
          })),
      }
    }),
)

function fmtMd(isoDate: string) {
  const d = parseDate(isoDate)
  return `${d.getMonth() + 1}/${d.getDate()}`
}
</script>

<template>
  <div>
    <!-- 全体サマリ（コンパクトな1行ストリップ） -->
    <div class="card strip">
      <span style="font-size: 13px; font-weight: 700; flex-shrink: 0">全体の学習進捗</span>
      <div class="strip-all">
        <span class="dm" style="font-size: 22px; font-weight: 700">{{ overall.allPct }}<span style="font-size: 12px">%</span></span>
        <div style="flex: 1; min-width: 80px">
          <div class="track" style="height: 8px">
            <div :style="{ height: '100%', width: overall.allPct + '%', background: 'linear-gradient(90deg,#3b50cc,#6678e6)', borderRadius: '99px' }"></div>
          </div>
        </div>
        <span style="font-size: 11.5px; color: var(--mut); flex-shrink: 0">{{ overall.studied }}/{{ overall.totalBoth }}行</span>
      </div>
      <div class="strip-types">
        <span v-for="t in overall.perType" :key="t.name" class="strip-type">
          {{ t.name }} <b class="dm">{{ t.pct }}%</b><span class="strip-sub">（{{ t.done }}/{{ t.total }}）</span>
        </span>
      </div>
    </div>

    <!-- 科目パネル: 展開操作なしで大分類まで全て表示 -->
    <div class="panel-grid">
      <div v-for="s in subjectPanels" :key="s.id" class="card panel">
        <div class="p-head" :style="{ borderLeft: `4px solid ${s.color}` }">
          <span class="p-name">{{ s.name }}</span>
          <span class="p-group" :style="{ color: s.color, background: s.light }">{{ s.group }}</span>
          <span v-if="s.lastLabel" class="p-last">最終学習 {{ s.lastLabel }}</span>
          <span style="flex: 1"></span>
          <span class="dm p-pct" :style="{ color: s.color }">{{ s.overallPct }}%</span>
        </div>
        <div class="track" style="height: 6px; margin: 6px 0 7px">
          <div :style="{ height: '100%', width: s.overallPct + '%', background: s.color, borderRadius: '99px' }"></div>
        </div>

        <div class="p-types">
          <span v-for="t in s.perType" :key="t.name" class="p-type">
            {{ t.name }} <b class="dm">{{ t.pct }}%</b><span class="p-type-sub">{{ t.done }}/{{ t.total }}</span>
          </span>
          <span style="flex: 1"></span>
          <span class="p-type-sub">計 {{ s.done }}/{{ s.total }}行</span>
        </div>

        <div class="majors">
          <div v-for="m in s.majors" :key="m.name" class="major-row">
            <span class="m-name">{{ m.name }}</span>
            <div class="m-bar track">
              <div :style="{ height: '100%', width: m.pct + '%', background: s.color, borderRadius: '99px' }"></div>
            </div>
            <span class="m-num dm">{{ m.pct }}%</span>
            <span class="m-cnt">{{ m.done }}/{{ m.totalBoth }}</span>

            <!-- ホバーで中分類別の内訳を表示 -->
            <div v-if="m.mids.length" class="mid-pop">
              <div class="mid-pop-title">{{ m.name }} の内訳（中分類別）</div>
              <div class="mid-pop-rows">
                <div v-for="md in m.mids" :key="md.name" class="mid-row">
                  <span class="mid-name">{{ md.name }}</span>
                  <div class="mid-bar track">
                    <div :style="{ height: '100%', width: md.pct + '%', background: s.color, borderRadius: '99px' }"></div>
                  </div>
                  <span class="m-num dm">{{ md.pct }}%</span>
                  <span class="m-cnt">{{ md.done }}/{{ md.totalBoth }}</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div v-if="!subjectPanels.length" style="padding: 40px; text-align: center; color: var(--faint); font-size: 13px; grid-column: 1 / -1">
        進捗対象のデータがありません
      </div>
    </div>
  </div>
</template>

<style scoped>
.strip {
  display: flex;
  align-items: center;
  gap: 18px;
  flex-wrap: wrap;
  padding: 12px 18px;
  margin-bottom: 14px;
}
.strip-all {
  display: flex;
  align-items: center;
  gap: 10px;
  flex: 1;
  min-width: 220px;
}
.strip-types {
  display: flex;
  align-items: center;
  gap: 14px;
  flex-wrap: wrap;
}
.strip-type {
  font-size: 12px;
  color: var(--mut);
  white-space: nowrap;
}
.strip-type b {
  font-size: 13px;
  color: var(--ink);
  margin-left: 2px;
}
.strip-sub {
  font-size: 10.5px;
  color: var(--faint);
}
.track {
  background: #eef0f3;
  border-radius: 99px;
  overflow: hidden;
}
.panel-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
  gap: 12px;
  align-items: start;
}
.panel {
  padding: 12px 15px 13px;
}
.p-head {
  display: flex;
  align-items: baseline;
  gap: 8px;
  padding-left: 9px;
  flex-wrap: wrap;
}
.p-name {
  font-size: 15px;
  font-weight: 700;
}
.p-group {
  font-size: 10px;
  font-weight: 600;
  padding: 1px 7px;
  border-radius: 99px;
}
.p-last {
  font-size: 10.5px;
  color: var(--faint);
}
.p-pct {
  font-size: 16px;
  font-weight: 700;
}
.p-types {
  display: flex;
  align-items: baseline;
  gap: 12px;
  flex-wrap: wrap;
  padding-bottom: 7px;
  border-bottom: 1px solid #f2f3f5;
}
.p-type {
  font-size: 11px;
  color: var(--mut);
  white-space: nowrap;
}
.p-type b {
  font-size: 12px;
  color: var(--ink);
  margin: 0 1px 0 2px;
}
.p-type-sub {
  font-size: 10px;
  color: var(--faint);
}
.majors {
  margin-top: 7px;
  display: flex;
  flex-direction: column;
  gap: 5px;
}
.major-row {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
  position: relative;
  padding: 2px 4px;
  margin: -2px -4px;
  border-radius: 7px;
}
.major-row:hover {
  background: #f6f8fb;
}
.mid-pop {
  display: none;
  position: absolute;
  left: 0;
  right: 0;
  top: calc(100% + 4px);
  z-index: 40;
  background: #fff;
  border: 1px solid #e3e6ea;
  border-radius: 12px;
  padding: 11px 13px;
  box-shadow: 0 10px 30px rgba(15, 20, 30, 0.16);
}
.major-row:hover .mid-pop {
  display: block;
}
.mid-pop-title {
  font-size: 11px;
  font-weight: 700;
  color: var(--mut);
  margin-bottom: 7px;
}
.mid-pop-rows {
  display: flex;
  flex-direction: column;
  gap: 5px;
  max-height: 240px;
  overflow-y: auto;
}
.mid-row {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
}
.mid-name {
  width: 40%;
  flex-shrink: 0;
  font-size: 11px;
  font-weight: 500;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.mid-bar {
  flex: 1;
  height: 5px;
}
.m-name {
  width: 96px;
  flex-shrink: 0;
  font-size: 11.5px;
  font-weight: 500;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.m-bar {
  flex: 1;
  height: 5px;
}
.m-num {
  width: 40px;
  flex-shrink: 0;
  text-align: right;
  font-size: 11px;
  font-weight: 700;
}
.m-cnt {
  width: 52px;
  flex-shrink: 0;
  text-align: right;
  font-size: 10px;
  color: var(--faint);
}
</style>
