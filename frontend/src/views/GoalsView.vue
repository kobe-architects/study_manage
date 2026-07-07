<script setup lang="ts">
import { reactive, ref } from 'vue'
import { iso } from '@/lib/design'
import { useStudyStore } from '@/stores/study'
import { useUiStore } from '@/stores/ui'
import GoalCard from '@/components/GoalCard.vue'
import GoalLinkModal from '@/components/GoalLinkModal.vue'
import type { GoalLinkBook, Goal } from '@/types'

const study = useStudyStore()
const ui = useUiStore()

const today = new Date()
today.setHours(0, 0, 0, 0)

// ---- 目標の追加（親目標） ----
const open = ref(false)
const form = reactive({ title: '', deadline: '', itemIds: [] as number[] })

function openModal() {
  form.title = ''
  form.deadline = iso(new Date(today.getTime() + 14 * 86400000))
  form.itemIds = []
  open.value = true
}
async function save() {
  if (!form.title.trim()) {
    ui.notify('タイトルを入力してください')
    return
  }
  if (!form.itemIds.length) {
    ui.notify('個別学習データを1件以上紐づけてください')
    return
  }
  const newId = await study.createGoal({
    title: form.title.trim(),
    subjectId: null,
    scope: 'all',
    rangeLabel: '個別学習データ',
    deadline: form.deadline,
    target: form.itemIds.length,
  })
  if (newId) await study.updateGoalItems(newId, form.itemIds)
  open.value = false
  ui.notify(`目標を追加し、${form.itemIds.length}件紐づけました`)
}

// ---- 紐づけモーダル（既存目標の紐づけ変更／追加時の選択） ----
const linkGoal = ref<Goal | null>(null)
const linkBooks = ref<GoalLinkBook[]>([])
const linkLoading = ref(false)
const addLinkOpen = ref(false)

async function openLink(g: Goal) {
  linkLoading.value = true
  try {
    // 中間目標は親の項目のみ、親目標は全教材から
    linkBooks.value = g.parentId ? await study.fetchSubLinkOptions(g.parentId) : await study.fetchGoalLinkOptions()
    linkGoal.value = g
  } catch {
    ui.notify('紐づけ候補の取得に失敗しました')
  } finally {
    linkLoading.value = false
  }
}
async function onLinkSave(ids: number[]) {
  if (!linkGoal.value) return
  await study.updateGoalItems(linkGoal.value.id, ids)
  ui.notify(`紐づけを${ids.length}件に更新しました`)
  linkGoal.value = null
}

async function openAddLink() {
  linkLoading.value = true
  try {
    linkBooks.value = await study.fetchGoalLinkOptions()
    addLinkOpen.value = true
  } catch {
    ui.notify('紐づけ候補の取得に失敗しました')
  } finally {
    linkLoading.value = false
  }
}
function onAddLinkSave(ids: number[]) {
  form.itemIds = ids
  addLinkOpen.value = false
}

// ---- 中間目標の追加 ----
const subModal = reactive<{ open: boolean; parent: Goal | null; title: string; deadline: string; itemIds: number[] }>({
  open: false,
  parent: null,
  title: '',
  deadline: '',
  itemIds: [],
})
const subLinkOpen = ref(false)
const subLinkBooks = ref<GoalLinkBook[]>([])

function openSubModal(parent: Goal) {
  subModal.open = true
  subModal.parent = parent
  subModal.title = ''
  subModal.deadline = parent.deadline // 既定は親の期限（上限）
  subModal.itemIds = []
}
async function openSubLink() {
  if (!subModal.parent) return
  linkLoading.value = true
  try {
    subLinkBooks.value = await study.fetchSubLinkOptions(subModal.parent.id)
    subLinkOpen.value = true
  } catch {
    ui.notify('紐づけ候補の取得に失敗しました')
  } finally {
    linkLoading.value = false
  }
}
function onSubLinkSave(ids: number[]) {
  subModal.itemIds = ids
  subLinkOpen.value = false
}
async function saveSub() {
  if (!subModal.parent) return
  if (!subModal.title.trim()) {
    ui.notify('タイトルを入力してください')
    return
  }
  if (!subModal.itemIds.length) {
    ui.notify('中間目標に含める項目を1件以上選択してください')
    return
  }
  if (subModal.deadline > subModal.parent.deadline) {
    ui.notify('中間目標の期限は元の目標以前にしてください')
    return
  }
  await study.createSubGoal(subModal.parent.id, {
    title: subModal.title.trim(),
    deadline: subModal.deadline,
    ids: subModal.itemIds,
  })
  subModal.open = false
  ui.notify('中間目標を追加しました')
}

async function remove(g: Goal) {
  const msg = g.subGoals?.length
    ? `「${g.title}」と中間目標${g.subGoals.length}件を削除しますか？`
    : `「${g.title}」を削除しますか？`
  if (!confirm(msg)) return
  await study.deleteGoal(g.id)
  ui.notify('削除しました')
}
</script>

