<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { hexA, pct } from '@/lib/design'
import { useStudyStore } from '@/stores/study'
import { useUiStore } from '@/stores/ui'
import { STUDY_TYPES, type StudyItemRow, type StudyType } from '@/types'

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

// ---- 進捗集計（生徒トップページと同じロジック: included のみ・教材の行ベース達成率） ----
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
  for (const it of study.items.filter((i) => i.included)) {
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
    allPct: pct(done, total),
    studied: done,
    totalBoth: total,
    perType: STUDY_TYPES.map((k) => ({ name: k, pct: pct(t[k].done, t[k].total), done: t[k].done, total: t[k].total })),
  }
})

const subjectCards = computed(() =>
  subjAgg.value
    .filter((s) => sumTotal(s.types) > 0)
    .map((s) => {
      const color = ui.colorOf(s.colorSoft, s.colorVivid)
      return {
        id: s.id,
        name: s.name,
        group: s.group,
        itemCount: s.itemCount,
        color,
        light: hexA(color, 0.12),
        overallPct: pct(sumDone(s.types), sumTotal(s.types)),
        perType: STUDY_TYPES.map((k) => ({ name: k, pct: pct(s.types[k].done, s.types[k].total), done: s.types[k].done, total: s.types[k].total })),
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

const expanded = reactive<Record<number, boolean>>({})
</script>

<template>
  <div>
    <div style="font-size: 17px; font-weight: 700; margin-bottom: 14px">科目別学習状況</div>

    <div v-if="loading" style="padding: 40px; text-align: center; color: var(--faint); font-size: 13px">読み込み中…</div>
    <template v-else>
      <!-- 全体サマリ -->
      <div class="card" style="padding: 20px 24px; margin-bottom: 16px; display: flex; gap: 26px; align-items: center; flex-wrap: wrap">
        <div style="flex: 1; min-width: 200px">
          <div style="font-size: 12.5px; color: var(--faint); font-weight: 500; margin-bottom: 4px">全体の学習進捗</div>
          <div style="display: flex; align-items: baseline; gap: 10px">
            <span class="dm" style="font-size: 36px; font-weight: 700">{{ overall.allPct }}<span style="font-size: 17px">%</span></span>
            <span style="font-size: 13px; color: var(--mut)">{{ overall.studied }} / {{ overall.totalBoth }} 行</span>
          </div>
          <div class="track" style="height: 9px; margin-top: 10px">
            <div :style="{ height: '100%', width: overall.allPct + '%', background: 'linear-gradient(90deg,#3b50cc,#6678e6)', borderRadius: '99px' }"></div>
          </div>
        </div>
        <div style="display: flex; gap: 18px">
          <template v-for="(t, i) in overall.perType" :key="t.name">
            <div v-if="i > 0" style="width: 1px; background: var(--line)"></div>
            <div style="text-align: center; min-width: 76px">
              <div style="font-size: 11.5px; color: var(--faint); margin-bottom: 3px">{{ t.name }}</div>
              <div class="dm" style="font-size: 21px; font-weight: 700">{{ t.pct }}<span style="font-size: 12px">%</span></div>
              <div style="font-size: 11px; color: var(--faint)">{{ t.done }} / {{ t.total }}</div>
            </div>
          </template>
        </div>
      </div>

      <!-- 科目カード -->
      <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(330px, 1fr)); gap: 16px">
        <div v-for="s in subjectCards" :key="s.id" class="card" style="padding: 18px 20px">
          <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px">
            <div :style="{ width: '11px', height: '38px', borderRadius: '6px', background: s.color, flexShrink: 0 }"></div>
            <div style="flex: 1; min-width: 0">
              <div class="row-between">
                <div style="display: flex; align-items: center; gap: 8px">
                  <span style="font-size: 16px; font-weight: 700">{{ s.name }}</span>
                  <span :style="{ fontSize: '10.5px', fontWeight: 600, color: s.color, background: s.light, padding: '2px 7px', borderRadius: '99px' }">{{ s.group }}</span>
                </div>
                <span class="dm" style="font-size: 15px; font-weight: 700">{{ s.overallPct }}%</span>
              </div>
              <div class="track" style="height: 7px; margin-top: 8px">
                <div :style="{ height: '100%', width: s.overallPct + '%', background: s.color, borderRadius: '99px' }"></div>
              </div>
            </div>
          </div>

          <div style="display: flex; gap: 8px; margin-bottom: 6px">
            <div v-for="t in s.perType" :key="t.name" class="stat-box">
              <div class="row-between"><span style="font-size: 11.5px; color: var(--mut); font-weight: 500">{{ t.name }}</span><span class="dm" style="font-size: 13px; font-weight: 700">{{ t.pct }}%</span></div>
              <div style="font-size: 10.5px; color: var(--faint)">{{ t.done }} / {{ t.total }}</div>
            </div>
          </div>

          <!-- 大分類別 -->
          <button class="expand-btn" @click="expanded[s.id] = !expanded[s.id]">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" :style="{ transform: expanded[s.id] ? 'rotate(90deg)' : 'none', transition: 'transform .12s' }"><path d="M9 6l6 6-6 6" /></svg>
            大分類別の進捗を見る
          </button>
          <div v-if="expanded[s.id]" style="margin-top: 6px; display: flex; flex-direction: column; gap: 7px">
            <div v-for="m in s.majors" :key="m.name">
              <div class="row-between" style="margin-bottom: 3px">
                <span style="font-size: 12px; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis">{{ m.name }}</span>
                <span style="font-size: 11px; color: var(--faint); flex-shrink: 0">{{ m.done }}/{{ m.totalBoth }}・{{ m.pct }}%</span>
              </div>
              <div class="track" style="height: 5px">
                <div :style="{ height: '100%', width: m.pct + '%', background: s.color, borderRadius: '99px' }"></div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div v-if="!subjectCards.length" style="padding: 40px; text-align: center; color: var(--faint); font-size: 13px">
        進捗対象のデータがありません
      </div>
    </template>
  </div>
</template>

<style scoped>
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
  background: #f7f8fa;
  border-radius: 10px;
  padding: 8px 10px;
}
.expand-btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  border: none;
  background: none;
  padding: 4px 0 2px;
  cursor: pointer;
  font-size: 12px;
  font-weight: 600;
  color: #3b50cc;
}
</style>
