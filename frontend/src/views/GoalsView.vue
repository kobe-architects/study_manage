<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { daysBetween, hexA, iso, parseDate } from '@/lib/design'
import { useStudyStore } from '@/stores/study'
import { useUiStore } from '@/stores/ui'

const study = useStudyStore()
const ui = useUiStore()

const today = new Date()
today.setHours(0, 0, 0, 0)

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
      pct: g.target ? Math.round((g.done / g.target) * 1000) / 10 : 0,
      urgentColor: daysLeft <= 7 ? '#e0533d' : '#9aa1ab',
      deadlineLabel: `${dl.getFullYear()}.${dl.getMonth() + 1}.${dl.getDate()}`,
    }
  }),
)

// add modal
const open = ref(false)
const form = reactive({ title: '', subjectId: 0, scope: 'all', deadline: '', target: 10 })

const subjects = computed(() => {
  const seen = new Map<number, string>()
  study.items.forEach((i) => seen.set(i.subjectId, i.subjectName))
  return [...seen.entries()].map(([id, name]) => ({ id, name }))
})
const majors = computed(() => {
  const s = new Set<string>()
  study.items.filter((i) => i.subjectId === form.subjectId).forEach((i) => s.add(i.major))
  return ['all', ...s]
})

function openModal() {
  form.title = ''
  form.subjectId = subjects.value[0]?.id ?? 0
  form.scope = 'all'
  form.deadline = iso(new Date(today.getTime() + 14 * 86400000))
  form.target = 10
  open.value = true
}

async function save() {
  if (!form.title.trim()) {
    ui.notify('タイトルを入力してください')
    return
  }
  const subjectName = subjects.value.find((s) => s.id === form.subjectId)?.name ?? ''
  const rangeLabel = form.scope === 'all' ? `${subjectName} 全体` : form.scope
  await study.createGoal({
    title: form.title.trim(),
    subjectId: form.subjectId,
    scope: form.scope,
    rangeLabel,
    deadline: form.deadline,
    target: Number(form.target) || 10,
  })
  open.value = false
  ui.notify('目標を追加しました')
}

async function remove(id: number) {
  await study.deleteGoal(id)
  ui.notify('目標を削除しました')
}
</script>

<template>
  <div>
    <div style="display: flex; justify-content: flex-end; margin-bottom: 14px">
      <button class="btn-dark" @click="openModal">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 5v14M5 12h14" /></svg>目標を追加
      </button>
    </div>

    <div style="display: flex; flex-direction: column; gap: 12px">
      <div v-for="g in goalCards" :key="g.id" class="card goal-row">
        <div style="flex: 1; min-width: 200px">
          <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px">
            <span :style="{ fontSize: '11px', fontWeight: 600, color: g.color, background: g.light, padding: '2px 8px', borderRadius: '99px' }">{{ g.rangeLabel }}</span>
            <span :style="{ fontSize: '11.5px', color: g.urgentColor, fontWeight: 600 }">期限まで{{ g.daysLeft }}日</span>
          </div>
          <div style="font-size: 15px; font-weight: 700">{{ g.title }}</div>
          <div style="font-size: 11.5px; color: var(--faint); margin-top: 3px">期限: {{ g.deadlineLabel }}</div>
        </div>
        <div style="width: 220px; flex-shrink: 0">
          <div class="row-between" style="align-items: baseline; margin-bottom: 6px">
            <span style="font-size: 12px; color: var(--mut)">進める項目</span>
            <span class="dm" style="font-size: 13px; font-weight: 700">{{ g.done }}/{{ g.target }} <span :style="{ color: g.color }">{{ g.pct }}%</span></span>
          </div>
          <div class="track"><div :style="{ height: '100%', width: g.pct + '%', background: g.color, borderRadius: '99px' }"></div></div>
        </div>
        <button class="del-btn" title="削除" @click="remove(g.id)">
          <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6" /></svg>
        </button>
      </div>
    </div>

    <div class="hint">
      達成・未達成の判定は行いません。学習リストに学習日を登録していくことで、目標に対する「進めた項目数」が自動でカウントされます。復習のため同じ項目に何度でも学習日を登録できます。
    </div>

    <!-- add modal -->
    <div v-if="open" class="overlay" @click="open = false">
      <div class="modal" @click.stop>
        <div style="font-size: 16px; font-weight: 700; margin-bottom: 18px">目標を追加</div>
        <div style="display: flex; flex-direction: column; gap: 13px">
          <label class="fld"><span>目標タイトル</span><input v-model="form.title" placeholder="例: 数学II 微分・積分を固める" /></label>
          <div style="display: flex; gap: 10px">
            <label class="fld" style="flex: 1"><span>科目</span>
              <select v-model.number="form.subjectId"><option v-for="s in subjects" :key="s.id" :value="s.id">{{ s.name }}</option></select>
            </label>
            <label class="fld" style="flex: 1"><span>範囲（大分類）</span>
              <select v-model="form.scope"><option v-for="m in majors" :key="m" :value="m">{{ m === 'all' ? '科目全体' : m }}</option></select>
            </label>
          </div>
          <div style="display: flex; gap: 10px">
            <label class="fld" style="flex: 1"><span>期限</span><input v-model="form.deadline" type="date" /></label>
            <label class="fld" style="flex: 1"><span>進める項目数</span><input v-model.number="form.target" type="number" min="1" /></label>
          </div>
        </div>
        <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px">
          <button class="btn-ghost" @click="open = false">キャンセル</button>
          <button class="btn-dark" @click="save">追加する</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.btn-dark {
  display: flex;
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
.goal-row {
  padding: 16px 20px;
  display: flex;
  align-items: center;
  gap: 20px;
  flex-wrap: wrap;
}
.row-between {
  display: flex;
  justify-content: space-between;
}
.track {
  height: 8px;
  background: #eef0f3;
  border-radius: 99px;
  overflow: hidden;
}
.del-btn {
  border: none;
  background: transparent;
  cursor: pointer;
  padding: 6px;
  color: #cbd1d8;
}
.hint {
  background: #f8f9fb;
  border: 1px dashed #d8dce1;
  border-radius: 14px;
  padding: 15px 18px;
  margin-top: 14px;
  font-size: 12.5px;
  color: var(--faint);
  line-height: 1.7;
}
.overlay {
  position: fixed;
  inset: 0;
  background: rgba(20, 24, 32, 0.4);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 50;
  padding: 20px;
}
.modal {
  background: #fff;
  border-radius: 16px;
  padding: 24px;
  width: 100%;
  max-width: 460px;
  box-shadow: 0 20px 50px rgba(0, 0, 0, 0.2);
}
.fld span {
  font-size: 12px;
  color: var(--mut);
  font-weight: 500;
  display: block;
  margin-bottom: 5px;
}
.fld input,
.fld select {
  width: 100%;
  padding: 9px 11px;
  border: 1px solid #e3e6ea;
  border-radius: 9px;
  font-size: 13px;
  outline: none;
  background: #fff;
}
.btn-ghost {
  padding: 9px 18px;
  border: 1px solid #e3e6ea;
  border-radius: 9px;
  background: #fff;
  cursor: pointer;
  font-size: 13px;
  font-weight: 600;
  color: var(--mut);
}
</style>
