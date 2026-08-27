<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import client from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useUiStore, type ColorMode, type NavStyle, type ProgressStyle } from '@/stores/ui'
import type { StudyType, TutorAccount, UserSettings } from '@/types'

const auth = useAuthStore()
const ui = useUiStore()

const local = reactive<UserSettings>({
  name: '',
  school: '',
  examDate: null,
  defaultType: '問題集',
  reminder: true,
  weeklyReport: true,
  hideEmpty: false,
  startScreen: 'home',
})

watch(
  () => auth.settings,
  (s) => {
    if (s) Object.assign(local, s)
  },
  { immediate: true, deep: true },
)

async function save(patch: Partial<UserSettings>) {
  Object.assign(local, patch)
  try {
    await auth.updateSettings(patch)
    ui.notify('設定を保存しました')
  } catch {
    ui.notify('保存に失敗しました')
  }
}

const colorModes: ColorMode[] = ['落ち着いた', '鮮やか', 'モノトーン']
const navStyles: NavStyle[] = ['トップナビ', 'サイドバー', 'アイコンレール']
const progressStyles: ProgressStyle[] = ['バー', 'リング', 'ドット']

const examDateModel = computed({
  get: () => local.examDate ?? '',
  set: (v: string) => save({ examDate: v || null }),
})

// ---- 家庭教師アカウント管理 ----
const tutors = ref<TutorAccount[]>([])
const tutorForm = reactive({ open: false, name: '', email: '', password: '', saving: false })
const pwEdit = reactive<{ id: number | null; password: string; saving: boolean }>({ id: null, password: '', saving: false })

onMounted(async () => {
  try {
    const { data } = await client.get('/tutors')
    tutors.value = data.data
  } catch {
    // 取得失敗時は空のまま
  }
})

async function addTutor() {
  if (!tutorForm.name.trim() || !tutorForm.email.trim() || tutorForm.password.length < 8) {
    ui.notify('氏名・メールアドレス・パスワード（8文字以上）を入力してください')
    return
  }
  tutorForm.saving = true
  try {
    const { data } = await client.post('/tutors', {
      name: tutorForm.name.trim(),
      email: tutorForm.email.trim(),
      password: tutorForm.password,
    })
    tutors.value.push(data.data)
    tutorForm.open = false
    tutorForm.name = ''
    tutorForm.email = ''
    tutorForm.password = ''
    ui.notify('家庭教師アカウントを追加しました')
  } catch {
    ui.notify('追加に失敗しました（メールアドレスの重複など）')
  } finally {
    tutorForm.saving = false
  }
}

async function saveTutorPassword() {
  if (pwEdit.id === null || pwEdit.password.length < 8) {
    ui.notify('新しいパスワードは8文字以上で入力してください')
    return
  }
  pwEdit.saving = true
  try {
    await client.put(`/tutors/${pwEdit.id}`, { password: pwEdit.password })
    pwEdit.id = null
    pwEdit.password = ''
    ui.notify('パスワードを再設定しました')
  } catch {
    ui.notify('再設定に失敗しました')
  } finally {
    pwEdit.saving = false
  }
}

async function removeTutor(t: TutorAccount) {
  if (!confirm(`家庭教師アカウント「${t.name}」を削除しますか？`)) return
  try {
    await client.delete(`/tutors/${t.id}`)
    tutors.value = tutors.value.filter((x) => x.id !== t.id)
    ui.notify('削除しました')
  } catch {
    ui.notify('削除に失敗しました')
  }
}
</script>

