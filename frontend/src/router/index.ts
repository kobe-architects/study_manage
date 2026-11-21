import { createRouter, createWebHistory } from 'vue-router'
import { reloadOnce } from '@/lib/native'

// meta.depth = 2 の画面は meta.parent の下の階層（遷移アニメーション・iOS の左端スワイプで戻る に使う）

const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/login', name: 'login', component: () => import('@/views/LoginView.vue'), meta: { public: true } },
    {
      path: '/',
      component: () => import('@/layouts/AppLayout.vue'),
      children: [
        { path: '', name: 'home', component: () => import('@/views/HomeView.vue') },
        { path: 'data', name: 'data', component: () => import('@/views/DataView.vue') },
        { path: 'tasks', name: 'tasks', component: () => import('@/views/TasksView.vue') },
        { path: 'resource', name: 'resource', component: () => import('@/views/ResourceDataView.vue') },
        { path: 'record', name: 'record', component: () => import('@/views/RecordView.vue') },
        // 英語学習（スクランブル／英単語／鉄緑会 英文法 をタブで切替）・数学学習（合格るシリーズ）
        { path: 'english', name: 'english', component: () => import('@/views/EnglishView.vue') },
        { path: 'math', name: 'math', component: () => import('@/views/MathView.vue') },
        // 旧 URL（英単語クイズ・英文法・語法）は英語学習の該当タブへ
        { path: 'quiz', name: 'quiz', redirect: { name: 'english', query: { tab: 'vocab' } } },
        { path: 'grammar', name: 'grammar', redirect: { name: 'english', query: { tab: 'scramble' } } },
        { path: 'goals', name: 'goals', component: () => import('@/views/GoalsView.vue') },
        { path: 'gantt', name: 'gantt', component: () => import('@/views/GanttView.vue'), meta: { wide: true } },
        { path: 'gantt/:id', name: 'gantt-chart', component: () => import('@/views/GanttChartView.vue'), meta: { wide: true, fullscreen: true, depth: 2, parent: 'gantt', title: '計画表' } },
        { path: 'mistakes', name: 'mistakes', component: () => import('@/views/MistakesView.vue') },
        { path: 'mistakes/new', name: 'mistake-new', component: () => import('@/views/MistakeEditView.vue'), meta: { wide: true, depth: 2, parent: 'mistakes', title: '問題を追加' } },
        { path: 'mistakes/:id', name: 'mistake-edit', component: () => import('@/views/MistakeEditView.vue'), meta: { wide: true, depth: 2, parent: 'mistakes', title: '間違いノートを編集' } },
        { path: 'settings', name: 'settings', component: () => import('@/views/SettingsView.vue') },
        { path: 'vocabulary', name: 'vocabulary', component: () => import('@/views/VocabularyManageView.vue'), meta: { depth: 2, parent: 'english', title: '単語帳管理' } },
        { path: 'review', name: 'review', component: () => import('@/views/ReviewView.vue'), meta: { depth: 2, parent: 'english', title: '間違えた単語の復習' } },
        { path: 'flashcard', name: 'flashcard', component: () => import('@/views/FlashcardView.vue'), meta: { depth: 2, parent: 'english', title: 'フラッシュカード' } },
        { path: 'quizzes', name: 'quizzes', component: () => import('@/views/QuizzesView.vue') },
        // 自分で出題した小テストの採点・添削（講師と同じ画面）
        { path: 'quizzes/:id/grade', name: 'quiz-grade', component: () => import('@/views/TutorQuizGradeView.vue'), meta: { depth: 2, parent: 'quizzes', title: '採点・添削' } },
        // ファイル共有（講師と同じ階層フォルダ）
        { path: 'files', name: 'files', component: () => import('@/views/SharedFilesView.vue') },
        // 質問事項（生徒 ⇄ 講師の質問スレッド）
        { path: 'questions', name: 'questions', component: () => import('@/views/QuestionsView.vue'), meta: { wide: true } },
        { path: 'invoices', name: 'invoices', component: () => import('@/views/InvoicesView.vue') },
      ],
    },
    // 家庭教師用（tutor ロール）
    {
      path: '/tutor',
      component: () => import('@/layouts/TutorLayout.vue'),
      meta: { tutor: true },
      children: [
        { path: '', name: 'tutor-home', component: () => import('@/views/TutorHomeView.vue'), meta: { tutor: true } },
        {
          path: 'subjects',
          name: 'tutor-subjects',
          component: () => import('@/views/TutorSubjectsView.vue'),
          meta: { tutor: true },
        },
        {
          path: 'assignments',
          name: 'tutor-assignments',
          component: () => import('@/views/TutorAssignmentsView.vue'),
          meta: { tutor: true },
        },
        {
          path: 'quizzes',
          name: 'tutor-quizzes',
          component: () => import('@/views/TutorQuizzesView.vue'),
          meta: { tutor: true },
        },
        {
          path: 'quizzes/:id',
          name: 'tutor-quiz-grade',
          component: () => import('@/views/TutorQuizGradeView.vue'),
          meta: { tutor: true, depth: 2, parent: 'tutor-quizzes', title: '採点・添削' },
        },
        {
          path: 'invoices',
          name: 'tutor-invoices',
          component: () => import('@/views/TutorInvoicesView.vue'),
          meta: { tutor: true },
        },
        // チェックシート（スクランブル英文法・語法／英文法ファーストステップ。担当生徒の結果を ○△× で表示）
        {
          path: 'checksheets',
          name: 'tutor-checksheets',
          component: () => import('@/views/TutorCheckSheetView.vue'),
          meta: { tutor: true },
        },
        { path: 'scramble', redirect: { name: 'tutor-checksheets' } },
        // 計画表（講師自身の計画表。生徒側と同じ画面を /tutor の API で使う）
        { path: 'gantt', name: 'tutor-gantt', component: () => import('@/views/GanttView.vue'), meta: { tutor: true, wide: true } },
        {
          path: 'gantt/:id',
          name: 'tutor-gantt-chart',
          component: () => import('@/views/GanttChartView.vue'),
          meta: { tutor: true, wide: true, fullscreen: true, depth: 2, parent: 'tutor-gantt', title: '計画表' },
        },
        // ファイル共有（生徒と同じ階層フォルダ）
        {
          path: 'files',
          name: 'tutor-files',
          component: () => import('@/views/SharedFilesView.vue'),
          meta: { tutor: true },
        },
        // 質問事項（担当生徒とのスレッド。生徒側と同じ画面）
        { path: 'questions', name: 'tutor-questions', component: () => import('@/views/QuestionsView.vue'), meta: { tutor: true, wide: true } },
      ],
    },
  ],
})

