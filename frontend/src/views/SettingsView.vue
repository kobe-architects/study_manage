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
  tutorQuizEnabled: false,
  tutorSubjectsEnabled: true,
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
// 講師のパスワード表示（行ごとに表示/非表示を切り替え）
const shownPw = ref<Set<number>>(new Set())
function togglePw(id: number) {
  const next = new Set(shownPw.value)
  if (next.has(id)) next.delete(id)
  else next.add(id)
  shownPw.value = next
}
/** 講師の時給を保存（講師請求管理で使用。締め時にスナップショットされる） */
async function saveTutorRate(t: TutorAccount, ev: Event) {
  const v = Math.max(0, Math.round(Number((ev.target as HTMLInputElement).value) || 0))
  try {
    const { data } = await client.put(`/tutors/${t.id}`, { hourlyRate: v })
    const idx = tutors.value.findIndex((x) => x.id === t.id)
    if (idx >= 0) tutors.value[idx] = data.data
    ui.notify(`${t.name} の時給を ${v.toLocaleString()} 円に設定しました`)
  } catch {
    ui.notify('時給の保存に失敗しました')
  }
}

// ---- LINE 通知連携 ----
async function copyTutorLineCode(t: TutorAccount) {
  try {
    await navigator.clipboard.writeText(t.lineLinkCode)
    ui.notify(`${t.name} の連携コードをコピーしました`)
  } catch {
    ui.notify('コピーに失敗しました')
  }
}
async function copyLineCode() {
  const code = auth.user?.lineLinkCode
  if (!code) return
  try {
    await navigator.clipboard.writeText(code)
    ui.notify('連携コードをコピーしました')
  } catch {
    ui.notify('コピーに失敗しました')
  }
}
async function refreshLine() {
  try {
    await auth.fetchMe()
    ui.notify(auth.user?.lineLinked ? 'LINE 連携済みです' : 'まだ連携されていません。コードをトークに送信してください')
  } catch {
    ui.notify('状態の取得に失敗しました')
  }
}