<template>
  <div>
    <div style="display: flex; justify-content: flex-end; margin-bottom: 14px">
      <button class="btn-dark" @click="openModal">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 5v14M5 12h14" /></svg>目標を追加
      </button>
    </div>

    <div style="display: flex; flex-direction: column; gap: 14px">
      <GoalCard v-for="g in study.goals" :key="g.id" :goal="g" @link="openLink" @add-sub="openSubModal" @remove="remove" />
      <div v-if="!study.goals.length" class="hint" style="text-align: center">
        目標がまだありません。「目標を追加」から、個別学習データを紐づけて目標を作成してください。
      </div>
    </div>

    <div class="hint">
      「紐づけ変更」で対象データを選ぶと、進捗は<b>目標設定後に学習記録された、または直接「学習済み」にした行数</b>で集計されます。紐づけデータを展開して各行をクリックすると学習済みを直接切り替えられます。<b>中間目標</b>は元の目標より前の期限で、元の目標に含まれる項目の中から設定でき、中間目標で学習済みにした項目は元の目標にも反映されます。
    </div>

    <!-- 目標を追加 -->
    <div v-if="open" class="overlay" @click="open = false">
      <div class="modal" @click.stop>
        <div style="font-size: 16px; font-weight: 700; margin-bottom: 18px">目標を追加</div>
        <div style="display: flex; flex-direction: column; gap: 13px">
          <label class="fld"><span>目標タイトル</span><input v-model="form.title" placeholder="例: 数学II 微分・積分を固める" /></label>
          <label class="fld"><span>期限</span><input v-model="form.deadline" type="date" /></label>
          <div>
            <span class="fld-label" style="margin-bottom: 2px">進める項目数は紐づけたデータ数になります</span>
          </div>
          <div>
            <span class="fld-label">個別学習データの紐づけ<span style="color: #cf5563">（必須）</span></span>
            <button class="link-select" :class="{ empty: !form.itemIds.length }" :disabled="linkLoading" @click="openAddLink">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1" /><path d="M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1" /></svg>
              {{ form.itemIds.length ? `${form.itemIds.length}件を紐づけ済み（変更）` : 'ツリーから対象を選択' }}
            </button>
          </div>
        </div>
        <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px">
          <button class="btn-ghost" @click="open = false">キャンセル</button>
          <button class="btn-dark" :disabled="!form.title.trim() || !form.itemIds.length" @click="save">追加する</button>
        </div>
      </div>
    </div>

    <!-- 中間目標を追加 -->
    <div v-if="subModal.open" class="overlay" @click="subModal.open = false">
      <div class="modal" @click.stop>
        <div style="font-size: 16px; font-weight: 700; margin-bottom: 4px">中間目標を追加</div>
        <div style="font-size: 12px; color: var(--faint); margin-bottom: 16px">元の目標: {{ subModal.parent?.title }}（期限 {{ subModal.parent?.deadline }}）</div>
        <div style="display: flex; flex-direction: column; gap: 13px">
          <label class="fld"><span>中間目標タイトル</span><input v-model="subModal.title" placeholder="例: 今週中に三角比を1周" /></label>
          <label class="fld"><span>期限（元の目標以前）</span><input v-model="subModal.deadline" type="date" :max="subModal.parent?.deadline" /></label>
          <div>
            <span class="fld-label">対象項目<span style="color: #cf5563">（必須・元の目標の項目から）</span></span>
            <button class="link-select" :class="{ empty: !subModal.itemIds.length }" :disabled="linkLoading" @click="openSubLink">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1" /><path d="M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1" /></svg>
              {{ subModal.itemIds.length ? `${subModal.itemIds.length}件を選択済み（変更）` : 'ツリーから対象を選択' }}
            </button>
          </div>
        </div>
        <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px">
          <button class="btn-ghost" @click="subModal.open = false">キャンセル</button>
          <button class="btn-dark" :disabled="!subModal.title.trim() || !subModal.itemIds.length" @click="saveSub">追加する</button>
        </div>
      </div>
    </div>

    <!-- 紐づけツリー: 既存目標 -->
    <GoalLinkModal
      v-if="linkGoal"
      :goal-title="linkGoal.title"
      :books="linkBooks"
      :initial-ids="linkGoal.itemIds"
      @save="onLinkSave"
      @close="linkGoal = null"
    />
    <!-- 紐づけツリー: 目標追加時 -->
    <GoalLinkModal
      v-if="addLinkOpen"
      :goal-title="form.title || '新しい目標'"
      :books="linkBooks"
      :initial-ids="form.itemIds"
      @save="onAddLinkSave"
      @close="addLinkOpen = false"
    />
    <!-- 紐づけツリー: 中間目標（親の項目のみ） -->
    <GoalLinkModal
      v-if="subLinkOpen"
      :goal-title="subModal.title || '中間目標'"
      :books="subLinkBooks"
      :initial-ids="subModal.itemIds"
      @save="onSubLinkSave"
      @close="subLinkOpen = false"
    />
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
.btn-dark:disabled {
  opacity: 0.4;
  cursor: default;
}
.sub-list {
  margin: 10px 0 0 26px;
  display: flex;
  flex-direction: column;
  gap: 10px;
  position: relative;
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
.fld input {
  width: 100%;
  padding: 9px 11px;
  border: 1px solid #e3e6ea;
  border-radius: 9px;
  font-size: 13px;
  outline: none;
  background: #fff;
}
.fld-label {
  font-size: 12px;
  color: var(--mut);
  font-weight: 500;
  display: block;
  margin-bottom: 5px;
}
.link-select {
  width: 100%;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 7px;
  padding: 10px 12px;
  border: 1px dashed #c1c8f0;
  border-radius: 9px;
  background: #f7f8ff;
  color: #3b50cc;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
}
.link-select:disabled {
  opacity: 0.6;
  cursor: default;
}
.link-select.empty {
  border-color: #f0b8be;
  background: #fdf3f4;
  color: #c0444f;
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
