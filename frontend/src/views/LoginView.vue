<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const auth = useAuthStore()

const email = ref('')
const password = ref('')
const showPassword = ref(false) // 入力中のパスワードを確認できる表示切替
const loading = ref(false)
const error = ref('')

async function submit() {
  error.value = ''
  loading.value = true
  try {
    await auth.login(email.value, password.value)
    router.push({ name: auth.user?.role === 'tutor' ? 'tutor-home' : 'home' })
  } catch (e: unknown) {
    error.value = 'IDまたはパスワードが正しくありません。'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="login-wrap">
    <div class="card login-card">
      <div class="brand">
        <div class="logo dm">学</div>
        <div>
          <div style="font-weight: 700; font-size: 18px">受験ナビ</div>
          <div style="font-size: 11px; color: var(--faint); letter-spacing: 0.04em">STUDY MANAGER</div>
        </div>
      </div>
      <div style="font-size: 13px; color: var(--mut); margin: 6px 0 20px">アカウントにログインしてください</div>
      <form @submit.prevent="submit" style="display: flex; flex-direction: column; gap: 14px">
        <label class="fld">
          <span>ID</span>
          <input v-model="email" type="text" autocomplete="username" />
        </label>
        <label class="fld">
          <span>パスワード</span>
          <div class="pw-wrap">
            <input v-model="password" :type="showPassword ? 'text' : 'password'" autocomplete="current-password" />
            <button
              type="button"
              class="pw-toggle"
              :title="showPassword ? 'パスワードを隠す' : 'パスワードを表示'"
              :aria-label="showPassword ? 'パスワードを隠す' : 'パスワードを表示'"
              @click="showPassword = !showPassword"
            >
              <svg v-if="!showPassword" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12z" /><circle cx="12" cy="12" r="3" /></svg>
              <svg v-else width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3l18 18" /><path d="M10.6 10.6a3 3 0 0 0 4.2 4.2" /><path d="M9.9 5.1A10.4 10.4 0 0 1 12 5c6.5 0 10 7 10 7a17.6 17.6 0 0 1-3.2 4.1" /><path d="M6.6 6.6C3.7 8.6 2 12 2 12s3.5 7 10 7c1.4 0 2.7-.3 3.8-.7" /></svg>
            </button>
          </div>
        </label>
        <div v-if="error" style="color: #cf5563; font-size: 12.5px">{{ error }}</div>
        <button class="primary-btn" :disabled="loading" type="submit">
          {{ loading ? 'ログイン中…' : 'ログイン' }}
        </button>
      </form>
    </div>
  </div>
</template>

<style scoped>
.login-wrap {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--bg);
  padding: 20px;
}
.login-card {
  width: 100%;
  max-width: 380px;
  padding: 32px 30px;
}
.brand {
  display: flex;
  align-items: center;
  gap: 12px;
}
.logo {
  width: 40px;
  height: 40px;
  border-radius: 11px;
  background: #1c2024;
  color: #fff;
  font-weight: 700;
  font-size: 19px;
  display: flex;
  align-items: center;
  justify-content: center;
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
  padding: 10px 12px;
  border: 1px solid #e3e6ea;
  border-radius: 10px;
  font-size: 14px;
  outline: none;
}
.fld input:focus {
  border-color: var(--primary);
}
.pw-wrap {
  position: relative;
}
.pw-wrap input {
  padding-right: 42px;
}
.pw-toggle {
  position: absolute;
  top: 0;
  right: 0;
  height: 100%;
  width: 40px;
  border: none;
  background: transparent;
  color: #9aa1ab;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 0 10px 10px 0;
}
.pw-toggle:hover {
  color: var(--ink);
}
.primary-btn {
  margin-top: 6px;
  padding: 12px;
  border: none;
  border-radius: 11px;
  background: #1c2024;
  color: #fff;
  font-size: 14px;
  font-weight: 700;
  cursor: pointer;
}
.primary-btn:disabled {
  opacity: 0.6;
}
</style>
