<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { iso } from '@/lib/design'
import { useStudyStore } from '@/stores/study'
import { useUiStore } from '@/stores/ui'
import AssignmentCard from '@/components/AssignmentCard.vue'
import GoalLinkModal from '@/components/GoalLinkModal.vue'
import type { Assignment, GoalLinkBook } from '@/types'

const study = useStudyStore()
const ui = useUiStore()

const today = new Date()
today.setHours(0, 0, 0, 0)

onMounted(() => {
  study.fetchAssignments().catch(() => ui.notify('課題の取得に失敗しました'))
})

// ---- 課題の追加 / 編集 ----
const modal = reactive<{ open: boolean; id: number | null; title: string; note: string; dueOn: string; itemIds: number[] }>({
  open: false,
  id: null,
  title: '',
  note: '',
  dueOn: '',
  itemIds: [],
})

function openAdd() {
  modal.open = true
  modal.id = null
  modal.title = ''
  modal.note = ''
  modal.dueOn = iso(new Date(today.getTime() + 7 * 86400000))
  modal.itemIds = []
}
function openEdit(a: Assignment) {
  modal.open = true
  modal.id = a.id
  modal.title = a.title
  modal.note = a.note ?? ''
  modal.dueOn = a.dueOn
  modal.itemIds = [...a.itemIds]
}
async function save() {
  if (!modal.title.trim()) {
    ui.notify('課題タイトルを入力してください')
    return
  }
  if (!modal.itemIds.length) {
    ui.notify('個別学習データを1件以上選択してください')
    return
  }
  const payload = {
    title: modal.title.trim(),
    note: modal.note.trim() || null,
    dueOn: modal.dueOn,
    ids: modal.itemIds,
  }
  try {
    if (modal.id === null) {
      await study.createAssignment(payload)
      ui.notify(`課題を追加しました（${modal.itemIds.length}件）`)
    } else {
      await study.updateAssignment(modal.id, payload)
      ui.notify('課題を更新しました')
    }
    modal.open = false
  } catch {
    ui.notify('保存に失敗しました')
  }
}

async function remove(a: Assignment) {
  if (!confirm(`課題「${a.title}」を削除しますか？`)) return
  await study.deleteAssignment(a.id)
  ui.notify('削除しました')
}

// ---- 対象データの選択（紐づけツリーを流用） ----
const linkOpen = ref(false)
const linkBooks = ref<GoalLinkBook[]>([])
const linkLoading = ref(false)

async function openLink() {
  linkLoading.value = true
  try {
    linkBooks.value = await study.fetchGoalLinkOptions()
    linkOpen.value = true
  } catch {
    ui.notify('選択候補の取得に失敗しました')
  } finally {
    linkLoading.value = false
  }
}
function onLinkSave(ids: number[]) {
  modal.itemIds = ids
  linkOpen.value = false
}
</script>

<template>
  <div>
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; gap: 10px; flex-wrap: wrap">
      <div style="font-size: 17px; font-weight: 700">課題設定</div>
      <button class="btn-dark" @click="openAdd">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 5v14M5 12h14" /></svg>課題を追加
      </button>
    </div>

    <div style="display: flex; flex-direction: column; gap: 14px">
      <AssignmentCard v-for="a in study.assignments" :key="a.id" :assignment="a" @edit="openEdit" @remove="remove" />
      <div v-if="!study.assignments.length" class="hint" style="text-align: center">
        課題がまだありません。「課題を追加」から、個別学習データを選択して期限を設定してください。
      </div>
    </div>

    <div class="hint">
      課題の進捗は<b>課題作成後に生徒が学習記録を付けた行数</b>で自動集計されます。生徒のトップページにも同じ課題が表示され、進み具合が共有されます。
    </div>

    <!-- 課題の追加 / 編集 -->
    <div v-if="modal.open" class="overlay" @click="modal.open = false">
      <div class="modal" @click.stop>
        <div style="font-size: 16px; font-weight: 700; margin-bottom: 18px">{{ modal.id === null ? '課題を追加' : '課題を編集' }}</div>
        <div style="display: flex; flex-direction: column; gap: 13px">
          <label class="fld"><span>課題タイトル</span><input v-model="modal.title" placeholder="例: 今週中に三角比の例題を1周" /></label>
          <label class="fld"><span>期限</span><input v-model="modal.dueOn" type="date" /></label>
          <label class="fld"><span>メモ（任意）</span><textarea v-model="modal.note" rows="2" placeholder="生徒への補足・指示など"></textarea></label>
          <div>
            <span class="fld-label">個別学習データの選択<span style="color: #cf5563">（必須）</span></span>
            <button class="link-select" :class="{ empty: !modal.itemIds.length }" :disabled="linkLoading" @click="openLink">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1" /><path d="M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1" /></svg>
              {{ modal.itemIds.length ? `${modal.itemIds.length}件を選択済み（変更）` : 'ツリーから対象を選択' }}
            </button>
          </div>
        </div>
        <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px">
          <button class="btn-ghost" @click="modal.open = false">キャンセル</button>
          <button class="btn-dark" :disabled="!modal.title.trim() || !modal.itemIds.length" @click="save">{{ modal.id === null ? '追加する' : '保存' }}</button>
        </div>
      </div>
    </div>

    <!-- 対象データ選択ツリー -->
    <GoalLinkModal
      v-if="linkOpen"
      :goal-title="modal.title || '新しい課題'"
      :books="linkBooks"
      :initial-ids="modal.itemIds"
      @save="onLinkSave"
      @close="linkOpen = false"
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
.fld textarea {
  width: 100%;
  padding: 9px 11px;
  border: 1px solid #e3e6ea;
  border-radius: 9px;
  font-size: 13px;
  outline: none;
  background: #fff;
  font-family: inherit;
  resize: vertical;
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