router.beforeEach((to) => {
  const token = localStorage.getItem('sm_token')
  const isTutor = localStorage.getItem('sm_role') === 'tutor'

  if (!to.meta.public && !token) {
    // ログイン後に元のリンク（共有ファイルのページ指定リンクなど）へ戻れるように覚えておく
    return { name: 'login', query: to.fullPath !== '/' ? { redirect: to.fullPath } : {} }
  }
  if (to.name === 'login' && token) {
    return { name: isTutor ? 'tutor-home' : 'home' }
  }
  // ロールごとに入れる画面を分離（tutor は /tutor 配下のみ、owner は /tutor 以外）
  if (token && !to.meta.public) {
    // ファイル共有のリンクは生徒・講師どちらでも開ける（同じ内容の画面へ、クエリを持って振り分ける）
    if (isTutor && to.name === 'files') return { name: 'tutor-files', query: to.query }
    if (!isTutor && to.name === 'tutor-files') return { name: 'files', query: to.query }
    if (isTutor && to.name === 'questions') return { name: 'tutor-questions', query: to.query }
    if (!isTutor && to.name === 'tutor-questions') return { name: 'questions', query: to.query }
    if (isTutor && !to.meta.tutor) return { name: 'tutor-home' }
    if (!isTutor && to.meta.tutor) return { name: 'home' }
  }
})

// デプロイ後に古いチャンクが無くなっていて画面を開けないときは、読み直して最新版にする
router.onError((err, to) => {
  if (/dynamically imported module|Importing a module script failed|error loading dynamically imported/i.test(String(err?.message ?? err))) {
    reloadOnce(to.fullPath)
  }
})

export default router