async function copyPw(t: TutorAccount) {
  if (!t.password) return
  try {
    await navigator.clipboard.writeText(t.password)
    ui.notify('パスワードをコピーしました')
  } catch {
    ui.notify('コピーに失敗しました')
  }
}

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
    ui.notify('氏名・ログインID・パスワード（8文字以上）を入力してください')
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
    ui.notify('追加に失敗しました（ログインIDの重複など）')
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
    const { data } = await client.put(`/tutors/${pwEdit.id}`, { password: pwEdit.password })
    const idx = tutors.value.findIndex((x) => x.id === pwEdit.id)
    if (idx >= 0) tutors.value[idx] = data.data
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
        <div class="opt">
          <div><div class="opt-t">講師メニューに小テストを表示</div><div class="opt-s">オフにすると講師画面のメニューから「小テスト」が非表示になります</div></div>
          <button class="toggle" :class="{ on: local.tutorQuizEnabled }" @click="save({ tutorQuizEnabled: !local.tutorQuizEnabled })"><span></span></button>
        </div>
        <div class="opt bt" style="margin-bottom: 4px">
          <div><div class="opt-t">講師メニューに科目別学習状況を表示</div><div class="opt-s">オフにすると講師画面のメニューから「科目別学習状況」が非表示になります</div></div>
          <button class="toggle" :class="{ on: local.tutorSubjectsEnabled }" @click="save({ tutorSubjectsEnabled: !local.tutorSubjectsEnabled })"><span></span></button>
        </div>
        <div v-for="t in tutors" :key="t.id" class="tutor-row">
          <div style="flex: 1 1 230px; min-width: 0">
            <div style="font-size: 13px; font-weight: 600">{{ t.name }}</div>
            <div style="font-size: 11px; color: var(--faint); white-space: nowrap; overflow: hidden; text-overflow: ellipsis">ID: {{ t.email }}</div>
            <div class="pw-line">
              <span style="color: var(--faint)">PW:</span>
              <template v-if="t.password">
                <span class="pw-value" :class="{ masked: !shownPw.has(t.id) }">{{ shownPw.has(t.id) ? t.password : '●'.repeat(Math.min(t.password.length, 12)) }}</span>
                <button class="pw-icon" :title="shownPw.has(t.id) ? '隠す' : '表示'" @click="togglePw(t.id)">
                  <svg v-if="!shownPw.has(t.id)" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12z" /><circle cx="12" cy="12" r="3" /></svg>
                  <svg v-else width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3l18 18" /><path d="M10.6 10.6a3 3 0 0 0 4.2 4.2" /><path d="M9.9 5.1A10.4 10.4 0 0 1 12 5c6.5 0 10 7 10 7a17.6 17.6 0 0 1-3.2 4.1" /><path d="M6.6 6.6C3.7 8.6 2 12 2 12s3.5 7 10 7c1.4 0 2.7-.3 3.8-.7" /></svg>
                </button>
                <button class="pw-icon" title="コピー" @click="copyPw(t)">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="11" height="11" rx="2" /><path d="M5 15V5a2 2 0 0 1 2-2h10" /></svg>
                </button>
              </template>
              <span v-else style="color: var(--faint); white-space: nowrap">未保存（PW再設定で保存）</span>
            </div>
            <div v-if="auth.user?.lineConfigured" class="pw-line">
              <span style="color: var(--faint)">LINE:</span>
              <span v-if="t.lineLinked" class="line-badge ok" style="font-size: 10.5px">連携済み</span>
              <template v-else>
                <span>連携コード <b style="letter-spacing: 1px">{{ t.lineLinkCode }}</b></span>
                <button class="pw-icon" title="連携コードをコピー" @click="copyTutorLineCode(t)">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="11" height="11" rx="2" /><path d="M5 15V5a2 2 0 0 1 2-2h10" /></svg>
                </button>
              </template>
            </div>
          </div>
          <div class="tutor-actions">
            <label class="rate-box">
              <span>時給</span>
              <input :value="t.hourlyRate" type="number" min="0" step="100" @change="saveTutorRate(t, $event)" />
              <span>円</span>
            </label>
            <button class="mini-btn" @click="pwEdit.id = pwEdit.id === t.id ? null : t.id; pwEdit.password = ''">PW再設定</button>
            <button class="mini-btn danger" @click="removeTutor(t)">削除</button>
          </div>
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
          <label class="fld"><span>ログインID（メールアドレス形式でなくても可）</span><input v-model="tutorForm.email" type="text" autocomplete="off" /></label>
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

    <!-- LINE 通知 -->
    <div class="card sec">
      <div class="sec-title">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#6b7280" stroke-width="1.8"><path d="M21 11.5a8.5 7.5 0 0 1-8.5 7.5c-.9 0-1.8-.1-2.6-.3L5 20.5l1-3.2A7.3 7.3 0 0 1 4 11.5 8.5 7.5 0 0 1 12.5 4 8.5 7.5 0 0 1 21 11.5z" /></svg>
        LINE通知
      </div>
      <div v-if="!auth.user?.lineConfigured" style="font-size: 12px; color: var(--faint); line-height: 1.7">
        LINE 公式アカウントの設定が未構成のため、現在は利用できません。
      </div>
      <template v-else>
        <div class="opt">
          <div><div class="opt-t">連携状態</div><div class="opt-s">課題の設定・小テストの出題・採点/添削の完了を LINE でお知らせします</div></div>
          <span class="line-badge" :class="{ ok: auth.user?.lineLinked }">{{ auth.user?.lineLinked ? '連携済み' : '未連携' }}</span>
        </div>
        <div class="line-steps">
          1.
          <a v-if="auth.user?.lineAddFriendUrl" :href="auth.user.lineAddFriendUrl" target="_blank" rel="noopener">LINE 公式アカウントを友だち追加</a>
          <template v-else>LINE 公式アカウントを友だち追加</template>
          　2. トークに下の連携コードを送信すると連携されます（「解除」と送ると停止）<br />
          グループLINEに公式アカウントを招待してそのトークにコードを送ると、グループ宛てに通知されます。
          講師アカウントの連携コードは上の「家庭教師アカウント」欄にあり、同じグループで両方のコードを送ると全種類の通知が1つのグループに届きます。
        </div>
        <div class="line-code-row">
          <code class="line-code">{{ auth.user?.lineLinkCode }}</code>
          <button class="mini-btn" @click="copyLineCode">コピー</button>
          <button class="mini-btn" @click="refreshLine">状態を更新</button>
        </div>
      </template>
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
  gap: 8px 12px;
  padding: 9px 11px;
  border: 1px solid #eceef0;
  border-radius: 10px;
  flex-wrap: wrap;
}
.tutor-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-left: auto;
  flex-wrap: wrap;
}
.pw-line {
  display: flex;
  align-items: center;
  gap: 5px;
  font-size: 11px;
  margin-top: 2px;
  min-width: 0;
}
.pw-value {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 11.5px;
  color: var(--ink);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.pw-value.masked {
  letter-spacing: 1px;
  color: var(--mut);
}
.pw-icon {
  flex-shrink: 0;
  border: none;
  background: transparent;
  color: #9aa1ab;
  cursor: pointer;
  padding: 2px;
  display: flex;
  align-items: center;
}
.pw-icon:hover {
  color: var(--ink);
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
.rate-box {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 11.5px;
  color: var(--mut);
  flex-shrink: 0;
  white-space: nowrap;
}
.rate-box input {
  width: 76px;
  padding: 6px 8px;
  border: 1px solid #e3e6ea;
  border-radius: 7px;
  font-size: 12.5px;
  text-align: right;
}
.line-badge {
  font-size: 11px;
  font-weight: 700;
  padding: 3px 10px;
  border-radius: 999px;
  background: #f1f2f4;
  color: var(--mut);
  white-space: nowrap;
}
.line-badge.ok {
  background: #e6f5ec;
  color: #2f7a4f;
}
.line-steps {
  font-size: 12px;
  color: var(--mut);
  line-height: 1.8;
  margin: 8px 0;
}
.line-steps a {
  color: #06c755;
  font-weight: 700;
}
.line-code-row {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}
.line-code {
  font-size: 16px;
  font-weight: 700;
  letter-spacing: 2px;
  background: #f6f7f9;
  border: 1px dashed #d8dce1;
  border-radius: 9px;
  padding: 8px 14px;
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