<template>
  <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 16px; align-items: start">
    <!-- account -->
    <div class="card sec">
      <div class="sec-title">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#6b7280" stroke-width="1.8"><circle cx="12" cy="8" r="4" /><path d="M4 20c0-4 4-6 8-6s8 2 8 6" /></svg>
        アカウント
      </div>
      <div style="display: flex; flex-direction: column; gap: 13px">
        <label class="fld"><span>氏名</span>
          <input :value="local.name" @change="save({ name: ($event.target as HTMLInputElement).value })" />
        </label>
        <label class="fld"><span>志望校</span>
          <input :value="local.school" @change="save({ school: ($event.target as HTMLInputElement).value })" />
        </label>
        <label class="fld"><span>受験日</span>
          <input v-model="examDateModel" type="date" />
        </label>
      </div>
    </div>

    <!-- display -->
    <div class="card sec">
      <div class="sec-title">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#6b7280" stroke-width="1.8"><circle cx="12" cy="12" r="4" /><path d="M12 2v3M12 19v3M2 12h3M19 12h3" /></svg>
        表示設定
      </div>
      <div style="display: flex; flex-direction: column; gap: 6px">
        <div class="opt">
          <div><div class="opt-t">デフォルト種別</div><div class="opt-s">学習記録の初期選択</div></div>
          <select :value="local.defaultType" @change="save({ defaultType: ($event.target as HTMLSelectElement).value as StudyType })">
            <option value="講義">講義</option><option value="問題集">問題集</option>
          </select>
        </div>
        <div class="opt bt">
          <div><div class="opt-t">未着手の項目を隠す</div><div class="opt-s">進捗0%の科目を非表示</div></div>
          <button class="toggle" :class="{ on: local.hideEmpty }" @click="save({ hideEmpty: !local.hideEmpty })"><span></span></button>
        </div>
        <div class="opt bt">
          <div><div class="opt-t">起動時の画面</div><div class="opt-s">ログイン後に表示</div></div>
          <select :value="local.startScreen" @change="save({ startScreen: ($event.target as HTMLSelectElement).value as UserSettings['startScreen'] })">
            <option value="home">トップページ</option><option value="record">学習記録</option><option value="goal">目標設定</option>
          </select>
        </div>
        <div class="opt bt">
          <div><div class="opt-t">科目カラー</div><div class="opt-s">配色のテイスト</div></div>
          <select v-model="ui.colorMode"><option v-for="c in colorModes" :key="c" :value="c">{{ c }}</option></select>
        </div>
        <div class="opt bt">
          <div><div class="opt-t">ナビゲーション</div><div class="opt-s">メニューの配置</div></div>
          <select v-model="ui.navStyle"><option v-for="n in navStyles" :key="n" :value="n">{{ n }}</option></select>
        </div>
        <div class="opt bt">
          <div><div class="opt-t">進捗インジケータ</div><div class="opt-s">科目カードの見せ方</div></div>
          <select v-model="ui.progressStyle"><option v-for="p in progressStyles" :key="p" :value="p">{{ p }}</option></select>
        </div>
      </div>
    </div>

    <!-- tutors -->
    <div class="card sec">
      <div class="sec-title">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#6b7280" stroke-width="1.8"><circle cx="9" cy="8" r="3.5" /><path d="M2.5 20c0-3.5 3-5.5 6.5-5.5s6.5 2 6.5 5.5" /><path d="M16 4a3.5 3.5 0 0 1 0 7" /><path d="M17.5 14.7c2.4.6 4 2.3 4 5.3" /></svg>
        家庭教師アカウント
      </div>
      <div style="display: flex; flex-direction: column; gap: 10px">
        <div v-for="t in tutors" :key="t.id" class="tutor-row">
          <div style="flex: 1; min-width: 0">
            <div style="font-size: 13px; font-weight: 600">{{ t.name }}</div>
            <div style="font-size: 11px; color: var(--faint); white-space: nowrap; overflow: hidden; text-overflow: ellipsis">{{ t.email }}</div>
          </div>
          <button class="mini-btn" @click="pwEdit.id = pwEdit.id === t.id ? null : t.id; pwEdit.password = ''">PW再設定</button>
          <button class="mini-btn danger" @click="removeTutor(t)">削除</button>
        </div>
        <div v-if="pwEdit.id !== null" class="tutor-form">
          <label class="fld"><span>新しいパスワード（8文字以上）</span>
            <input v-model="pwEdit.password" type="password" autocomplete="new-password" />
          </label>
          <div style="display: flex; gap: 8px; justify-content: flex-end">
            <button class="mini-btn" @click="pwEdit.id = null">キャンセル</button>
            <button class="mini-btn primary" :disabled="pwEdit.saving" @click="saveTutorPassword">再設定する</button>
          </div>
        </div>
        <div v-if="!tutors.length" style="font-size: 12px; color: var(--faint)">
          家庭教師アカウントはまだありません。追加すると、家庭教師用のページ（学習記録の閲覧・課題設定・学習計画）にログインできます。
        </div>

        <div v-if="tutorForm.open" class="tutor-form">
          <label class="fld"><span>氏名</span><input v-model="tutorForm.name" /></label>
          <label class="fld"><span>メールアドレス（ログインID）</span><input v-model="tutorForm.email" type="email" autocomplete="off" /></label>
          <label class="fld"><span>パスワード（8文字以上）</span><input v-model="tutorForm.password" type="password" autocomplete="new-password" /></label>
          <div style="display: flex; gap: 8px; justify-content: flex-end">
            <button class="mini-btn" @click="tutorForm.open = false">キャンセル</button>
            <button class="mini-btn primary" :disabled="tutorForm.saving" @click="addTutor">追加する</button>
          </div>
        </div>
        <button v-else class="data-btn" @click="tutorForm.open = true">
          <span>＋ 家庭教師アカウントを追加</span>
        </button>
      </div>
    </div>

    <!-- notifications -->
    <div class="card sec">
      <div class="sec-title">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#6b7280" stroke-width="1.8"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9" /><path d="M10 21a2 2 0 0 0 4 0" /></svg>
        通知
      </div>
      <div style="display: flex; flex-direction: column; gap: 6px">
        <div class="opt">
          <div><div class="opt-t">学習リマインダー</div><div class="opt-s">毎日20:00に通知</div></div>
          <button class="toggle" :class="{ on: local.reminder }" @click="save({ reminder: !local.reminder })"><span></span></button>
        </div>
        <div class="opt bt">
          <div><div class="opt-t">週次レポート</div><div class="opt-s">日曜に進捗サマリーを送信</div></div>
          <button class="toggle" :class="{ on: local.weeklyReport }" @click="save({ weeklyReport: !local.weeklyReport })"><span></span></button>
        </div>
      </div>
    </div>

    <!-- data -->
    <div class="card sec">
      <div class="sec-title">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#6b7280" stroke-width="1.8"><path d="M4 6c0-1.7 3.6-3 8-3s8 1.3 8 3-3.6 3-8 3-8-1.3-8-3z" /><path d="M4 6v12c0 1.7 3.6 3 8 3s8-1.3 8-3V6" /><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3" /></svg>
        データ管理
      </div>
      <div style="display: flex; flex-direction: column; gap: 9px">
        <button class="data-btn" @click="ui.notify('英単語管理画面からCSVエクスポートできます')"><span>英単語データをCSVでエクスポート</span><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#9aa1ab" stroke-width="2"><path d="M9 18l6-6-6-6" /></svg></button>
        <button class="data-btn" @click="ui.notify('英単語管理画面からCSVインポートできます')"><span>CSVからインポート</span><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#9aa1ab" stroke-width="2"><path d="M9 18l6-6-6-6" /></svg></button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.sec {
  padding: 20px;
}
.sec-title {
  font-size: 14px;
  font-weight: 700;
  margin-bottom: 16px;
  display: flex;
  align-items: center;
  gap: 8px;
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
}
.opt {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 9px 0;
}
.opt.bt {
  border-top: 1px solid #f4f5f7;
}
.opt-t {
  font-size: 13px;
  font-weight: 500;
}
.opt-s {
  font-size: 11px;
  color: var(--faint);
}
.opt select {
  padding: 7px 10px;
  border: 1px solid #e3e6ea;
  border-radius: 8px;
  font-size: 12.5px;
  cursor: pointer;
  outline: none;
}
.toggle {
  width: 42px;
  height: 24px;
  border-radius: 99px;
  border: none;
  cursor: pointer;
  background: #d3d7dd;
  position: relative;
}
.toggle.on {
  background: #1c2024;
}
.toggle span {
  position: absolute;
  top: 2px;
  left: 2px;
  width: 20px;
  height: 20px;
  border-radius: 50%;
  background: #fff;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
  transition: left 0.15s;
}
.toggle.on span {
  left: 20px;
}
.data-btn {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 11px 13px;
  border: 1px solid #e3e6ea;
  border-radius: 10px;
  background: #fff;
  cursor: pointer;
  font-size: 13px;
  font-weight: 500;
}
.tutor-row {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 9px 11px;
  border: 1px solid #eceef0;
  border-radius: 10px;
}
.tutor-form {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 12px;
  border: 1px dashed #d8dce1;
  border-radius: 10px;
  background: #fafbfc;
}
.mini-btn {
  flex-shrink: 0;
  padding: 6px 10px;
  border: 1px solid #e3e6ea;
  border-radius: 8px;
  background: #fff;
  font-size: 11.5px;
  font-weight: 600;
  color: var(--mut);
  cursor: pointer;
}
.mini-btn.primary {
  background: #1c2024;
  border-color: #1c2024;
  color: #fff;
}
.mini-btn.primary:disabled {
  opacity: 0.5;
}
.mini-btn.danger {
  color: #c0444f;
  border-color: #f0b8be;
}
</style>
